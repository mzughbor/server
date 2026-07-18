#!/usr/bin/env bash
# Validate that config-option creds render in the Blesta client area by actually
# logging in as the client and fetching the service manage page.
set -uo pipefail
DB=falc_blesta
SVC=3           # falcondemo1 service
USERID=4        # clients.id=3 -> user_id=4
CLIENTPW='FalconTest!2026'
MAP=/opt/falcon-provisioner/templates/blesta_options.json
BASE=https://falcon.codes/portal
q(){ mysql "$DB" -N -B -e "$1"; }
mapid(){ php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m[$argv[2]]??"";' "$MAP" "$1"; }

echo "=== set known bcrypt password for user $USERID ==="
HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost"=>12]);' "$CLIENTPW")
q "UPDATE users SET password='$HASH' WHERE id=$USERID;"
USERNAME=$(q "SELECT username FROM users WHERE id=$USERID;")
echo "username=$USERNAME"

echo "=== insert service_options creds for service $SVC ==="
declare -A V=(
  [subdomain]="falcondemo1"
  [theme]="studio"
  [site_url]="https://falcondemo1.falcon.codes"
  [wp_admin_url]="https://falcondemo1.falcon.codes/wp-admin/"
  [wp_user]="falconadmin"
  [wp_pass]="WPtestPass123"
  [ftp_access]="ftp://falcondemo1.falcon.codes:21 (explicit TLS/FTPS)"
  [ftp_user]="falcondemo1"
  [ftp_pass]="FTPtestPass123"
)
for k in subdomain theme site_url wp_admin_url wp_user wp_pass ftp_access ftp_user ftp_pass; do
  pid=$(mapid "$k"); val="${V[$k]}"; valEsc=$(printf "%s" "$val" | sed "s/'/''/g")
  q "DELETE FROM service_options WHERE service_id=$SVC AND option_pricing_id=$pid;"
  q "INSERT INTO service_options (service_id,option_pricing_id,qty,value,encrypted) VALUES ($SVC,$pid,1,'$valEsc',0);"
done
echo "service_options now:"; q "SELECT option_pricing_id,LEFT(value,40) FROM service_options WHERE service_id=$SVC;"

echo "=== client login via curl ==="
CJ=$(mktemp)
# 1) get login page + csrf
LOGIN_HTML=$(curl -s -c "$CJ" "$BASE/client/login/")
CSRF=$(printf "%s" "$LOGIN_HTML" | grep -oE 'name="_csrf_token"[^>]*value="[^"]+"' | grep -oE 'value="[^"]+"' | head -1 | sed 's/value="//;s/"//')
echo "csrf=${CSRF:0:16}..."
# 2) post credentials
curl -s -b "$CJ" -c "$CJ" -o /dev/null \
  --data-urlencode "_csrf_token=$CSRF" \
  --data-urlencode "username=$USERNAME" \
  --data-urlencode "password=$CLIENTPW" \
  "$BASE/client/login/"
# 3) fetch service manage page
MANAGE=$(curl -s -b "$CJ" "$BASE/client/services/manage/$SVC/")
echo "=== does manage page show our creds? ==="
echo "Configurable Options heading: $(printf "%s" "$MANAGE" | grep -c "Configurable Options")"
for probe in "falcondemo1.falcon.codes" "falconadmin" "WPtestPass123" "FTPtestPass123" "Website URL" "FTP Password"; do
  cnt=$(printf "%s" "$MANAGE" | grep -c -- "$probe")
  echo "  [$cnt] $probe"
done
echo "=== page size + logged-in check ==="
echo "bytes=$(printf "%s" "$MANAGE" | wc -c); logged_in_marker(logout)=$(printf "%s" "$MANAGE" | grep -c -iE "logout|sign out")"
rm -f "$CJ"
