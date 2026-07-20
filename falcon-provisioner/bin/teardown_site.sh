#!/usr/bin/env bash
# Remove one provisioned child site from CyberPanel, MySQL, FTP, and provisioner state.
# Usage: teardown_site.sh <subdomain> [--keep-db]
set -uo pipefail

SUB="${1:-}"
KEEP_DB=0
[ "${2:-}" = "--keep-db" ] && KEEP_DB=1

[ -n "$SUB" ] || { echo "Usage: $0 <subdomain> [--keep-db]" >&2; exit 2; }
SUB="$(echo "$SUB" | tr '[:upper:]' '[:lower:]')"
FQDN="${SUB}.falcon.codes"
MASTER="falcon.codes"
STATE_DIR="/var/lib/falcon-provisioner"
SITES="${STATE_DIR}/sites.json"
LOG="/var/log/falcon-provisioner.log"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [teardown] $*" | tee -a "$LOG" >&2; }

# Look up DB name from sites.json if present.
DBNAME="wp_${SUB//-/_}"
DBUSER="$DBNAME"
if [ -f "$SITES" ]; then
  read -r DBNAME DBUSER <<< "$(php -r '
    $a=json_decode(file_get_contents($argv[1]),true) ?: [];
    foreach($a as $r){
      if(($r["subdomain"]??"")===$argv[2]){
        echo ($r["db"]??"")." ".($r["db_user"]??"");
        exit;
      }
    }
  ' "$SITES" "$SUB")"
  [ -n "$DBNAME" ] || DBNAME="wp_${SUB//-/_}"
  [ -n "$DBUSER" ] || DBUSER="$DBNAME"
fi

log "teardown start: sub=${SUB} fqdn=${FQDN} db=${DBNAME}"

if cyberpanel deleteChild --childDomain "$FQDN" >>"$LOG" 2>&1; then
  log "cyberpanel deleteChild ok for ${FQDN}"
else
  log "WARN: cyberpanel deleteChild failed or domain missing for ${FQDN}"
fi

if [ -d "/home/${MASTER}/${FQDN}" ]; then
  rm -rf "/home/${MASTER}/${FQDN}"
  log "removed docroot /home/${MASTER}/${FQDN}"
fi

if [ "$KEEP_DB" -eq 0 ]; then
  mysql -e "DROP DATABASE IF EXISTS \`${DBNAME}\`;" 2>>"$LOG" && log "dropped database ${DBNAME}" || log "WARN: drop database ${DBNAME} failed"
  mysql -e "DROP USER IF EXISTS '${DBUSER}'@'localhost';" 2>>"$LOG" && log "dropped user ${DBUSER}" || log "WARN: drop user ${DBUSER} failed"
fi

mysql cyberpanel -e "DELETE FROM users WHERE User='${SUB}';" 2>>"$LOG" && log "removed FTP user ${SUB}" || true
rm -f "${STATE_DIR}/secrets/${SUB}.env"

for f in "$SITES" "${STATE_DIR}/bridge.json" "${STATE_DIR}/reservations.json" "${STATE_DIR}/processed.json"; do
  [ -f "$f" ] || continue
  php -r '
    $f=$argv[1]; $sub=$argv[2]; $mode=$argv[3] ?? "subdomain";
    $fh=fopen($f,"c+"); if(!$fh) exit(1);
    flock($fh,LOCK_EX);
    $raw=stream_get_contents($fh);
    $a=json_decode($raw,true);
    if(!is_array($a)){ flock($fh,LOCK_UN); fclose($fh); exit(0); }
    if($mode==="processed"){
      foreach($a as $sid=>$rec){
        if(($rec["subdomain"]??"")===$sub) unset($a[$sid]);
      }
    } else {
      $a=array_values(array_filter($a, fn($r)=>($r["subdomain"]??"")!==$sub));
    }
    ftruncate($fh,0); rewind($fh);
    fwrite($fh, json_encode($a, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    fflush($fh); flock($fh,LOCK_UN); fclose($fh);
  ' "$f" "$SUB" "$(basename "$f" .json)"
done

log "teardown complete: ${SUB}"
echo "TEARDOWN_OK sub=${SUB} fqdn=${FQDN}"
