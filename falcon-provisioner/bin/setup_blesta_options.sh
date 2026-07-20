#!/usr/bin/env bash
# One-time Blesta setup for Phase 4 "creds in client area" + auto-activate.
# - Section A: force $0 trial auto-activation (idempotent).
# - Creates a config-option group assigned to package 4 with fields the
#   provisioner/poller populate per service (subdomain, theme + access creds).
#   The client sees these under Client area -> Services -> (service) ->
#   "Configurable Options" (rendered by the stock/falcon theme, no theme edit).
# - subdomain + theme are addable=1 (visible on the ORDER form) so the customer's
#   choice is captured natively into service_options on checkout; the order
#   template's prefill JS fills + hides them. The poller then reads them as the
#   PRIMARY source (source=blesta_field) instead of the bridge.json fallback.
#   The remaining credential fields stay hidden=1 (poller-written, display-only).
# Emits /opt/falcon-provisioner/templates/blesta_options.json  { field: option_pricing_id }
# Safe to re-run: purges its own prior group/options first.
set -uo pipefail
DB=falc_blesta
PKG=4
# All Falcon hosting packages that share subdomain/theme config options
PKG_IDS="${FALCON_HOSTING_PKG_IDS:-4}"
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

echo "=== Section A2: minimal signup / contact fields (order + client area) ==="
# Blesta stores these as base64-encoded JSON arrays in company_settings.
# Before: shown=["address1","city","country","phone"], required=[]
# After:  shown=["first_name","last_name","email"], required=["email","first_name","last_name"]
# Hides address/country/state/zip/fax/company/title from order checkout.
MIN_SHOWN='WyJmaXJzdF9uYW1lIiwibGFzdF9uYW1lIiwiZW1haWwiXQ=='
MIN_REQUIRED='WyJlbWFpbCIsImZpcnN0X25hbWUiLCJsYXN0X25hbWUiXQ=='
MIN_READONLY='W10='
q "INSERT INTO company_settings (company_id,\`key\`,value,encrypted) VALUES (1,'shown_contact_fields','${MIN_SHOWN}',0)
   ON DUPLICATE KEY UPDATE value='${MIN_SHOWN}';"
q "INSERT INTO company_settings (company_id,\`key\`,value,encrypted) VALUES (1,'required_contact_fields','${MIN_REQUIRED}',0)
   ON DUPLICATE KEY UPDATE value='${MIN_REQUIRED}';"
q "INSERT INTO company_settings (company_id,\`key\`,value,encrypted) VALUES (1,'read_only_contact_fields','${MIN_READONLY}',0)
   ON DUPLICATE KEY UPDATE value='${MIN_READONLY}';"
q "INSERT INTO company_settings (company_id,\`key\`,value,encrypted) VALUES (1,'show_client_tax_id','false',0)
   ON DUPLICATE KEY UPDATE value='false';"
echo "shown_contact_fields=$(echo WyJmaXJzdF9uYW1lIiwibGFzdF9uYW1lIiwiZW1haWwi | base64 -d 2>/dev/null || true)"
echo "required_contact_fields=$(echo WyJlbWFpbCIsImZpc3RfbmFtZSIsImxhc3RfbmFtZSJd | base64 -d 2>/dev/null || true)"

echo "=== Section B: ensure Blesta system cron runs (activates paid-pending services) ==="
BLESTA_ROOT="${BLESTA_ROOT:-/home/falcon.codes/public_html/portal}"
BLESTA_USER="${BLESTA_USER:-falco6812}"
CRON_D=/etc/cron.d/falcon-provisioner
if [ -f /opt/falcon-provisioner/falcon-provisioner.cron ]; then
  cp /opt/falcon-provisioner/falcon-provisioner.cron "$CRON_D"
  chmod 644 "$CRON_D"
  echo "installed $CRON_D (Blesta cron as $BLESTA_USER + poller as root)"
elif [ -d "$BLESTA_ROOT" ]; then
  cat > "$CRON_D" <<CRON
# Falcon of Codes - Blesta system cron + provisioner poller
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * $BLESTA_USER cd $BLESTA_ROOT && /usr/bin/php index.php cron >/dev/null 2>&1
* * * * * root /usr/bin/php /opt/falcon-provisioner/bin/blesta_poll.php >/dev/null 2>&1
CRON
  chmod 644 "$CRON_D"
  echo "installed $CRON_D (inline fallback)"
else
  echo "WARN: Blesta root not found; cron not installed"
fi
# Remove duplicate Blesta cron from the web user's personal crontab (if present).
if crontab -u "$BLESTA_USER" -l 2>/dev/null | grep -qE 'portal/(cron\.php|index\.php cron)'; then
  crontab -u "$BLESTA_USER" -l 2>/dev/null \
    | grep -vE 'portal/(cron\.php|index\.php cron)' \
    | crontab -u "$BLESTA_USER" - 2>/dev/null || true
  echo "removed duplicate Blesta cron from $BLESTA_USER user crontab"
fi

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
for _pkg in $(echo "$PKG_IDS" | tr ',' ' '); do
  q "INSERT IGNORE INTO package_option (package_id,option_group_id,\`order\`) VALUES ($_pkg,$GID,0);"
done

# field|label|type|addable|hidden   (order preserved for display)
#   addable=1,hidden=0  -> captured natively at ORDER time (customer's choice
#                          lands in service_options on checkout) AND visible in
#                          the client area. Used for subdomain + theme so the
#                          poller reads them as source=blesta_field.
#   addable=0,hidden=1  -> display-only credential fields written by the poller
#                          AFTER provisioning (never shown on the order form).
# editable is 0 for every field (clients never change them post-order); the
# prefill JS in the order template makes the two capturable inputs readonly.
FIELDS=(
  "subdomain|Subdomain|text|1|0"
  "theme|Theme|text|1|0"
  "site_url|Website URL|text|0|1"
  "wp_admin_url|WordPress Admin URL|text|0|1"
  "wp_user|WordPress Username|text|0|1"
  "wp_pass|WordPress Password|password|0|1"
  "ftp_access|File Access (FTP / FTPS)|text|0|1"
  "ftp_user|FTP Username|text|0|1"
  "ftp_pass|FTP Password|password|0|1"
)

echo "{" > /tmp/blesta_options.json
i=0; n=${#FIELDS[@]}
for row in "${FIELDS[@]}"; do
  IFS='|' read -r key label type addable hidden <<< "$row"
  mysql "$DB" <<SQL
INSERT INTO package_options (company_id,label,name,description,type,addable,editable,hidden,disable_pricing,hide_on_invoice)
VALUES (1,'$label','$key','','$type',$addable,0,$hidden,1,1);
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
