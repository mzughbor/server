#!/usr/bin/env bash
# Phase-4b acceptance test: new subdomain + theme + NEW email -> simulate the
# completed $0 checkout (insert the exact rows a finished order creates, incl.
# the Blesta custom order fields subdomain/theme) -> let the poller auto-provision
# (zero staff clicks) -> verify site 200/SSL, client-area creds via a REAL client
# login, and a REAL FTP/FTPS login with the generated credential.
set -uo pipefail
DB=falc_blesta
KEY=fa5775f348182f288fbe1877c0e4ecbc2c67569e46ad56eb4c3ce2cdf4608b94
MAP=/opt/falcon-provisioner/templates/blesta_options.json
BASE=https://falcon.codes
PORTAL="$BASE/portal"
SUB="${1:-proof$(date +%H%M%S)}"
THEME_ID="${2:-cafe}"
EMAIL="proof.$(date +%s)@falcon-proof.test"
CLIENTPW='FalconProof!2026'
q(){ mysql "$DB" -N -B -e "$1"; }
qx(){ mysql "$DB" -e "$1"; }
mapid(){ php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m[$argv[2]]??"";' "$MAP" "$1"; }
esc(){ printf "%s" "$1" | sed "s/'/''/g"; }

echo "########## ACCEPTANCE TEST: sub=$SUB theme=$THEME_ID email=$EMAIL ##########"

echo "=== 1. reserve subdomain via public API (as homepage does) ==="
RES=$(curl -s -X POST "$BASE/api/subdomain/reserve" --data-urlencode "name=$SUB")
echo "reserve: $RES"
TOKEN=$(printf "%s" "$RES" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["reservation_token"]??($j["token"]??"");')
echo "token=$TOKEN"
[ -n "$TOKEN" ] || { echo "FAIL: no reservation token (subdomain taken?)"; exit 1; }

echo "=== 2. bridge the choice (fallback carrier) ==="
curl -s -X POST "$BASE/api/subdomain/bridge" \
  --data-urlencode "token=$TOKEN" --data-urlencode "subdomain=$SUB" \
  --data-urlencode "theme=$THEME_ID" --data-urlencode "email=$EMAIL" ; echo

echo "=== 3. simulate COMPLETED \$0 checkout: user+client+contact+service (auto-active) ==="
echo "    + the Blesta custom order fields subdomain/theme (PRIMARY source)"
HASH=$(php -r 'echo password_hash(hash_hmac("sha256",$argv[1],$argv[2]),PASSWORD_BCRYPT,["cost"=>12]);' "$CLIENTPW" "$KEY")
CIV=$(q "SELECT COALESCE(MAX(CAST(id_value AS UNSIGNED)),1500)+1 FROM clients;")
SIV=$(q "SELECT COALESCE(MAX(CAST(id_value AS UNSIGNED)),0)+1 FROM services;")
SPID=$(mapid subdomain); TPID=$(mapid theme)
# All inserts + id capture in ONE connection (LAST_INSERT_ID is per-connection).
IDS=$(mysql "$DB" -N -B <<SQL
INSERT INTO users (username,password,date_added) VALUES ('$(esc "$EMAIL")','$(esc "$HASH")',NOW());
SET @uid=LAST_INSERT_ID();
INSERT INTO clients (id_format,id_value,user_id,client_group_id,status) VALUES ('{num}','$CIV',@uid,1,'active');
SET @cid=LAST_INSERT_ID();
INSERT INTO contacts (client_id,user_id,contact_type,first_name,last_name,email,country,date_added) VALUES (@cid,NULL,'primary','Proof','Client','$(esc "$EMAIL")','US',NOW());
INSERT INTO services (pricing_id,client_id,qty,status,id_format,id_value,date_added,date_renews) VALUES (4,@cid,1,'active','{num}','$SIV',NOW(),DATE_ADD(NOW(),INTERVAL 1 MONTH));
SET @sid=LAST_INSERT_ID();
INSERT INTO service_options (service_id,option_pricing_id,qty,value,encrypted) VALUES (@sid,$SPID,1,'$(esc "$SUB")',0),(@sid,$TPID,1,'$(esc "$THEME_ID")',0);
SELECT @uid,@cid,@sid;
SQL
)
BUID=$(printf "%s" "$IDS" | tail -1 | cut -f1)
CID=$(printf "%s" "$IDS" | tail -1 | cut -f2)
SID=$(printf "%s" "$IDS" | tail -1 | cut -f3)
echo "created user=$BUID client=$CID service=$SID (status=active, no staff approval, custom fields set)"
[ -n "$SID" ] && [ "$SID" != "0" ] || { echo "FAIL: service insert failed"; exit 1; }

echo "=== 4. run poller (simulates the 1-min cron tick; no admin action) ==="
php /opt/falcon-provisioner/bin/blesta_poll.php; echo "poller exit=$?"
sleep 2

echo "=== 5. verify site is live ==="
CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://$SUB.falcon.codes/" --max-time 25)
SSLOK=$(curl -s -o /dev/null -w "%{http_code}" "https://$SUB.falcon.codes/" --max-time 25 && echo)
echo "https://$SUB.falcon.codes/ -> HTTP $CODE"
echo "SSL cert present: $([ -d /etc/letsencrypt/live/$SUB.falcon.codes ] && echo yes || echo no)"

echo "=== 6. service_options creds written by poller ==="
q "SELECT po.name, LEFT(so.value,48) FROM service_options so
   JOIN package_option_pricing pop ON pop.id=so.option_pricing_id
   JOIN package_option_values pov ON pov.id=pop.option_value_id
   JOIN package_options po ON po.id=pov.option_id
   WHERE so.service_id=$SID ORDER BY po.id;"

echo "=== 7. REAL client login -> service manage page shows creds ==="
CJ=$(mktemp)
CSRF=$(curl -s -c "$CJ" "$PORTAL/client/login/" | grep -oE 'name="_csrf_token"[^>]*value="[^"]+"' | grep -oE 'value="[^"]+"' | head -1 | sed 's/value="//;s/"//')
curl -s -o /dev/null -b "$CJ" -c "$CJ" --data-urlencode "_csrf_token=$CSRF" \
  --data-urlencode "username=$EMAIL" --data-urlencode "password=$CLIENTPW" --data-urlencode "login=Login" "$PORTAL/client/login/"
MANAGE=$(curl -s -b "$CJ" "$PORTAL/client/services/manage/$SID/")
echo "manage bytes=$(printf "%s" "$MANAGE" | wc -c)"
FTPU=$(grep -E "^FTP_USER=" /var/lib/falcon-provisioner/secrets/$SUB.env | cut -d= -f2)
FTPP=$(grep -E "^FTP_PASS=" /var/lib/falcon-provisioner/secrets/$SUB.env | cut -d= -f2)
WPP=$(grep -E "^WP_ADMIN_PASS=" /var/lib/falcon-provisioner/secrets/$SUB.env | cut -d= -f2)
for probe in "Configurable Options" "$SUB.falcon.codes" "falconadmin" "$WPP" "$FTPU" "$FTPP" "FTP Password"; do
  echo "  [$(printf "%s" "$MANAGE" | grep -c -- "$probe")] $probe"
done
rm -f "$CJ"

echo "=== 8. REAL FTP/FTPS login test with generated credential ==="
echo "ftp user=$FTPU"
echo "--- FTPS (explicit TLS) directory listing ---"
curl -s --ssl-reqd --ftp-ssl --insecure --max-time 25 -u "$FTPU:$FTPP" "ftp://$SUB.falcon.codes/" | head -20
echo "--- exit code: $? ---"
echo "--- plain FTP listing (fallback) ---"
curl -s --max-time 25 -u "$FTPU:$FTPP" "ftp://$SUB.falcon.codes/" | head -20
echo "--- exit code: $? ---"

echo "########## RESULT: service=$SID client=$CID url=https://$SUB.falcon.codes/ (HTTP $CODE) ##########"
echo "CLIENT_LOGIN=$EMAIL / $CLIENTPW   FTP=$FTPU / $FTPP"
