#!/usr/bin/env bash
# Purge ALL test provisioned sites and Blesta test clients. Keeps admin/staff + client 1.
# Safe to re-run (idempotent on empty state).
set -uo pipefail

DB=falc_blesta
STATE_DIR="/var/lib/falcon-provisioner"
SITES="${STATE_DIR}/sites.json"
TEARDOWN="$(dirname "$0")/teardown_site.sh"
KEEP_CLIENT_IDS="1"
KEEP_USER_IDS="1,2"

echo "=== Purge test subdomains from sites.json + CyberPanel ==="
SUBS=()
if [ -f "$SITES" ]; then
  mapfile -t SUBS < <(php -r '$a=json_decode(file_get_contents($argv[1]),true)?:[]; foreach($a as $r){ $s=$r["subdomain"]??""; if($s!=="") echo $s,"\n"; }' "$SITES")
fi
if [ "${#SUBS[@]}" -eq 0 ]; then
  echo "No sites in ${SITES}"
else
  for sub in "${SUBS[@]}"; do
    echo "--- tearing down ${sub} ---"
    bash "$TEARDOWN" "$sub" || echo "WARN: teardown ${sub} had errors"
  done
fi

echo "[]" > "$SITES"
chmod 640 "$SITES" 2>/dev/null || true
echo "{}" > "${STATE_DIR}/processed.json"
echo "[]" > "${STATE_DIR}/bridge.json"
echo "[]" > "${STATE_DIR}/reservations.json"
echo "Reset sites.json, processed.json, bridge.json, reservations.json"

echo "=== Purge Blesta test clients (keep client_id ${KEEP_CLIENT_IDS}) ==="
TEST_CLIENTS=$(mysql "$DB" -N -B -e "SELECT id FROM clients WHERE id NOT IN (${KEEP_CLIENT_IDS});" 2>/dev/null | paste -sd, -)
if [ -z "$TEST_CLIENTS" ]; then
  echo "No test clients to purge"
  exit 0
fi
echo "Deleting clients: ${TEST_CLIENTS}"

mysql "$DB" <<SQL
SET @test_clients := '${TEST_CLIENTS}';

DELETE so FROM service_options so
  JOIN services s ON s.id = so.service_id
  WHERE FIND_IN_SET(s.client_id, @test_clients);

DELETE si FROM service_invoices si
  JOIN services s ON s.id = si.service_id
  WHERE FIND_IN_SET(s.client_id, @test_clients);

DELETE ls FROM log_services ls
  JOIN services s ON s.id = ls.service_id
  WHERE FIND_IN_SET(s.client_id, @test_clients);

DELETE os FROM order_services os
  JOIN services s ON s.id = os.service_id
  WHERE FIND_IN_SET(s.client_id, @test_clients);

DELETE FROM services WHERE FIND_IN_SET(client_id, @test_clients);

DELETE il FROM invoice_line_taxes il
  JOIN invoice_lines l ON l.id = il.line_id
  JOIN invoices i ON i.id = l.invoice_id
  WHERE FIND_IN_SET(i.client_id, @test_clients);

DELETE l FROM invoice_lines l
  JOIN invoices i ON i.id = l.invoice_id
  WHERE FIND_IN_SET(i.client_id, @test_clients);

DELETE ta FROM transaction_applied ta
  JOIN invoices i ON i.id = ta.invoice_id
  WHERE FIND_IN_SET(i.client_id, @test_clients);

DELETE FROM transactions WHERE FIND_IN_SET(client_id, @test_clients);

DELETE o FROM orders o
  JOIN invoices i ON i.id = o.invoice_id
  WHERE FIND_IN_SET(i.client_id, @test_clients);

DELETE FROM invoices WHERE FIND_IN_SET(client_id, @test_clients);

DELETE cn FROM contact_numbers cn
  JOIN contacts c ON c.id = cn.contact_id
  WHERE FIND_IN_SET(c.client_id, @test_clients);

DELETE FROM contacts WHERE FIND_IN_SET(client_id, @test_clients);
DELETE FROM client_notes WHERE FIND_IN_SET(client_id, @test_clients);
DELETE FROM client_account WHERE FIND_IN_SET(client_id, @test_clients);
DELETE FROM client_settings WHERE FIND_IN_SET(client_id, @test_clients);
DELETE FROM client_values WHERE FIND_IN_SET(client_id, @test_clients);

DELETE u FROM users u
  JOIN clients c ON c.user_id = u.id
  WHERE FIND_IN_SET(c.id, @test_clients);

DELETE FROM clients WHERE FIND_IN_SET(id, @test_clients);
SQL

echo "Remaining clients:"
mysql "$DB" -e "SELECT c.id, u.username, c.status FROM clients c JOIN users u ON u.id=c.user_id ORDER BY c.id;"
echo "Remaining services:"
mysql "$DB" -e "SELECT id, client_id, status FROM services ORDER BY id;"
echo "Purge complete."
