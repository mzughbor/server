#!/usr/bin/env bash
# Deploy repo files TO the live server (repo -> server). Non-destructive.
# NEVER deletes portal/, api runtime, child sites, or secrets.
#   Usage: falcon-provisioner/bin/sync_from_repo.sh [ssh_alias]
set -euo pipefail

SSH="${1:-falcon-vps}"
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$REPO"
OWNER="falco6812:falco6812"

echo ">> Homepage -> public_html (no --delete; keeps portal/ + api/)"
rsync -a falcon-codes/ "$SSH":/home/falcon.codes/public_html/
ssh "$SSH" "chown -R $OWNER /home/falcon.codes/public_html/{index.html,script.js,styles.css,packages.json,assets}"

echo ">> Subdomain API -> public_html/api"
rsync -a falcon-provisioner/web/api/ "$SSH":/home/falcon.codes/public_html/api/
ssh "$SSH" "chown -R $OWNER /home/falcon.codes/public_html/api"

echo ">> Provisioner -> /opt/falcon-provisioner (no secrets)"
rsync -a --exclude='secrets' \
  falcon-provisioner/bin falcon-provisioner/templates falcon-provisioner/README.txt falcon-provisioner/falcon-provisioner.cron \
  "$SSH":/opt/falcon-provisioner/
ssh "$SSH" 'chmod +x /opt/falcon-provisioner/bin/*.sh /opt/falcon-provisioner/bin/*.php 2>/dev/null || true'

echo ">> Blesta custom theme -> portal (does NOT touch core)"
rsync -a portal-custom/app/views/client/falcon/ \
  "$SSH":/home/falcon.codes/public_html/portal/app/views/client/falcon/
ssh "$SSH" "chown -R $OWNER /home/falcon.codes/public_html/portal/app/views/client/falcon"

echo ">> Blesta custom plugins -> portal/plugins (does NOT touch stock plugins)"
rsync -a portal-custom/plugins/ \
  "$SSH":/home/falcon.codes/public_html/portal/plugins/
ssh "$SSH" "chown -R $OWNER /home/falcon.codes/public_html/portal/plugins/falcon_theme_snapshots 2>/dev/null || true"

echo ">> Docs -> /srv/falcon-docs"
rsync -a docs/ "$SSH":/srv/falcon-docs/

echo "Done. Verify: curl -sI https://falcon.codes/ | head -1"
