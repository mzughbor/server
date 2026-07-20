#!/usr/bin/env bash
#
# One-time setup: golden clean WordPress at wp-staging-base.falcon.codes
# and tarball snapshot at /var/lib/falcon-provisioner/staging-base/
#
# Usage (on VPS as root):
#   bash /opt/falcon-provisioner/bin/setup_staging_base.sh
#
set -euo pipefail

MASTER="falcon.codes"
BASE_SUB="wp-staging-base"
BASE_DOMAIN="${BASE_SUB}.${MASTER}"
HOME_BASE="/home/${MASTER}"
SITE_USER="falco6812"
WP="/usr/local/bin/wp"
STATE_DIR="/var/lib/falcon-provisioner"
STAGING_BASE_DIR="${STATE_DIR}/staging-base"
PROVISION="/opt/falcon-provisioner/bin/provision.sh"
LOG="/var/log/falcon-provisioner.log"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [setup_staging_base] $*" | tee -a "$LOG" >&2; }
die() { log "ERROR: $*"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "must run as root"
[ -x "$PROVISION" ] || die "provision.sh not found at ${PROVISION}"

DOCROOT="${HOME_BASE}/${BASE_DOMAIN}"

log "=== setup staging base: ${BASE_DOMAIN} ==="

if [ ! -f "${DOCROOT}/wp-config.php" ] || ! sudo -u "$SITE_USER" -H "$WP" core is-installed --path="$DOCROOT" >/dev/null 2>&1; then
  log "provisioning clean WordPress at ${BASE_DOMAIN}"
  "$PROVISION" \
    --subdomain "$BASE_SUB" \
    --theme default \
    --email "${ADMIN_EMAIL:-mzughbo@gmail.com}" \
    --client_id staging-base \
    --force >>"$LOG" 2>&1 || die "provision.sh failed for ${BASE_DOMAIN}"
else
  log "golden site already installed at ${DOCROOT}"
fi

mkdir -p "$STAGING_BASE_DIR"

log "exporting database snapshot"
TMPSQL="$(mktemp /tmp/falcon-staging-base.XXXXXX.sql)"
trap 'rm -f "$TMPSQL"' EXIT
sudo -u "$SITE_USER" -H "$WP" db export "$TMPSQL" --path="$DOCROOT" >>"$LOG" 2>&1 \
  || die "database export failed"
gzip -cf "$TMPSQL" > "${STAGING_BASE_DIR}/database.sql.gz"
rm -f "$TMPSQL"
trap - EXIT

log "archiving wp-content"
tar --warning=no-file-changed -czf "${STAGING_BASE_DIR}/wp-content.tar.gz" -C "$DOCROOT" \
  --exclude='wp-content/cache' \
  --exclude='wp-content/upgrade' \
  --exclude='wp-content/debug.log' \
  wp-content \
  || die "wp-content archive failed"

SITE_URL="$(sudo -u "$SITE_USER" -H "$WP" option get siteurl --path="$DOCROOT" 2>/dev/null || echo "https://${BASE_DOMAIN}")"
SITE_URL="${SITE_URL%/}"

php -r '
  $out = [
    "base_domain" => $argv[1],
    "site_url" => $argv[2],
    "created_at" => gmdate("c"),
    "files" => [
      "database" => "database.sql.gz",
      "wp_content" => "wp-content.tar.gz",
    ],
  ];
  file_put_contents($argv[3], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$BASE_DOMAIN" "$SITE_URL" "${STAGING_BASE_DIR}/meta.json"

chown -R root:"$SITE_USER" "$STAGING_BASE_DIR"
chmod 750 "$STAGING_BASE_DIR"
chmod 640 "${STAGING_BASE_DIR}/database.sql.gz" "${STAGING_BASE_DIR}/wp-content.tar.gz" "${STAGING_BASE_DIR}/meta.json"

DB_SIZE=$(stat -c%s "${STAGING_BASE_DIR}/database.sql.gz")
TAR_SIZE=$(stat -c%s "${STAGING_BASE_DIR}/wp-content.tar.gz")
log "staging base ready: db_bytes=${DB_SIZE} tar_bytes=${TAR_SIZE} dir=${STAGING_BASE_DIR}"

cat <<EOF
STAGING_BASE_OK
base_domain=${BASE_DOMAIN}
storage_dir=${STAGING_BASE_DIR}
site_url=${SITE_URL}
database_bytes=${DB_SIZE}
wp_content_bytes=${TAR_SIZE}
EOF
