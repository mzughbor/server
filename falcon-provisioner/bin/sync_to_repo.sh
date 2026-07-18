#!/usr/bin/env bash
# Pull LIVE server files into this repo (server -> repo). Secret-safe.
# Run from your laptop where the git clone + `falcon-vps` ssh alias exist.
#   Usage: falcon-provisioner/bin/sync_to_repo.sh [ssh_alias]
set -euo pipefail

SSH="${1:-falcon-vps}"
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$REPO"

echo ">> Homepage (html/js/css/assets only)"
rsync -a "$SSH":/home/falcon.codes/public_html/index.html \
        "$SSH":/home/falcon.codes/public_html/script.js \
        "$SSH":/home/falcon.codes/public_html/styles.css  falcon-codes/
rsync -a --exclude='*.bak*' "$SSH":/home/falcon.codes/public_html/assets/ falcon-codes/assets/

echo ">> Provisioner (bin/templates/README, no secrets, no .bak)"
rsync -a --exclude='*.bak*' --exclude='secrets' \
  "$SSH":/opt/falcon-provisioner/bin \
  "$SSH":/opt/falcon-provisioner/templates \
  "$SSH":/opt/falcon-provisioner/README.txt  falcon-provisioner/

echo ">> Subdomain API source"
rsync -a "$SSH":/home/falcon.codes/public_html/api falcon-provisioner/web/

echo ">> Blesta custom client theme only"
rsync -a "$SSH":/home/falcon.codes/public_html/portal/app/views/client/falcon \
  portal-custom/app/views/client/

echo ">> Docs"
rsync -a --exclude='*.bak*' "$SSH":/srv/falcon-docs/ docs/

echo ">> Stripping any stray backups"
find falcon-codes falcon-provisioner portal-custom docs -name '*.bak*' -delete 2>/dev/null || true

echo ">> Secret scan (should print nothing)"
if grep -rniE '[A-Fa-f0-9]{32,}|(API_KEY|password|db_pass)[[:space:]]*[:=][[:space:]]*[A-Za-z0-9/+._-]{10,}' \
     falcon-codes falcon-provisioner portal-custom docs 2>/dev/null \
   | grep -viE 'integrity|sha|md5|hash|acme|example|placeholder|<|YOUR_|\$|redacted|\.min\.|\.woff'; then
  echo "!! Potential secret detected above — review before committing." >&2
  exit 1
fi
echo "Done. Review 'git status' then commit."
