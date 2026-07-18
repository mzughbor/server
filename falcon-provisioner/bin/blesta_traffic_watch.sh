#!/usr/bin/env bash
#
# blesta_traffic_watch.sh — outbound-traffic observability for the Blesta web/PHP stack.
#
# WHAT IT DOES
#   For a window of N minutes (default 10) it records every OUTBOUND connection
#   initiated by this host — driven, during the window, by the Blesta web/PHP
#   stack: the OpenLiteSpeed (litespeed/lshttpd) workers, the lsphp83 FastCGI
#   workers, and PHP-CLI processes (Blesta's cron controller `index.php cron`
#   and our provisioner poller `blesta_poll.php`).
#
#   For each unique outbound destination it logs (metadata ONLY):
#     - first-seen timestamp
#     - destination IP (IPv4 or IPv6)
#     - destination port
#     - reverse DNS of the destination (best-effort, cached)
#     - local process comm + user, if attributable (best-effort, via `ss`)
#
#   Goal: learn WHO Blesta talks to (license/account host, SMTP, payment APIs,
#   update mirrors, DNS, ...) so we can trust it and later build an outbound
#   allowlist.
#
# HOW IT CAPTURES  (important: this box prefers IPv6 for egress)
#   PRIMARY collector = tcpdump watching for pure TCP SYN packets (connection
#   initiations) whose SOURCE is one of THIS host's IPs. This reliably catches
#   short-lived TLS flows (license/update/SMTP) that periodic `ss` sampling
#   misses, and it works for BOTH IPv4 and IPv6. It parses tcpdump's TEXT output
#   only — it never decodes packet PAYLOAD (no -A/-X), so no secrets are read.
#   A parallel `ss` "sidecar" samples the socket table to attribute flows to a
#   process/user (best-effort) and to catch longer-lived/local sockets (e.g.
#   MySQL on loopback).
#
#   FALLBACK = if tcpdump is unavailable, it degrades to periodic `ss` sampling
#   (and then `lsof`), so it still works everywhere — just less able to catch
#   very short-lived flows.
#
# WHY IT IS SAFE / NON-DISRUPTIVE
#   - Read-only observation: tcpdump text headers + `ss`/`lsof`. No firewall
#     changes, no traffic blocking, no packet payload decoding.
#   - Never reads/touches Blesta encoded/ionCube/license files.
#   - Metadata only (IP / port / DNS). No secrets, keys, or payloads logged.
#   - Idempotent & safe to re-run: only appends to a dated log file.
#
# USAGE
#   blesta_traffic_watch.sh [minutes]              # default 10 minutes
#   SAMPLE_INTERVAL=1 blesta_traffic_watch.sh 5    # ss sidecar interval (s)
#   NO_RDNS=1 blesta_traffic_watch.sh 10           # skip reverse-DNS lookups
#   COLLECTOR=ss blesta_traffic_watch.sh 10        # force a collector
#   IFACE=eth0 blesta_traffic_watch.sh 10          # force capture interface
#
#   Run as root: tcpdump needs CAP_NET_RAW and `ss -p` needs privilege to
#   attribute sockets owned by other users (litespeed=root/nobody,
#   lsphp=falco6812).
#
# OUTPUT
#   Appends to /var/log/falcon-provisioner/blesta-outbound-YYYYMMDD.log
#   and prints a per-run SUMMARY table of unique destinations at the end.
#
set -u

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------
DURATION_MIN="${1:-10}"
DURATION_SEC=$(( DURATION_MIN * 60 ))
SAMPLE_INTERVAL="${SAMPLE_INTERVAL:-1}"        # ss sidecar sample interval (s)
NO_RDNS="${NO_RDNS:-0}"
LOG_DIR="/var/log/falcon-provisioner"
LOG_FILE="${LOG_DIR}/blesta-outbound-$(date +%Y%m%d).log"

# Process comm names that make up the Blesta web/PHP stack. "php" also covers
# the CLI cron controller (index.php cron) and our poller (blesta_poll.php).
PROC_RE='litespeed|lshttpd|lsphp|^php$'

# Local ports that mean "this socket is an INBOUND listener", used by the ss
# sidecar to avoid mislabelling inbound web/ssh traffic as outbound.
LISTEN_PORTS=" 80 443 7080 8088 8090 21 22 3306 "

mkdir -p "$LOG_DIR" 2>/dev/null || true

# --------------------------------------------------------------------------
# Discover this host's own IPs (v4 + v6) so we can tell OUTBOUND (src = us)
# from INBOUND (dst = us). Stored space-delimited for cheap membership tests.
# --------------------------------------------------------------------------
OUR_IPS=" "
if command -v ip >/dev/null 2>&1; then
    while read -r a; do OUR_IPS="${OUR_IPS}${a} "; done < <(ip -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1)
fi
OUR_IPS="${OUR_IPS}127.0.0.1 ::1 "
is_our_ip() { case "$OUR_IPS" in *" $1 "*) return 0 ;; *) return 1 ;; esac; }
is_listen_port() { case "$LISTEN_PORTS" in *" $1 "*) return 0 ;; *) return 1 ;; esac; }

# --------------------------------------------------------------------------
# Collector selection
# --------------------------------------------------------------------------
COLLECTOR="${COLLECTOR:-auto}"
if [ "$COLLECTOR" = "auto" ]; then
    if command -v tcpdump >/dev/null 2>&1; then COLLECTOR="tcpdump"
    elif command -v ss   >/dev/null 2>&1; then COLLECTOR="ss"
    elif command -v lsof >/dev/null 2>&1; then COLLECTOR="lsof"
    else echo "ERROR: no tcpdump/ss/lsof available; cannot trace." >&2; exit 1
    fi
fi

# Capture interface for tcpdump: the interface on the default route, else eth0.
if [ -z "${IFACE:-}" ]; then
    IFACE=$(ip route get 1.1.1.1 2>/dev/null | sed -n 's/.* dev \([^ ]*\).*/\1/p' | head -1)
    [ -z "$IFACE" ] && IFACE="eth0"
fi

# --------------------------------------------------------------------------
# State: dedup + caches (bash 4+ associative arrays)
# --------------------------------------------------------------------------
declare -A SEEN RDNS_CACHE USER_CACHE 2>/dev/null || true
RUN_TMP="$(mktemp /tmp/blesta_traffic_watch.XXXXXX)"
ATTR_CACHE="${RUN_TMP}.attr"     # ss sidecar: "ip<TAB>port<TAB>comm<TAB>user"
: > "$ATTR_CACHE"
SIDE_PID=""
cleanup() {
    [ -n "$SIDE_PID" ] && kill "$SIDE_PID" 2>/dev/null
    rm -f "$RUN_TMP" "$ATTR_CACHE" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

END_TS=$(( $(date +%s) + DURATION_SEC ))

# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------

# rdns <ip> -> best-effort reverse DNS, cached. Prints hostname or "-".
rdns() {
    local ip="$1" host=""
    [ "$NO_RDNS" = "1" ] && { echo "-"; return; }
    if [ -n "${RDNS_CACHE[$ip]+x}" ]; then echo "${RDNS_CACHE[$ip]}"; return; fi
    if command -v getent >/dev/null 2>&1; then
        host=$(getent hosts "$ip" 2>/dev/null | awk '{print $2; exit}')
    fi
    if [ -z "$host" ] && command -v dig >/dev/null 2>&1; then
        host=$(dig +short +time=2 +tries=1 -x "$ip" 2>/dev/null | head -1 | sed 's/\.$//')
    fi
    [ -z "$host" ] && host="-"
    RDNS_CACHE[$ip]="$host"
    echo "$host"
}

# attr_lookup <ip> <port> -> "comm/user" from the ss sidecar cache, or "-/-".
attr_lookup() {
    local hit
    hit=$(awk -F'\t' -v ip="$1" -v pt="$2" '$1==ip && $2==pt {print $3"/"$4; exit}' "$ATTR_CACHE" 2>/dev/null)
    [ -n "$hit" ] && echo "$hit" || echo "-/-"
}

# record <ip> <port> [comm] [user]
#   Logs the first time a (ip,port) destination is seen this run. Attribution is
#   best-effort: if comm not supplied, look it up from the ss sidecar cache.
record() {
    local ip="$1" port="$2" comm="${3:-}" user="${4:-}"
    local key="${ip}|${port}"
    [ -n "${SEEN[$key]+x}" ] && return
    SEEN[$key]=1
    if [ -z "$comm" ] || [ "$comm" = "-" ]; then
        local a; a=$(attr_lookup "$ip" "$port"); comm="${a%%/*}"; user="${a##*/}"
    fi
    [ -z "$comm" ] && comm="-"; [ -z "$user" ] && user="-"
    local host ts; host=$(rdns "$ip"); ts=$(date '+%Y-%m-%d %H:%M:%S')
    printf '%s | dst=%-39s port=%-5s rdns=%-40s proc=%s user=%s\n' \
        "$ts" "$ip" "$port" "$host" "$comm" "$user" | tee -a "$LOG_FILE"
    printf '%s\t%s\t%s\t%s\t%s\n' "$ip" "$port" "$host" "$comm" "$user" >> "$RUN_TMP"
}

# --------------------------------------------------------------------------
# ss sidecar: continuously map stack sockets -> comm/user into ATTR_CACHE, and
# directly record stack-attributed connections (covers loopback/long-lived).
# --------------------------------------------------------------------------
ss_sidecar() {
    while [ "$(date +%s)" -lt "$END_TS" ]; do
        ss -tnp state established state syn-sent 2>/dev/null | while read -r la pa rest; do
            case "$la" in *:*) : ;; *) continue ;; esac
            [ -z "$pa" ] && continue
            local lport="${la##*:}" rip="${pa%:*}" rport="${pa##*:}"
            rip="${rip#[}"; rip="${rip%]}"
            case "$rport" in ''|*[!0-9]*) continue ;; esac
            echo "$rest" | grep -oE '\("[^"]+",pid=[0-9]+' | while read -r tup; do
                local comm pid user
                comm=$(echo "$tup" | sed -E 's/^\("([^"]+)".*/\1/')
                pid=$(echo "$tup"  | sed -E 's/.*pid=([0-9]+).*/\1/')
                echo "$comm" | grep -qE "$PROC_RE" || continue
                if [ -n "${USER_CACHE[$pid]+x}" ]; then user="${USER_CACHE[$pid]}"; else
                    user=$(stat -c '%U' "/proc/$pid" 2>/dev/null); [ -z "$user" ] && user="-"
                    USER_CACHE[$pid]="$user"
                fi
                # Cache for tcpdump attribution.
                printf '%s\t%s\t%s\t%s\n' "$rip" "$rport" "$comm" "$user" >> "$ATTR_CACHE"
                # Directly record only if it is clearly outbound (skip inbound listeners).
                is_listen_port "$lport" && continue
                record "$rip" "$rport" "$comm" "$user"
            done
        done
        sleep "$SAMPLE_INTERVAL"
    done
}

# --------------------------------------------------------------------------
# tcpdump collector: parse TEXT output for pure SYN packets sourced by us.
#   Example lines:
#     IP  195.35.1.18.57478 > 54.68.103.101.443: Flags [S], seq ...
#     IP6 2a02:...::1.57252 > 2a06:...::2.443:  Flags [S], seq ...
#   A pure SYN ("Flags [S]," not "[S.]") from our IP = an outbound init.
# --------------------------------------------------------------------------
collect_tcpdump() {
    timeout "${DURATION_SEC}s" tcpdump -nn -l -i "$IFACE" 'tcp' 2>/dev/null | \
    while IFS= read -r line; do
        case "$line" in *"Flags [S],"*) : ;; *) continue ;; esac
        # Fields: <ts> IP|IP6 <src> > <dst>: Flags ...
        local src dst
        src=$(echo "$line" | awk '{print $3}')
        dst=$(echo "$line" | awk '{print $5}'); dst="${dst%:}"
        # Split trailing ".PORT" (works for v4 a.b.c.d.PORT and v6 xx::x.PORT).
        local sip="${src%.*}" dip="${dst%.*}" dport="${dst##*.}"
        case "$dport" in ''|*[!0-9]*) continue ;; esac
        is_our_ip "$sip" || continue      # only connections WE initiated
        is_our_ip "$dip" && continue      # skip host-to-self / loopback
        record "$dip" "$dport"
    done
}

# --------------------------------------------------------------------------
# ss-only / lsof-only fallback collectors (used when tcpdump absent).
# --------------------------------------------------------------------------
collect_ss_loop() {
    while [ "$(date +%s)" -lt "$END_TS" ]; do
        ss -tnp state established state syn-sent 2>/dev/null | while read -r la pa rest; do
            case "$la" in *:*) : ;; *) continue ;; esac
            [ -z "$pa" ] && continue
            local lport="${la##*:}" rip="${pa%:*}" rport="${pa##*:}"
            rip="${rip#[}"; rip="${rip%]}"
            is_listen_port "$lport" && continue
            case "$rport" in ''|*[!0-9]*) continue ;; esac
            echo "$rest" | grep -oE '\("[^"]+",pid=[0-9]+' | while read -r tup; do
                local comm pid
                comm=$(echo "$tup" | sed -E 's/^\("([^"]+)".*/\1/')
                pid=$(echo "$tup"  | sed -E 's/.*pid=([0-9]+).*/\1/')
                echo "$comm" | grep -qE "$PROC_RE" || continue
                record "$rip" "$rport" "$comm" "$(stat -c '%U' /proc/$pid 2>/dev/null)"
            done
        done
        sleep "$SAMPLE_INTERVAL"
    done
}
collect_lsof_loop() {
    while [ "$(date +%s)" -lt "$END_TS" ]; do
        lsof -i -nP -w 2>/dev/null | grep -E 'TCP .*->' | while read -r comm pid user _rest; do
            echo "$comm" | grep -qE "$PROC_RE" || continue
            local conn; conn=$(echo "$_rest" | grep -oE '[^ ]+->[^ ]+' | head -1)
            [ -z "$conn" ] && continue
            local peer="${conn##*->}"; local rip="${peer%:*}" rport="${peer##*:}"
            case "$rport" in ''|*[!0-9]*) continue ;; esac
            record "$rip" "$rport" "$comm" "$user"
        done
        sleep "$SAMPLE_INTERVAL"
    done
}

# --------------------------------------------------------------------------
# Run
# --------------------------------------------------------------------------
{
    echo "=================================================================="
    echo "blesta_traffic_watch START $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "  window=${DURATION_MIN}m  collector=${COLLECTOR}  iface=${IFACE}  host=$(hostname)"
    echo "  ss-sidecar interval=${SAMPLE_INTERVAL}s  attributing procs: ${PROC_RE}"
    echo "  local IPs:${OUR_IPS}"
    echo "=================================================================="
} | tee -a "$LOG_FILE"

case "$COLLECTOR" in
    tcpdump)
        ss_sidecar &            # best-effort attribution + loopback/long-lived
        SIDE_PID=$!
        collect_tcpdump         # primary: catches short-lived v4+v6 outbound
        ;;
    ss)   collect_ss_loop   ;;
    lsof) collect_lsof_loop ;;
esac

# --------------------------------------------------------------------------
# Per-run SUMMARY table of unique outbound destinations
# --------------------------------------------------------------------------
{
    echo "------------------------------------------------------------------"
    echo "SUMMARY — unique outbound destinations this run ($(date '+%H:%M:%S'))"
    echo "------------------------------------------------------------------"
    if [ -s "$RUN_TMP" ]; then
        awk -F'\t' '
            { ip=$1; port=$2; host=$3; comm=$4; user=$5;
              key=port"\t"ip"\t"host;
              tag=comm"/"user;
              if (!(key in procs) || procs[key]=="-/-") procs[key]=tag;
              else if (tag!="-/-" && index(procs[key],tag)==0) procs[key]=procs[key]","tag;
              seen[key]=1 }
            END {
              printf "%-5s %-39s %-42s %s\n","PORT","IP","RDNS","PROC/USER";
              for (k in seen){ split(k,a,"\t"); printf "%-5s %-39s %-42s %s\n", a[1],a[2],a[3],procs[k] }
            }' "$RUN_TMP" | { IFS= read -r h; printf '%s\n' "$h"; sort -n; }
    else
        echo "(no outbound connections observed this run)"
    fi
    echo "blesta_traffic_watch END $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "=================================================================="
} | tee -a "$LOG_FILE"
