#!/usr/bin/env bash
# One-time VPS setup: read-only MySQL user for the public theme catalog API.
# Usage: ssh falcons 'bash -s' < falcon-provisioner/bin/setup_catalog_db.sh
set -euo pipefail

DB="falc_blesta"
TABLE="falcon_wp_templates"
USER="falc_catalog"
CNF="/var/lib/falcon-provisioner/catalog_db.cnf"
SITE_USER="falco6812"
STATE_DIR="/var/lib/falcon-provisioner"

PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"

echo ">> MySQL read-only user ${USER} for ${DB}.${TABLE}"
mysql -e "CREATE USER IF NOT EXISTS '${USER}'@'localhost' IDENTIFIED BY '${PASS}';"
mysql -e "GRANT SELECT ON \`${DB}\`.\`${TABLE}\` TO '${USER}'@'localhost'; FLUSH PRIVILEGES;"

echo ">> Credentials file ${CNF} (readable by ${SITE_USER})"
install -m 640 -o root -g "${SITE_USER}" /dev/stdin "${CNF}" <<EOF
[client]
user=${USER}
password=${PASS}
database=${DB}
host=localhost
EOF

echo "Done. Test: sudo -u ${SITE_USER} mysql --defaults-extra-file=${CNF} -e 'SELECT slug, active FROM ${TABLE};'"
