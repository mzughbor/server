# Blesta Outbound-Traffic Observability (Task B)

Goal: know **who** the Blesta PHP/web stack connects to (destination IP / port /
DNS), so we can trust it and later build an outbound allowlist. **Metadata only**
— no packet payloads, no secrets, no card data. Read-only observation; the
firewall is **not** changed by any of this.

- **Host:** `fpanel.falcon.codes` (195.35.1.18 / IPv6 `2a02:4780:28:4f5a::1`), AlmaLinux 9.8
- **Stack:** OpenLiteSpeed (`litespeed`/`lshttpd`) + `lsphp83` (PHP 8.3), CyberPanel
- **Blesta:** `/home/falcon.codes/public_html/portal`, site user `falco6812`
- **Watcher script:** `/opt/falcon-provisioner/bin/blesta_traffic_watch.sh`
  (mirrored in repo at `falcon-provisioner/bin/blesta_traffic_watch.sh`)
- **Log:** `/var/log/falcon-provisioner/blesta-outbound-YYYYMMDD.log`

---

## 1. Process / port inventory (Blesta stack)

Snapshot via `ss -tnp` / `lsof -i -nP` / `ps`:

| Component | Process (comm) | User | Sockets |
|---|---|---|---|
| OLS main + cgid | `litespeed` (openlitespeed main / lscgid) | `root` | listens `:80`, `:443` (TCP+QUIC/UDP), IPv4+IPv6 |
| OLS workers | `litespeed` (lshttpd #01..#08) | `nobody` | serve inbound HTTP(S) |
| PHP-FPM workers | `lsphp` (lsphp83) | `falco6812` | FastCGI for `/portal`; this is what makes Blesta's outbound calls when serving the app |
| CyberPanel daemon php | `lsphp` | `lscpd` | panel (not Blesta) |
| Provisioner poller (cron) | `php` (CLI) | `root` | `/usr/bin/php /opt/falcon-provisioner/bin/blesta_poll.php`, every minute via `/etc/cron.d/falcon-provisioner` |
| Blesta cron controller | `php` (CLI) | `falco6812` | see below |
| MySQL/MariaDB client | (from `lsphp`) | `falco6812` | outbound to `127.0.0.1:3306` (local DB) |
| **Non-Blesta baseline** | `monarx-agent` | `monarx`/root | persistent keep-alive to `54.68.103.101:443` (AWS us-west-2, Monarx security agent) |

### Blesta cron controller — exact invocation

The correct, verified invocation (run as the **site user**, from the portal dir):

```bash
cd /home/falcon.codes/public_html/portal
sudo -u falco6812 /usr/bin/php -q index.php cron
```

It runs: renewing-services, quotation transitions, plugin crons
(`domain_synchronization`, `accept_paid_orders`, `process_abandoned_orders`,
`support_manager poll_tickets` / feedback / reminders), then "all system tasks"
(which include license validation, update/version checks, and queued-email send).

> ⚠️ **Ops note (found during this task, not fixed here):** the site crontab
> (`/var/spool/cron/falco6812`) runs `/usr/bin/php -q .../portal/cron.php`, but
> **`cron.php` does not exist** in this Blesta build — the CLI entry point is
> `index.php cron`. So Blesta's scheduled cron is effectively **not running**.
> Worth fixing separately (out of scope for Task B).

---

## 2. How the watcher works (and why)

This box **prefers IPv6 for egress** (e.g. `account.blesta.com` →
`2a06:98c1:3121::2`). Periodic `ss`/`lsof` sampling reliably **misses** Blesta's
outbound calls because they are short-lived TLS flows that open and close between
samples. So the watcher uses:

- **Primary collector — `tcpdump` (already installed).** It watches for pure TCP
  **SYN** packets (connection initiations) whose *source* is one of this host's
  IPs — i.e. connections *we* initiate — for both **IPv4 and IPv6**. It parses
  only tcpdump's **text headers** (no `-A`/`-X`), so it never decodes payload.
  This catches the short-lived license/update/SMTP flows that sampling misses.
- **`ss` sidecar (parallel).** Samples the socket table every second to attribute
  a flow to a process/user (best-effort) and to catch longer-lived / loopback
  sockets (e.g. MySQL on `127.0.0.1:3306`). Attribution is best-effort: sub-100ms
  TLS handshakes to Cloudflare often complete between samples, so `proc` may show
  `-`.
- **Fallback.** If `tcpdump` is absent it degrades to `ss` sampling, then `lsof`,
  so the script always works.

Safety: read-only; no firewall changes; never reads Blesta encoded/ionCube/
license files; logs metadata only (IP / port / DNS).

### Run it

```bash
# 10-minute window (default)
/opt/falcon-provisioner/bin/blesta_traffic_watch.sh

# custom window (minutes)
/opt/falcon-provisioner/bin/blesta_traffic_watch.sh 5

# options
SAMPLE_INTERVAL=1 /opt/falcon-provisioner/bin/blesta_traffic_watch.sh 5   # ss sidecar interval
NO_RDNS=1        /opt/falcon-provisioner/bin/blesta_traffic_watch.sh 10   # skip reverse DNS
COLLECTOR=ss     /opt/falcon-provisioner/bin/blesta_traffic_watch.sh 10   # force collector
```

Run as **root** (tcpdump needs `CAP_NET_RAW`; `ss -p` needs privilege to attribute
other users' sockets). Output appends to
`/var/log/falcon-provisioner/blesta-outbound-YYYYMMDD.log` and a SUMMARY table is
printed at the end of each run.

### Generate real Blesta traffic during the window (no browser)

```bash
# Blesta cron (license/update checks, queued mail) — as the site user
( cd /home/falcon.codes/public_html/portal && sudo -u falco6812 /usr/bin/php -q index.php cron )
# our provisioner poller
/usr/bin/php /opt/falcon-provisioner/bin/blesta_poll.php
# public pages (these are INBOUND to :443 and are correctly filtered out)
curl -s -o /dev/null https://falcon.codes/portal/{,admin/,order/,client/login/}
```

> **Important:** Blesta **caches** license validation and update checks, so
> re-running cron usually does **not** reconnect to `account.blesta.com` every
> time. To *deterministically* surface the license/account host without touching
> license files, exercise the public endpoint through the stack:
> `sudo -u falco6812 php -r '@file_get_contents("https://account.blesta.com/");'`

---

## 3. Findings — unique outbound destinations observed

Observation windows on 2026-07-18 (21:44–21:55 UTC) while running cron + poller +
page curls + a deterministic touch of Blesta's account host.

| Dest IP (observed) | Port | Host (fwd-resolved) | Likely purpose | Attributed to |
|---|---|---|---|---|
| `2a06:98c1:3121::2` (also `2a06:98c1:3120::2`; IPv4 `188.114.96.2`, `188.114.97.2`) | 443/TCP | **account.blesta.com / blesta.com** (Cloudflare) | **Blesta license validation, update/version check, account/marketplace** | php / lsphp (site `falco6812`) — stack-initiated |
| `2a06:98c1:3120::4` / `::1` (IPv4 `104.21.17.204`, `172.67.178.74`) | 443/TCP | **www.blesta.com** (Cloudflare) | Blesta docs / marketplace / update assets | php stack |
| `127.0.0.1` | 3306/TCP | localhost | Blesta **database** (MariaDB/MySQL) — *local, not external* | lsphp (`falco6812`) |
| `153.92.2.6`, `1.1.1.1`, `8.8.4.4` | 53 UDP/TCP | Hostinger / Cloudflare / Google resolvers | **DNS** resolution (`/etc/resolv.conf`) | system resolver |
| `54.68.103.101` | 443/TCP | AWS us-west-2 | **NON-Blesta**: Monarx security agent (baseline, filter out) | `monarx-agent` |

**Not observed this window (expected — see notes):**

- **SMTP / mail** (25 / 465 / 587): no queued outbound mail was sent externally
  during the window. Blesta mail may be local (`sendmail`/PHP mail) or an external
  SMTP relay — confirm from Blesta → Settings → Company → Mail before locking down.
- **Payment gateway APIs** (e.g. Stripe `api.stripe.com:443`, PayPal): none
  exercised — no order was paid. These only fire on real transactions.

**Interpretation:** Blesta's only *external* dependency surfaced during normal
operation is **the Blesta account/license host (`*.blesta.com`, behind
Cloudflare) over 443**, plus **DNS**. Everything else the stack did was **local**
(MySQL on loopback, serving inbound web requests). The provisioner poller talks
only to the local `falcon.codes` install (itself) and does not egress externally.

---

## 4. Recommended outbound allowlist (for LATER — do NOT apply now)

When we're ready to lock down egress for the Blesta stack, allow:

| Destination | Port | Why |
|---|---|---|
| `account.blesta.com`, `blesta.com`, `www.blesta.com` | 443/TCP | License validation, update checks, marketplace. **Cloudflare-fronted → IPs rotate.** |
| DNS resolvers `153.92.2.6`, `1.1.1.1`, `8.8.4.4` | 53 UDP+TCP | Name resolution |
| `127.0.0.1` | 3306/TCP | Local database (always allow) |
| Configured SMTP relay host | 25/465/587 | Only if Blesta uses external SMTP — confirm first |
| Configured payment-gateway API host(s) | 443/TCP | e.g. `api.stripe.com`, PayPal — per enabled gateway |
| Monarx agent host (`54.68.103.101` / its domain) | 443/TCP | Keep if the security agent is intended |

**How to implement safely:**

- `*.blesta.com` and payment APIs sit behind Cloudflare/CDNs whose IPs **change**,
  so **do not** hard-code today's IPs. Prefer either:
  - a **hostname-based egress policy** (proxy / `firewalld`/`nftables` set refreshed
    from DNS on a timer), or
  - allowing the relevant **Cloudflare ranges** (`104.16.0.0/13`, `172.64.0.0/13`,
    `188.114.96.0/22`, `2a06:98c1::/29`, …) for 443 — broader but stable.
- **Default-deny is risky** for a billing app: a missed gateway/SMTP host breaks
  payments or email silently. Recommended rollout:
  1. Add `nftables`/`firewalld` **egress LOG-only** rules first (log, don't drop)
     and run this watcher + real transactions for a few days to catch SMTP and
     every payment gateway.
  2. Only then switch to allow-list + drop, keeping DNS + loopback + the confirmed
     hosts above open.
- Note this host prefers **IPv6 egress** — any allowlist must cover **both**
  IPv4 and IPv6 for each host.

---

## 5. Files

- Watcher: `/opt/falcon-provisioner/bin/blesta_traffic_watch.sh` (repo: `falcon-provisioner/bin/blesta_traffic_watch.sh`)
- Log: `/var/log/falcon-provisioner/blesta-outbound-YYYYMMDD.log` (server only — **never** committed)
- This doc: `/srv/falcon-docs/blesta-traffic.md` (repo: `docs/blesta-traffic.md`)
