#!/usr/bin/env bash
# One-time Blesta setup for Phase 4 "creds in client area" + auto-activate.
# - Section A: force $0 trial auto-activation (idempotent).
# - Creates a hidden config-option group assigned to package 4 with fields the
#   provisioner/poller populate per service (subdomain, theme + access creds).
#   The client sees these under Client area -> Services -> (service) ->
#   "Configurable Options" (rendered by the stock/falcon theme, no theme edit).
# Emits /opt/falcon-provisioner/templates/blesta_options.json  { field: option_pricing_id }
# Safe to re-run: purges its own prior group/options first.
set -uo pipefail
DB=falc_blesta
PKG=4
OPTPRICING=34   # pricings.id for 1 month @ $0 (reused for option pricing rows)
MAP=/opt/falcon-provisioner/templates/blesta_options.json
GROUP_NAME="Trial Site Access"

q(){ mysql "$DB" -N -B -e "$1"; }

FIELD_NAMES="'subdomain','theme','site_url','wp_admin_url','wp_user','wp_pass','ftp_access','ftp_user','ftp_pass'"

echo "=== Section A: ensure \$0 trial auto-activates (no manual gates) ==="
q "UPDATE order_forms SET manual_review=0, require_captcha=0, require_tos=0 WHERE id=1;"
q "UPDATE packages SET manual_activation=0 WHERE id=$PKG;"
q "INSERT INTO company_settings (company_id,\`key\`,value,encrypted) VALUES (1,'email_verification','false',0)
   ON DUPLICATE KEY UPDATE value='false';"
q "INSERT INTO company_settings (company_id,\`key\`,value,encrypted) VALUES (1,'prevent_unverified_payments','false',0)
   ON DUPLICATE KEY UPDATE value='false';"
q "DELETE FROM order_settings WHERE company_id=1 AND \`key\` IN ('antifraud','antifraud_after');" 2>/dev/null || true
echo "order_forms(id=1): $(q "SELECT CONCAT('manual_review=',manual_review,' captcha=',require_captcha,' tos=',require_tos) FROM order_forms WHERE id=1;")"
echo "packages(id=$PKG): manual_activation=$(q "SELECT manual_activation FROM packages WHERE id=$PKG;") module_id=$(q "SELECT module_id FROM packages WHERE id=$PKG;")"

echo "=== Purge any prior Falcon option group/options (idempotent re-run) ==="
mysql "$DB" <<SQL
DELETE pop FROM package_option_pricing pop
  JOIN package_option_values pov ON pov.id=pop.option_value_id
  JOIN package_options po ON po.id=pov.option_id
  WHERE po.company_id=1 AND po.name IN ($FIELD_NAMES);
DELETE pov FROM package_option_values pov
  JOIN package_options po ON po.id=pov.option_id
  WHERE po.company_id=1 AND po.name IN ($FIELD_NAMES);
DELETE pog FROM package_option_group pog
  JOIN package_options po ON po.id=pog.option_id
  WHERE po.company_id=1 AND po.name IN ($FIELD_NAMES);
DELETE FROM package_options WHERE company_id=1 AND name IN ($FIELD_NAMES);
DELETE po FROM package_option po
  JOIN package_option_groups pog ON pog.id=po.option_group_id
  WHERE pog.company_id=1 AND pog.name='$GROUP_NAME';
DELETE FROM package_option_groups WHERE company_id=1 AND name='$GROUP_NAME';
SQL

echo "=== Create config option group ==="
q "INSERT INTO package_option_groups (company_id,name,description,hidden,hide_options)
   VALUES (1,'$GROUP_NAME','Your trial site access details.',1,1);"
GID=$(q "SELECT id FROM package_option_groups WHERE company_id=1 AND name='$GROUP_NAME' ORDER BY id DESC LIMIT 1;")
echo "group id=$GID"
q "INSERT INTO package_option (package_id,option_group_id,\`order\`) VALUES ($PKG,$GID,0);"

# field|label|type    (order preserved for display)
FIELDS=(
  "subdomain|Subdomain|text"
  "theme|Theme|text"
  "site_url|Website URL|text"
  "wp_admin_url|WordPress Admin URL|text"
  "wp_user|WordPress Username|text"
  "wp_pass|WordPress Password|password"
  "ftp_access|File Access (FTP / FTPS)|text"
  "ftp_user|FTP Username|text"
  "ftp_pass|FTP Password|password"
)

echo "{" > /tmp/blesta_options.json
i=0; n=${#FIELDS[@]}
for row in "${FIELDS[@]}"; do
  key="${row%%|*}"; rest="${row#*|}"; label="${rest%%|*}"; type="${rest##*|}"
  mysql "$DB" <<SQL
INSERT INTO package_options (company_id,label,name,description,type,addable,editable,hidden,disable_pricing,hide_on_invoice)
VALUES (1,'$label','$key','','$type',0,0,1,1,1);
SET @oid=LAST_INSERT_ID();
INSERT INTO package_option_values (option_id,name,value,\`default\`,status,\`order\`,min,max,step)
VALUES (@oid,'','',0,'active',$i,NULL,NULL,NULL);
SET @vid=LAST_INSERT_ID();
INSERT INTO package_option_pricing (option_value_id,pricing_id) VALUES (@vid,$OPTPRICING);
INSERT INTO package_option_group (option_id,option_group_id,\`order\`) VALUES (@oid,$GID,$i);
SQL
  PID=$(q "SELECT pop.id FROM package_options po
           JOIN package_option_values pov ON pov.option_id=po.id
           JOIN package_option_pricing pop ON pop.option_value_id=pov.id
           WHERE po.company_id=1 AND po.name='$key' ORDER BY pop.id DESC LIMIT 1;")
  sep=","; [ $((i+1)) -eq $n ] && sep=""
  echo "  \"$key\": ${PID}${sep}" >> /tmp/blesta_options.json
  i=$((i+1))
done
echo "}" >> /tmp/blesta_options.json

cp /tmp/blesta_options.json "$MAP"; chmod 644 "$MAP"
echo "=== mapping ($MAP) ==="; cat "$MAP"
echo "=== verify options created ==="
q "SELECT po.name, po.label, po.type, pop.id AS option_pricing_id
   FROM package_options po
   JOIN package_option_values pov ON pov.option_id=po.id
   JOIN package_option_pricing pop ON pop.option_value_id=pov.id
   JOIN package_option_group pog ON pog.option_id=po.id AND pog.option_group_id=$GID
   WHERE po.company_id=1 ORDER BY pog.\`order\`;"
