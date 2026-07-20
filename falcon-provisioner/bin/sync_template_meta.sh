#!/usr/bin/env bash
#
# Update template meta.json (active flag + catalog fields). Runs as root via sudo.
# Usage: sync_template_meta.sh SLUG ACTIVE_JSON
#   ACTIVE_JSON is a one-line JSON object merged into meta.json (e.g. {"active":true})
#
set -euo pipefail

SLUG="${1:-}"; PATCH="${2:-}"
TEMPLATES_DIR="/var/lib/falcon-provisioner/templates"
SITE_USER="falco6812"

[ "$(id -u)" -eq 0 ] || { echo "must run as root" >&2; exit 1; }
[ -n "$SLUG" ] && [ -n "$PATCH" ] || exit 2

META="${TEMPLATES_DIR}/${SLUG}/meta.json"
[ -f "$META" ] || exit 0

php -r '
  $path = $argv[1];
  $patch = json_decode($argv[2], true);
  if (!is_array($patch)) { exit(3); }
  $meta = json_decode(@file_get_contents($path), true);
  if (!is_array($meta)) { exit(4); }
  $meta = array_merge($meta, $patch);
  file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$META" "$PATCH"

chown root:"$SITE_USER" "$META"
chmod 640 "$META"
exit 0
