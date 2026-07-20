#!/usr/bin/env bash
# Admin test provision: minimal Blesta trial service + poller (staff-only path).
# Usage: admin_test_provision.sh SUBDOMAIN THEME_SLUG
# Emits one line: TEST_OK {json} or TEST_FAIL {json}
set -uo pipefail

SUB="$(echo "${1:-}" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')"
THEME="$(echo "${2:-}" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')"
DB="falc_blesta"
OPTMAP="/opt/falcon-provisioner/templates/blesta_options.json"
LOG="/var/log/falcon-provisioner.log"
PROCESSED="/var/lib/falcon-provisioner/processed.json"
SITES="/var/lib/falcon-provisioner/sites.json"
POLLER="/opt/falcon-provisioner/bin/blesta_poll.php"
MASTER="falcon.codes"
TEMPLATE_DIR="/var/lib/falcon-provisioner/templates/${THEME}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [admin_test] $*" | tee -a "$LOG" >&2; }
fail() { local msg="$1"; log "FAIL: $msg"; echo "TEST_FAIL $(php -r 'echo json_encode(["message"=>$argv[1]], JSON_UNESCAPED_SLASHES);' "$msg")"; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "must run as root"
[ -n "$SUB" ] || fail "missing subdomain"
[ -n "$THEME" ] || fail "missing theme slug"
[[ "$SUB" =~ ^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$ ]] || fail "invalid subdomain format"

SUB_PRICING=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["subdomain"]??"";' "$OPTMAP")
THEME_PRICING=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["theme"]??"";' "$OPTMAP")
[ -n "$SUB_PRICING" ] && [ -n "$THEME_PRICING" ] || fail "blesta_options.json missing subdomain/theme ids"

if [ ! -f "${TEMPLATE_DIR}/database.sql.gz" ] || [ ! -f "${TEMPLATE_DIR}/wp-content.tar.gz" ]; then
  fail "theme template not captured: ${THEME}"
fi

EMAIL="admin-test-${SUB}@${MASTER}"
FIRST="Admin"
LAST="Test"

log "start sub=${SUB} theme=${THEME} email=${EMAIL}"

# Reject if subdomain already provisioned in sites.json
if php -r '
$a=json_decode(@file_get_contents($argv[1]),true)?:[];
foreach($a as $r){if(($r["subdomain"]??"")===$argv[2])exit(1);}
' "$SITES" "$SUB" 2>/dev/null; then
  :
else
  fail "subdomain already provisioned: ${SUB}"
fi

# Minimal Blesta client + active trial service (package pricing id 4)
UID_NEW=$(mysql "$DB" -N -e "INSERT INTO users (username,password,two_factor_mode,date_added) VALUES ('${EMAIL}','$(openssl passwd -6 adminTestPass!)','none',NOW()); SELECT LAST_INSERT_ID();")
NEXT_CLIENT_IDV=$(mysql "$DB" -N -e "SELECT COALESCE(MAX(id_value),1500)+1 FROM clients;")
CLIENT_ID=$(mysql "$DB" -N -e "INSERT INTO clients (id_format,id_value,user_id,client_group_id,status) VALUES ('{num}',${NEXT_CLIENT_IDV},${UID_NEW},1,'active'); SELECT LAST_INSERT_ID();")
mysql "$DB" -e "INSERT INTO contacts (client_id,contact_type,contact_type_id,first_name,last_name,email,date_added) VALUES (${CLIENT_ID},'primary',NULL,'${FIRST}','${LAST}','${EMAIL}',NOW());"
NEXT_SVC_IDV=$(mysql "$DB" -N -e "SELECT COALESCE(MAX(id_value),0)+1 FROM services;")
SVC_ID=$(mysql "$DB" -N -e "INSERT INTO services (id_format,id_value,pricing_id,client_id,module_row_id,qty,status,date_added,date_renews) VALUES ('{num}',${NEXT_SVC_IDV},4,${CLIENT_ID},0,1,'active',NOW(),DATE_ADD(NOW(),INTERVAL 1 MONTH)); SELECT LAST_INSERT_ID();")
mysql "$DB" -e "INSERT INTO service_options (service_id, option_pricing_id, qty, value, encrypted) VALUES
  (${SVC_ID}, ${SUB_PRICING}, 1, '${SUB}', 0),
  (${SVC_ID}, ${THEME_PRICING}, 1, '${THEME}', 0);"

log "created client_id=${CLIENT_ID} service_id=${SVC_ID} options=sub:${SUB},theme:${THEME}"

# Run poller (immediate + retries while waiting)
php "$POLLER" 2>&1 | tail -3 | while read -r line; do log "poller: $line"; done

STATUS="pending"
SITE_URL=""
WP_ADMIN_URL=""
WP_USER=""

for i in $(seq 1 24); do
  sleep 5
  php "$POLLER" >/dev/null 2>&1 || true

  REC=$(php -r '
$p=json_decode(@file_get_contents($argv[1]),true)?:[];
$r=$p[$argv[2]]??null;
if(!$r){echo "";}else{echo json_encode($r,JSON_UNESCAPED_SLASHES);}
' "$PROCESSED" "$SVC_ID")

  if [ -n "$REC" ]; then
    STATUS=$(echo "$REC" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["status"]??"";')
    SITE_URL=$(echo "$REC" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["site_url"]??"";')
    if [ "$STATUS" = "ok" ]; then
      log "provisioned after ~$((i*5))s service_id=${SVC_ID}"
      break
    fi
    if [ "$STATUS" = "failed" ] || [ "$STATUS" = "skipped" ]; then
      fail "provision ${STATUS} for service_id=${SVC_ID}"
    fi
  fi
done

if [ "$STATUS" != "ok" ]; then
  fail "timeout waiting for provision (service_id=${SVC_ID})"
fi

# Read client-visible URLs from service options (no passwords in JSON output)
SITE_PID=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["site_url"]??0;' "$OPTMAP")
ADMIN_PID=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["wp_admin_url"]??0;' "$OPTMAP")
USER_PID=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["wp_user"]??0;' "$OPTMAP")
SITE_URL=$(mysql "$DB" -N -e "SELECT value FROM service_options WHERE service_id=${SVC_ID} AND option_pricing_id=${SITE_PID} LIMIT 1;" 2>/dev/null || true)
WP_ADMIN_URL=$(mysql "$DB" -N -e "SELECT value FROM service_options WHERE service_id=${SVC_ID} AND option_pricing_id=${ADMIN_PID} LIMIT 1;" 2>/dev/null || true)
WP_USER=$(mysql "$DB" -N -e "SELECT value FROM service_options WHERE service_id=${SVC_ID} AND option_pricing_id=${USER_PID} LIMIT 1;" 2>/dev/null || true)

[ -n "$SITE_URL" ] || SITE_URL="https://${SUB}.${MASTER}/"
[ -n "$WP_ADMIN_URL" ] || WP_ADMIN_URL="https://${SUB}.${MASTER}/wp-admin/"

RESULT=$(php -r 'echo json_encode([
  "service_id" => (int)$argv[1],
  "client_id" => (int)$argv[2],
  "status" => $argv[3],
  "subdomain" => $argv[4],
  "theme" => $argv[5],
  "site_url" => $argv[6],
  "wp_admin_url" => $argv[7],
  "wp_user" => $argv[8],
  "blesta_service_url" => "clients/view/" . (int)$argv[2] . "/services/" . (int)$argv[1] . "/",
], JSON_UNESCAPED_SLASHES);' "$SVC_ID" "$CLIENT_ID" "$STATUS" "$SUB" "$THEME" "$SITE_URL" "$WP_ADMIN_URL" "$WP_USER")

log "done service_id=${SVC_ID} site=${SITE_URL}"
echo "TEST_OK ${RESULT}"
