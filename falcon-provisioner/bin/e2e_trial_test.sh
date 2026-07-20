#!/usr/bin/env bash
# End-to-end trial test: reserve -> bridge -> minimal Blesta order -> poller -> live site.
set -uo pipefail

SUB="${1:-restaurant-demo-010}"
THEME="${2:-restaurant}"
EMAIL="${3:-demo010@test.falcon.codes}"
FIRST="${4:-Demo}"
LAST="${5:-010}"
DB="falc_blesta"
API="https://falcon.codes/api/subdomain"
OPTMAP="/opt/falcon-provisioner/templates/blesta_options.json"
LOG="/var/log/falcon-provisioner.log"

SUB_PRICING=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["subdomain"]??"";' "$OPTMAP")
THEME_PRICING=$(php -r 'echo json_decode(file_get_contents($argv[1]),true)["theme"]??"";' "$OPTMAP")
[ -n "$SUB_PRICING" ] && [ -n "$THEME_PRICING" ] || { echo "FAIL: blesta_options.json missing subdomain/theme ids"; exit 1; }

echo "########## STEP 1: reserve subdomain via homepage API ##########"
RESP=$(curl -s -X POST -d "name=${SUB}" "${API}/reserve")
echo "reserve response: $RESP"
TOKEN=$(echo "$RESP" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["reservation_token"] ?? "";')
echo "TOKEN=$TOKEN"
[ -n "$TOKEN" ] || { echo "FAIL: no token"; exit 1; }

echo "########## STEP 2: bridge handoff ##########"
curl -s -X POST -d "token=${TOKEN}&subdomain=${SUB}&theme=${THEME}&email=${EMAIL}" "${API}/bridge"; echo

echo "########## STEP 3: minimal Blesta client + active trial service ##########"
UID_NEW=$(mysql "$DB" -N -e "INSERT INTO users (username,password,two_factor_mode,date_added) VALUES ('${EMAIL}','$(openssl passwd -6 trial010pass)','none',NOW()); SELECT LAST_INSERT_ID();")
NEXT_CLIENT_IDV=$(mysql "$DB" -N -e "SELECT COALESCE(MAX(id_value),1500)+1 FROM clients;")
CLIENT_ID=$(mysql "$DB" -N -e "INSERT INTO clients (id_format,id_value,user_id,client_group_id,status) VALUES ('{num}',${NEXT_CLIENT_IDV},${UID_NEW},1,'active'); SELECT LAST_INSERT_ID();")
mysql "$DB" -e "INSERT INTO contacts (client_id,contact_type,contact_type_id,first_name,last_name,email,date_added) VALUES (${CLIENT_ID},'primary',NULL,'${FIRST}','${LAST}','${EMAIL}',NOW());"
NEXT_SVC_IDV=$(mysql "$DB" -N -e "SELECT COALESCE(MAX(id_value),0)+1 FROM services;")
SVC_ID=$(mysql "$DB" -N -e "INSERT INTO services (id_format,id_value,pricing_id,client_id,module_row_id,qty,status,date_added,date_renews) VALUES ('{num}',${NEXT_SVC_IDV},4,${CLIENT_ID},0,1,'active',NOW(),DATE_ADD(NOW(),INTERVAL 1 MONTH)); SELECT LAST_INSERT_ID();")
mysql "$DB" -e "INSERT INTO service_options (service_id, option_pricing_id, qty, value, encrypted) VALUES
  (${SVC_ID}, ${SUB_PRICING}, 1, '${SUB}', 0),
  (${SVC_ID}, ${THEME_PRICING}, 1, '${THEME}', 0);"
echo "created user_id=${UID_NEW} client_id=${CLIENT_ID} service_id=${SVC_ID} options=sub:${SUB},theme:${THEME}"

echo "########## STEP 4: poller provision ##########"
FOUND=0
for i in $(seq 1 18); do
  sleep 10
  if grep -q "\"${SUB}\"" /var/lib/falcon-provisioner/sites.json 2>/dev/null; then FOUND=1; echo "poller provisioned after ~$((i*10))s"; break; fi
  echo "  ...waiting ($((i*10))s)"
done
if [ "$FOUND" != "1" ]; then
  echo "running poller manually:"
  php /opt/falcon-provisioner/bin/blesta_poll.php
fi

echo "########## STEP 5: verify live site ##########"
HTTP=$(curl -sI "https://${SUB}.falcon.codes/" | head -1)
echo "HTTP: $HTTP"
echo -n "WP generator: "; curl -s "https://${SUB}.falcon.codes/" | grep -oE "WordPress [0-9.]+" | head -1
echo "=== sites.json record ==="
php -r '$a=json_decode(file_get_contents("/var/lib/falcon-provisioner/sites.json"),true);foreach($a as $r){if(($r["subdomain"]??"")==="'"$SUB"'")echo json_encode($r,JSON_PRETTY_PRINT);}'
echo
echo "=== processed.json (service ${SVC_ID}) ==="
php -r '$a=json_decode(file_get_contents("/var/lib/falcon-provisioner/processed.json"),true);echo json_encode($a["'"$SVC_ID"'"]??"MISSING",JSON_PRETTY_PRINT);'
echo
echo "=== poller log lines for ${SUB} ==="
grep -E "${SUB}|service_id=${SVC_ID}|service ${SVC_ID}" "$LOG" | tail -5 || true
echo "e2e done: service_id=${SVC_ID} site=https://${SUB}.falcon.codes/"
