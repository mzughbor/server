#!/usr/bin/env bash
#
# Clone the golden staging-base WordPress image to a template staging subdomain.
# Faster than CyberPanel's WP wizard — rsync/tarball from /var/lib/falcon-provisioner/staging-base/
# or live wp-staging-base.falcon.codes fallback.
#
# Usage:
#   clone_staging_wp.sh --domain preview-restaurant.falcon.codes [--force]
#
set -euo pipefail

MASTER="falcon.codes"
HOME_BASE="/home/${MASTER}"
SITE_USER="falco6812"
WP="/usr/local/bin/wp"
STATE_DIR="/var/lib/falcon-provisioner"
STAGING_BASE_DIR="${STATE_DIR}/staging-base"
STAGING_CONFIG="/opt/falcon-provisioner/templates/staging_base.json"
BASE_DOMAIN="wp-staging-base.falcon.codes"
SECRETS_DIR="${STATE_DIR}/secrets"
SITES="${STATE_DIR}/sites.json"
LOG="/var/log/falcon-provisioner.log"

DOMAIN=""; FORCE=0

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [clone_staging] $*" | tee -a "$LOG" >&2; }
die() { log "ERROR: $*"; echo "STAGING_CLONE_FAILED: $*"; exit 1; }

usage() {
  cat >&2 <<EOF
Usage: $0 --domain FQDN [--force]
EOF
  exit 2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2;;
    --force)  FORCE=1; shift;;
    -h|--help) usage;;
    *) die "unknown argument: $1";;
  esac
done

[ "$(id -u)" -eq 0 ] || die "must run as root"
[ -n "$DOMAIN" ] || usage

# Load golden base config (env overrides file).
if [ -f "$STAGING_CONFIG" ]; then
  BASE_DOMAIN=$(php -r '
    $c=json_decode(@file_get_contents($argv[1]),true) ?: [];
    echo $c["base_domain"] ?? "";
  ' "$STAGING_CONFIG")
  CFG_DIR=$(php -r '
    $c=json_decode(@file_get_contents($argv[1]),true) ?: [];
    echo $c["storage_dir"] ?? "";
  ' "$STAGING_CONFIG")
  [ -n "$CFG_DIR" ] && STAGING_BASE_DIR="$CFG_DIR"
fi
[ -n "${FALCON_STAGING_BASE:-}" ] && BASE_DOMAIN="$FALCON_STAGING_BASE"
BASE_DOMAIN="$(echo "$BASE_DOMAIN" | tr '[:upper:]' '[:lower:]')"
ADMIN_EMAIL="${ADMIN_EMAIL:-mzughbo@gmail.com}"
if [ -f "$STAGING_CONFIG" ]; then
  ADMIN_EMAIL=$(php -r '
    $c=json_decode(@file_get_contents($argv[1]),true) ?: [];
    echo $c["admin_email"] ?? "mzughbo@gmail.com";
  ' "$STAGING_CONFIG")
fi

if ! echo "$DOMAIN" | grep -q '\.'; then
  DOMAIN="${DOMAIN}.${MASTER}"
fi
DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]')"

case "$DOMAIN" in
  "$BASE_DOMAIN"|"${MASTER}"|*."${MASTER}")
    ;;
  *)
    die "domain must be *.${MASTER}: ${DOMAIN}"
    ;;
esac

SUB="${DOMAIN%.${MASTER}}"
[ -n "$SUB" ] || die "invalid subdomain in ${DOMAIN}"
FQDN="$DOMAIN"
DOCROOT="${HOME_BASE}/${FQDN}"

log "=== clone staging start: domain=${FQDN} sub=${SUB} force=${FORCE} ==="

if [ -d "$DOCROOT" ] && [ -f "${DOCROOT}/wp-config.php" ]; then
  if sudo -u "$SITE_USER" -H "$WP" core is-installed --path="$DOCROOT" >/dev/null 2>&1; then
    if [ "$FORCE" -ne 1 ]; then
      log "WordPress already installed at ${DOCROOT}; skipping clone"
      SECRET_FILE="${SECRETS_DIR}/staging-${SUB}.env"
      WP_ADMIN_URL="https://${FQDN}/wp-admin/"
      WP_ADMIN_USER="falconadmin"
      WP_ADMIN_PASS=""
      if [ -f "$SECRET_FILE" ]; then
        WP_ADMIN_PASS=$(grep '^WP_ADMIN_PASS=' "$SECRET_FILE" 2>/dev/null | cut -d= -f2- || true)
      fi
      cat <<EOF
STAGING_CLONE_OK
status=already_exists
site_url=https://${FQDN}/
wp_admin_url=${WP_ADMIN_URL}
wp_admin_user=${WP_ADMIN_USER}
wp_admin_pass=${WP_ADMIN_PASS}
secrets=${SECRET_FILE}
EOF
      exit 0
    fi
    log "WARN: --force set; rebuilding existing staging site ${FQDN}"
  fi
fi

# Resolve golden image source.
BASE_URL="https://${BASE_DOMAIN}"
BASE_DOCROOT="${HOME_BASE}/${BASE_DOMAIN}"
USE_LIVE_BASE=0
if [ -f "${STAGING_BASE_DIR}/database.sql.gz" ] && [ -f "${STAGING_BASE_DIR}/wp-content.tar.gz" ]; then
  log "using golden snapshot at ${STAGING_BASE_DIR}"
  if [ -f "${STAGING_BASE_DIR}/meta.json" ]; then
    BASE_URL=$(php -r '
      $m=json_decode(@file_get_contents($argv[1]),true) ?: [];
      echo $m["site_url"] ?? $m["base_url"] ?? "";
    ' "${STAGING_BASE_DIR}/meta.json")
    [ -n "$BASE_URL" ] || BASE_URL="https://${BASE_DOMAIN}"
  fi
elif [ -f "${BASE_DOCROOT}/wp-config.php" ] && sudo -u "$SITE_USER" -H "$WP" core is-installed --path="$BASE_DOCROOT" >/dev/null 2>&1; then
  USE_LIVE_BASE=1
  BASE_URL="$(sudo -u "$SITE_USER" -H "$WP" option get siteurl --path="$BASE_DOCROOT" 2>/dev/null || echo "https://${BASE_DOMAIN}")"
  BASE_URL="${BASE_URL%/}"
  log "using live golden site ${BASE_DOMAIN} (run setup_staging_base.sh to cache tarball)"
else
  die "golden staging base missing — run: bash /opt/falcon-provisioner/bin/setup_staging_base.sh"
fi

# Child domain + docroot.
if [ ! -d "$DOCROOT" ]; then
  log "creating child domain ${FQDN} via cyberpanel createChild"
  CC_OUT=$(cyberpanel createChild --masterDomain "$MASTER" --childDomain "$FQDN" \
      --owner admin --php 8.3 --ssl 1 --dkim 0 --openBasedir 0 --path "$FQDN" 2>&1)
  log "createChild output: ${CC_OUT}"
  echo "$CC_OUT" | grep -q '"success": 1' || die "createChild failed for ${FQDN}: ${CC_OUT}"
  for i in $(seq 1 15); do
    [ -d "$DOCROOT" ] && break
    sleep 1
  done
  [ -d "$DOCROOT" ] || die "docroot not created: ${DOCROOT}"
else
  log "docroot exists: ${DOCROOT}"
fi
rm -f "${DOCROOT}/index.html" 2>/dev/null || true

# Database for target staging site.
DBBASE="wp_$(echo "$SUB" | tr '-' '_')"
DBNAME="$(echo "$DBBASE" | cut -c1-64)"
DBUSER="$(echo "$DBBASE" | cut -c1-32)"
DBPASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"

log "creating database ${DBNAME} and user ${DBUSER}"
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DBNAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
  || die "failed to create database ${DBNAME}"
mysql -e "CREATE USER IF NOT EXISTS '${DBUSER}'@'localhost' IDENTIFIED BY '${DBPASS}';" \
  || die "failed to create db user ${DBUSER}"
mysql -e "ALTER USER '${DBUSER}'@'localhost' IDENTIFIED BY '${DBPASS}';" 2>/dev/null
mysql -e "GRANT ALL PRIVILEGES ON \`${DBNAME}\`.* TO '${DBUSER}'@'localhost'; FLUSH PRIVILEGES;" \
  || die "failed to grant privileges on ${DBNAME}"

WP_ADMIN_USER="falconadmin"
WP_ADMIN_PASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-18)"
TARGET_URL="https://${FQDN}"

log "installing WordPress shell at ${DOCROOT}"
sudo -u "$SITE_USER" -H "$WP" core download --path="$DOCROOT" --force >>"$LOG" 2>&1 \
  || die "wp core download failed"
sudo -u "$SITE_USER" -H "$WP" config create --path="$DOCROOT" \
  --dbname="$DBNAME" --dbuser="$DBUSER" --dbpass="$DBPASS" --dbhost="localhost" --force >>"$LOG" 2>&1 \
  || die "wp config create failed"

if [ "$USE_LIVE_BASE" -eq 1 ]; then
  log "exporting database from live golden site ${BASE_DOMAIN}"
  TMPSQL="$(mktemp /tmp/falcon-staging-clone.XXXXXX.sql)"
  trap 'rm -f "$TMPSQL"' EXIT
  sudo -u "$SITE_USER" -H "$WP" db export "$TMPSQL" --path="$BASE_DOCROOT" >>"$LOG" 2>&1 \
    || die "database export from golden site failed"
  mysql "$DBNAME" < "$TMPSQL" || die "database import failed"
  rm -f "$TMPSQL"
  trap - EXIT

  log "copying wp-content from live golden site"
  rm -rf "${DOCROOT}/wp-content"
  cp -a "${BASE_DOCROOT}/wp-content" "${DOCROOT}/wp-content"
  chown -R "${SITE_USER}:${SITE_USER}" "${DOCROOT}/wp-content"
else
  log "importing golden database tarball"
  gunzip -c "${STAGING_BASE_DIR}/database.sql.gz" | mysql "$DBNAME" \
    || die "golden database import failed"

  log "extracting golden wp-content tarball"
  tar -xzf "${STAGING_BASE_DIR}/wp-content.tar.gz" -C "$DOCROOT" \
    || die "golden wp-content extract failed"
  chown -R "${SITE_USER}:${SITE_USER}" "${DOCROOT}/wp-content" 2>/dev/null || true
fi

HTTP_BASE="$(echo "$BASE_URL" | sed 's|^https://|http://|')"
log "search-replace URLs ${BASE_URL} -> ${TARGET_URL}"
for OLD in "$BASE_URL" "$HTTP_BASE" "https://${BASE_DOMAIN}" "http://${BASE_DOMAIN}"; do
  OLD="${OLD%/}"
  [ -n "$OLD" ] || continue
  sudo -u "$SITE_USER" -H "$WP" search-replace "$OLD" "$TARGET_URL" \
    --all-tables --precise --recurse-objects --path="$DOCROOT" >>"$LOG" 2>&1 \
    || log "WARN: search-replace ${OLD} -> ${TARGET_URL} reported errors (continuing)"
done

if sudo -u "$SITE_USER" -H "$WP" user get "$WP_ADMIN_USER" --path="$DOCROOT" --field=ID >/dev/null 2>&1; then
  sudo -u "$SITE_USER" -H "$WP" user update "$WP_ADMIN_USER" \
    --user_pass="$WP_ADMIN_PASS" --user_email="${ADMIN_EMAIL:-mzughbo@gmail.com}" --path="$DOCROOT" >>"$LOG" 2>&1 \
    || die "wp user update failed"
else
  sudo -u "$SITE_USER" -H "$WP" user create "$WP_ADMIN_USER" "${ADMIN_EMAIL:-mzughbo@gmail.com}" \
    --role=administrator --user_pass="$WP_ADMIN_PASS" --path="$DOCROOT" >>"$LOG" 2>&1 \
    || die "wp user create failed"
fi

sudo -u "$SITE_USER" -H "$WP" option update home "$TARGET_URL" --path="$DOCROOT" >>"$LOG" 2>&1 || true
sudo -u "$SITE_USER" -H "$WP" option update siteurl "$TARGET_URL" --path="$DOCROOT" >>"$LOG" 2>&1 || true

if [ ! -d "/etc/letsencrypt/live/${FQDN}" ]; then
  log "issuing SSL for ${FQDN}"
  cyberpanel issueSSL --domainName "$FQDN" >>"$LOG" 2>&1 || log "WARN: issueSSL failed for ${FQDN}"
fi

mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR"
SECRET_FILE="${SECRETS_DIR}/staging-${SUB}.env"
cat > "$SECRET_FILE" <<EOF
FQDN=${FQDN}
WP_ADMIN_USER=${WP_ADMIN_USER}
WP_ADMIN_PASS=${WP_ADMIN_PASS}
WP_ADMIN_URL=https://${FQDN}/wp-admin/
DB_NAME=${DBNAME}
DB_USER=${DBUSER}
DB_PASS=${DBPASS}
CLONED_FROM=${BASE_DOMAIN}
CREATED_AT=$(ts)
EOF
chmod 600 "$SECRET_FILE"

# Register staging site in sites.json (for ops visibility; not a customer trial).
NOW_ISO=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
if [ -f "$SITES" ]; then
  php -r '
    $f=$argv[1];
    $rec=[
      "subdomain"=>$argv[2], "fqdn"=>$argv[3], "db"=>$argv[4], "db_user"=>$argv[5],
      "wp_admin_user"=>$argv[6], "theme_id"=>"staging", "wp_theme"=>"",
      "template_slug"=>"", "provision_mode"=>"staging_clone",
      "client_id"=>"staging", "email"=>$argv[7], "created_at"=>$argv[8],
      "ssl"=>"issued", "ftp_user"=>"", "ftp_host"=>$argv[3],
      "ftp_status"=>"n/a", "status"=>"staging", "cloned_from"=>$argv[9]
    ];
    $fh=fopen($f,"c+"); flock($fh,LOCK_EX);
    $raw=stream_get_contents($fh); $a=json_decode($raw,true); if(!is_array($a)) $a=[];
    $a=array_values(array_filter($a, fn($r)=>($r["subdomain"]??"")!==$argv[2]));
    $a[]=$rec;
    ftruncate($fh,0); rewind($fh); fwrite($fh, json_encode($a, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    fflush($fh); flock($fh,LOCK_UN); fclose($fh);
  ' "$SITES" "$SUB" "$FQDN" "$DBNAME" "$DBUSER" "$WP_ADMIN_USER" "$ADMIN_EMAIL" "$NOW_ISO" "$BASE_DOMAIN" 2>/dev/null \
    && log "recorded staging site in ${SITES}" || log "WARN: failed to update sites.json"
  chmod 640 "$SITES" 2>/dev/null; chgrp "$SITE_USER" "$SITES" 2>/dev/null
fi

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://${FQDN}/" --max-time 20 2>/dev/null || echo "000")
log "=== clone staging success: ${TARGET_URL} http=${HTTP_CODE} secrets=${SECRET_FILE} ==="

cat <<EOF
STAGING_CLONE_OK
status=created
site_url=https://${FQDN}/
wp_admin_url=https://${FQDN}/wp-admin/
wp_admin_user=${WP_ADMIN_USER}
wp_admin_pass=${WP_ADMIN_PASS}
db_name=${DBNAME}
http_code=${HTTP_CODE}
secrets=${SECRET_FILE}
EOF
exit 0
