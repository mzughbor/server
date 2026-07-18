#!/usr/bin/env bash
# Phase 4 end-to-end smoke test.
# Proves: homepage reserve API -> bridge -> (simulated $0 Blesta order) ->
#         cron poller -> provision.sh -> live WordPress site.
set -uo pipefail

SUB="falcondemo1"
THEME="studio"                 # -> twentytwentyfour via meta.json
EMAIL="falcondemo1@example.com"
DB="falc_blesta"
API="https://falcon.codes/api/subdomain"

echo "########## STEP 1: reserve subdomain via homepage API ##########"
RESP=$(curl -s -X POST -d "name=${SUB}" "${API}/reserve")
echo "reserve response: $RESP"
TOKEN=$(echo "$RESP" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["reservation_token"] ?? "";')
echo "TOKEN=$TOKEN"
[ -n "$TOKEN" ] || { echo "FAIL: no token (already reserved/taken?)"; exit 1; }

echo "########## STEP 2: homepage posts {token,subdomain,theme} to bridge ##########"
curl -s -X POST -d "token=${TOKEN}&subdomain=${SUB}&theme=${THEME}&email=${EMAIL}" "${API}/bridge"; echo

echo "########## STEP 3: simulate a COMPLETED \$0 Blesta trial order (client + active service on package 4) ##########"
# This is the one link a real browser checkout would perform; we insert the same
# rows Blesta creates so the poller sees a genuine active package-4 service.
UID_NEW=$(mysql "$DB" -N -e "INSERT INTO users (username,password,two_factor_mode,date_added) VALUES ('${EMAIL}','disabled-trial-user','none',NOW()); SELECT LAST_INSERT_ID();")
NEXT_CLIENT_IDV=$(mysql "$DB" -N -e "SELECT COALESCE(MAX(id_value),1500)+1 FROM clients;")
CLIENT_ID=$(mysql "$DB" -N -e "INSERT INTO clients (id_format,id_value,user_id,client_group_id,status) VALUES ('{num}',${NEXT_CLIENT_IDV},${UID_NEW},1,'active'); SELECT LAST_INSERT_ID();")
mysql "$DB" -e "INSERT INTO contacts (client_id,contact_type,contact_type_id,first_name,last_name,email,date_added) VALUES (${CLIENT_ID},'primary',NULL,'Falcon','Demo','${EMAIL}',NOW());"
NEXT_SVC_IDV=$(mysql "$DB" -N -e "SELECT COALESCE(MAX(id_value),0)+1 FROM services;")
SVC_ID=$(mysql "$DB" -N -e "INSERT INTO services (id_format,id_value,pricing_id,client_id,module_row_id,qty,status,date_added,date_renews) VALUES ('{num}',${NEXT_SVC_IDV},4,${CLIENT_ID},0,1,'active',NOW(),DATE_ADD(NOW(),INTERVAL 1 MONTH)); SELECT LAST_INSERT_ID();")
echo "created Blesta user_id=${UID_NEW} client_id=${CLIENT_ID} (id_value ${NEXT_CLIENT_IDV}) service_id=${SVC_ID} on package 4 (active)"

echo "########## STEP 4: wait for the cron poller to provision (every minute) ##########"
FOUND=0
for i in $(seq 1 12); do
  sleep 10
  if grep -q "\"${SUB}\"" /var/lib/falcon-provisioner/sites.json 2>/dev/null; then FOUND=1; echo "poller provisioned after ~$((i*10))s"; break; fi
  echo "  ...waiting ($((i*10))s)"
done

if [ "$FOUND" != "1" ]; then
  echo "cron did not provision within 120s; running poller manually to show the same code path:"
  php /opt/falcon-provisioner/bin/blesta_poll.php
fi

echo "########## STEP 5: verify the live site ##########"
echo -n "HTTP: "; curl -sI "https://${SUB}.falcon.codes/" | head -1
echo -n "WP generator: "; curl -s "https://${SUB}.falcon.codes/" | grep -oE "WordPress [0-9.]+" | head -1
echo "=== sites.json record ==="; php -r '$a=json_decode(file_get_contents("/var/lib/falcon-provisioner/sites.json"),true);foreach($a as $r){if(($r["subdomain"]??"")==="'"$SUB"'")echo json_encode($r,JSON_PRETTY_PRINT);}'
echo; echo "=== processed.json (service ${SVC_ID}) ==="; php -r '$a=json_decode(file_get_contents("/var/lib/falcon-provisioner/processed.json"),true);echo json_encode($a["'"$SVC_ID"'"]??"MISSING",JSON_PRETTY_PRINT);'
echo; echo "=== Blesta client note added ==="; mysql "$DB" -e "SELECT title,LEFT(description,60) FROM client_notes WHERE client_id=${CLIENT_ID};"
echo "smoke test done: client_id=${CLIENT_ID} service_id=${SVC_ID} site=https://${SUB}.falcon.codes/"
