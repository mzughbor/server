#!/usr/bin/env bash
#
# Diagnose and repair WordPress template catalog rows vs VPS storage.
#
# template_id in Blesta admin = falcon_wp_templates.id (auto-increment PK).
# Catalog slug = falcon_wp_templates.slug (= storage dir name under templates/).
#
# Usage:
#   repair_templates.sh              # status report (default)
#   repair_templates.sh --sync-meta  # align meta.json active with DB
#   repair_templates.sh --capture personal
#   repair_templates.sh --capture-all
#
set -euo pipefail

DB="${FALCON_BLESTA_DB:-falc_blesta}"
TABLE="falcon_wp_templates"
TEMPLATES_DIR="/var/lib/falcon-provisioner/templates"
HOME_BASE="/home/falcon.codes"
CAPTURE_BIN="/opt/falcon-provisioner/bin/snapshot_capture.sh"
SYNC_META_BIN="/opt/falcon-provisioner/bin/sync_template_meta.sh"
MYSQL=(mysql -N -B "$DB")

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] $*"; }

staging_ready() {
  local domain="$1"
  [ -n "$domain" ] && [ -f "${HOME_BASE}/${domain}/wp-config.php" ]
}

capture_ready() {
  local slug="$1"
  local dir="${TEMPLATES_DIR}/${slug}"
  [ -d "$dir" ] \
    && [ -r "${dir}/database.sql.gz" ] \
    && [ -r "${dir}/wp-content.tar.gz" ]
}

meta_active() {
  local slug="$1"
  local meta="${TEMPLATES_DIR}/${slug}/meta.json"
  [ -f "$meta" ] || return 1
  php -r '
    $m = json_decode(@file_get_contents($argv[1]), true);
    echo !empty($m["active"]) ? "1" : "0";
  ' "$meta" 2>/dev/null
}

fetch_templates() {
  "${MYSQL[@]}" -e "
    SELECT id, slug, name, IFNULL(description,''), IFNULL(thumbnail_url,''),
           staging_domain, active, IFNULL(last_captured,''), sort_order
    FROM ${TABLE}
    ORDER BY sort_order ASC, id ASC
  "
}

print_status() {
  log "=== falcon_wp_templates status (${DB}.${TABLE}) ==="
  printf '%-4s %-14s %-8s %-10s %-10s %-12s %-s\n' \
    'id' 'slug' 'active' 'staging' 'capture' 'catalog' 'staging_domain'
  while IFS=$'\t' read -r id slug name desc thumb staging active captured sort; do
    local s_cap s_stg cat meta_a issues=()
    if staging_ready "$staging"; then s_stg=ready; else s_stg=missing; fi
    if capture_ready "$slug"; then s_cap=ready; else s_cap=missing; fi
    if [ "$active" = "1" ] && [ "$s_cap" = ready ]; then cat=live; elif [ "$active" = "1" ]; then cat=pending; else cat=hidden; fi
    if [ "$active" = "1" ] && [ "$s_cap" = missing ]; then issues+=('active-but-no-capture'); fi
    if [ "$s_stg" = ready ] && [ "$s_cap" = missing ]; then issues+=('needs-capture'); fi
    if capture_ready "$slug"; then
      meta_a="$(meta_active "$slug" || echo '?')"
      if [ "$active" = "1" ] && [ "$meta_a" = "0" ]; then issues+=('meta-active-stale'); fi
    fi
    printf '%-4s %-14s %-8s %-10s %-10s %-12s %-s' \
      "$id" "$slug" "$active" "$s_stg" "$s_cap" "$cat" "$staging"
    if [ "${#issues[@]}" -gt 0 ]; then
      printf '  [%s]' "$(IFS=','; echo "${issues[*]}")"
    fi
    echo
  done < <(fetch_templates)
  echo
  log "template_id = DB column id (sent as template_id in Capture/Provision AJAX)"
  log "slug        = catalog key + storage dir name (service theme field must match slug)"
}

sync_meta_for_slug() {
  local slug="$1" active="$2" name="$3" desc="$4" thumb="$5" staging="$6" sort="$7"
  capture_ready "$slug" || return 0
  local patch
  patch="$(php -r '
    echo json_encode([
      "active" => (bool)(int)$argv[1],
      "name" => $argv[2],
      "description" => $argv[3],
      "thumbnail_url" => $argv[4] !== "" ? $argv[4] : null,
      "staging_domain" => $argv[5],
      "sort_order" => (int)$argv[6],
    ], JSON_UNESCAPED_SLASHES);
  ' "$active" "$name" "$desc" "$thumb" "$staging" "$sort")"
  if [ "$(id -u)" -eq 0 ]; then
    "$SYNC_META_BIN" "$slug" "$patch"
  else
    sudo "$SYNC_META_BIN" "$slug" "$patch"
  fi
  log "synced meta.json active=${active} slug=${slug}"
}

sync_all_meta() {
  while IFS=$'\t' read -r id slug name desc thumb staging active captured sort; do
    sync_meta_for_slug "$slug" "$active" "$name" "$desc" "$thumb" "$staging" "$sort" || true
  done < <(fetch_templates)
}

run_capture() {
  local slug="$1"
  local row
  row="$("${MYSQL[@]}" -e "
    SELECT id, slug, name, IFNULL(description,''), IFNULL(thumbnail_url,''), staging_domain, active, sort_order
    FROM ${TABLE} WHERE slug='${slug}' LIMIT 1
  ")"
  [ -n "$row" ] || { log "ERROR: no DB row for slug=${slug}"; return 1; }

  IFS=$'\t' read -r id slug name desc thumb staging active sort <<< "$row"
  [ -n "$staging" ] || { log "ERROR: slug=${slug} has empty staging_domain"; return 1; }
  staging_ready "$staging" || { log "ERROR: staging WP missing at ${HOME_BASE}/${staging}"; return 1; }

  local cmd=( "$CAPTURE_BIN" --domain "$staging" --slug "$slug" --name "$name" --description "$desc" )
  [ -n "$thumb" ] && cmd+=( --thumbnail-url "$thumb" )

  log "capture template_id=${id} slug=${slug} domain=${staging}"
  if [ "$(id -u)" -eq 0 ]; then
    "${cmd[@]}"
  else
    sudo "${cmd[@]}"
  fi

  local now
  now="$(date '+%Y-%m-%d %H:%M:%S')"
  mysql "$DB" -e "UPDATE ${TABLE} SET last_captured='${now}', date_updated='${now}' WHERE id=${id};"
  sync_meta_for_slug "$slug" "$active" "$name" "$desc" "$thumb" "$staging" "$sort"
  log "capture complete template_id=${id} slug=${slug} last_captured=${now}"
}

capture_all_missing() {
  while IFS=$'\t' read -r id slug name desc thumb staging active captured sort; do
    if staging_ready "$staging" && ! capture_ready "$slug"; then
      run_capture "$slug" || log "WARN: capture failed for slug=${slug}"
    fi
  done < <(fetch_templates)
}

MODE="${1:---status}"
case "$MODE" in
  --status|-s)
    print_status
    ;;
  --sync-meta)
    print_status
    sync_all_meta
    print_status
    ;;
  --capture)
    [ -n "${2:-}" ] || { echo "Usage: $0 --capture SLUG" >&2; exit 2; }
    print_status
    run_capture "$2"
    print_status
    ;;
  --capture-all)
    print_status
    capture_all_missing
    print_status
    ;;
  -h|--help)
    sed -n '2,14p' "$0" | tail -n +2
    ;;
  *)
    echo "Unknown option: $MODE" >&2
    exit 2
    ;;
esac
