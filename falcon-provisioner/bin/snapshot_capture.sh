#!/usr/bin/env bash
#
# Falcon of Codes — capture a WordPress staging site as a restorable template.
# Exports DB (with site URL → {{SITE_URL}}) and wp-content tarball.
#
# Usage:
#   snapshot_capture.sh --domain preview-restaurant.falcon.codes \
#     --slug restaurant --name "Restaurant" [--description "..."] [--thumbnail-url URL]
#
set -euo pipefail

MASTER="falcon.codes"
HOME_BASE="/home/${MASTER}"
SITE_USER="falco6812"
WP="/usr/local/bin/wp"
TEMPLATES_DIR="/var/lib/falcon-provisioner/templates"
LOG="/var/log/falcon-provisioner.log"
CAPTURE_LOG="/var/log/falcon-provisioner-capture.log"
URL_TOKEN="{{SITE_URL}}"

DOMAIN=""; SLUG=""; NAME=""; DESCRIPTION=""; THUMB=""

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [snapshot_capture] $*" | tee -a "$LOG" "$CAPTURE_LOG" >&2; }
die() { log "ERROR: $*"; echo "CAPTURE_FAILED: $*"; exit 1; }

# GNU tar exits 1 when files change during read; archive is still usable.
tar_archive() {
  tar --warning=no-file-changed -czf "$1" -C "$2" "${@:3}"
  local rc=$?
  if [ "$rc" -gt 1 ]; then
    return "$rc"
  fi
  return 0
}

usage() {
  cat >&2 <<EOF
Usage: $0 --domain FQDN --slug SLUG --name NAME [--description TEXT] [--thumbnail-url URL]
EOF
  exit 2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2;;
    --slug) SLUG="${2:-}"; shift 2;;
    --name) NAME="${2:-}"; shift 2;;
    --description) DESCRIPTION="${2:-}"; shift 2;;
    --thumbnail-url) THUMB="${2:-}"; shift 2;;
    -h|--help) usage;;
    *) die "unknown argument: $1";;
  esac
done

[ "$(id -u)" -eq 0 ] || die "must run as root"
[ -n "$DOMAIN" ] && [ -n "$SLUG" ] && [ -n "$NAME" ] || usage

# Normalize domain (allow bare slug → FQDN).
if ! echo "$DOMAIN" | grep -q '\.'; then
  DOMAIN="${DOMAIN}.${MASTER}"
fi
DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]')"
SLUG="$(echo "$SLUG" | tr '[:upper:]' '[:lower:]')"

if ! echo "$SLUG" | grep -Eq '^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$'; then
  die "invalid slug: ${SLUG}"
fi

DOCROOT="${HOME_BASE}/${DOMAIN}"
[ -f "${DOCROOT}/wp-config.php" ] || die "WordPress not found at ${DOCROOT}"
sudo -u "$SITE_USER" -H "$WP" core is-installed --path="$DOCROOT" >/dev/null 2>&1 \
  || die "WordPress is not installed at ${DOCROOT}"

OUT="${TEMPLATES_DIR}/${SLUG}"
mkdir -p "$OUT"

SITE_URL="$(sudo -u "$SITE_USER" -H "$WP" option get siteurl --path="$DOCROOT" 2>/dev/null || true)"
[ -n "$SITE_URL" ] || SITE_URL="https://${DOMAIN}"
SITE_URL="${SITE_URL%/}"
HTTP_URL="$(echo "$SITE_URL" | sed 's|^https://|http://|')"

log "capture start slug=${SLUG} domain=${DOMAIN} docroot=${DOCROOT}"

# DB export (read-only). URL tokenization is applied to the dump file only — live staging DB is untouched.
# On restore (Phase 3): import then `wp search-replace '{{SITE_URL}}' 'https://target.example' --all-tables`.
TMPSQL="$(sudo -u "$SITE_USER" mktemp "/tmp/falcon-snapshot.XXXXXX.sql")"
trap 'sudo -u "$SITE_USER" rm -f "$TMPSQL"' EXIT

sudo -u "$SITE_USER" -H "$WP" db export "$TMPSQL" --path="$DOCROOT" >>"$LOG" 2>&1 \
  || die "database export failed"

for OLD in "$SITE_URL" "$HTTP_URL" "https://${DOMAIN}" "http://${DOMAIN}"; do
  OLD="${OLD%/}"
  [ -n "$OLD" ] || continue
  esc_old="$(printf '%s' "$OLD" | sed 's/[&|/\\]/\\&/g')"
  sed -i "s|${esc_old}|${URL_TOKEN}|g" "$TMPSQL"
done

gzip -cf "$TMPSQL" > "${OUT}/database.sql.gz"
sudo -u "$SITE_USER" rm -f "$TMPSQL"
trap - EXIT

# wp-content archive (skip cache/temp).
tar_archive "${OUT}/wp-content.tar.gz" "$DOCROOT" \
  --exclude='wp-content/cache' \
  --exclude='wp-content/upgrade' \
  --exclude='wp-content/debug.log' \
  wp-content \
  || die "wp-content archive failed"

CAPTURED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

# meta.json — catalog + restore metadata (restore uses wp search-replace {{SITE_URL}} → target).
php -r '
  $out = [
    "slug" => $argv[1],
    "name" => $argv[2],
    "description" => $argv[3],
    "thumbnail_url" => $argv[4] !== "" ? $argv[4] : null,
    "staging_domain" => $argv[5],
    "site_url_token" => $argv[6],
    "active" => false,
    "sort_order" => (int)$argv[7],
    "captured_at" => $argv[8],
    "files" => [
      "database" => "database.sql.gz",
      "wp_content" => "wp-content.tar.gz",
    ],
  ];
  file_put_contents($argv[9], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$SLUG" "$NAME" "$DESCRIPTION" "$THUMB" "$DOMAIN" "$URL_TOKEN" "0" "$CAPTURED_AT" "${OUT}/meta.json"

chown -R root:"$SITE_USER" "$OUT"
chmod 750 "$OUT"
chmod 640 "${OUT}/database.sql.gz" "${OUT}/wp-content.tar.gz" "${OUT}/meta.json"

DB_SIZE=$(stat -c%s "${OUT}/database.sql.gz")
TAR_SIZE=$(stat -c%s "${OUT}/wp-content.tar.gz")
log "capture success slug=${SLUG} db_bytes=${DB_SIZE} tar_bytes=${TAR_SIZE}"

cat <<EOF
CAPTURE_OK
slug=${SLUG}
storage_dir=${OUT}
staging_domain=${DOMAIN}
captured_at=${CAPTURED_AT}
database_bytes=${DB_SIZE}
wp_content_bytes=${TAR_SIZE}
EOF
exit 0
