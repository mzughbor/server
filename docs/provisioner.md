# Falcon Provisioner (Phase 4 / 4b) — operator quick reference

Full docs: `/opt/falcon-provisioner/README.txt`

## Flow
Homepage → subdomain check/reserve API → Blesta $0 trial order (auto-activates,
zero staff clicks) → cron poller → `provision.sh` → live WordPress on
`<sub>.falcon.codes` (SSL, chosen theme) + per-site FTP/FTPS account → creds
written to the Blesta service (client area) and emailed.

No Softaculous. CyberPanel CLI + WP-CLI + pure-ftpd MySQL backend only.

## Phase 4b additions (creds in client area + auto-activate)
- **Auto-activate:** `$0` trial completes Active with no review/approval/fraud/
  email-verification gate. Enforced by `setup_blesta_options.sh` (idempotent).
  Keys: `order_forms.manual_review=0` (form id 1), `packages.manual_activation=0`
  (pkg 4), `company_settings.email_verification=false`,
  `company_settings.prevent_unverified_payments=false`,
  `order_settings.antifraud` removed. Blesta system cron (`index.php cron`, every
  5 min via `/etc/cron.d/falcon-provisioner`) runs `provision_pending_services`
  so paid $0 services flip Active without staff. Falcon poller cron provisions
  any Active pkg-4 service every minute.
- **Per-site account = pure-ftpd VIRTUAL FTP/FTPS user** chrooted to the site
  docroot, mapped to unprivileged uid/gid `falco6812` (5003). NOT root, NOT the
  CyberPanel admin. (CyberPanel has no per-customer :8090 panel login for a child
  domain, and its `createFTPAccount` CLI is broken for child domains.) Written
  straight into `cyberpanel.users` (the same backend pure-ftpd uses,
  `MYSQLCrypt=crypt`, `domain_id` = master website id). Login: FTP/FTPS explicit
  TLS to `<sub>.falcon.codes:21`.
- **Client-area creds:** shown under Client area → Services → (service) →
  "Configurable Options" in a config-option group assigned to pkg 4.
  Field→`option_pricing_id` map: `/opt/falcon-provisioner/templates/blesta_options.json`.
  The poller upserts `service_options` rows on provision (site_url, wp_admin_url,
  wp_user, wp_pass, ftp_access, ftp_user, ftp_pass). These 7 stay `hidden=1,
  addable=0` (display-only, poller-written).
- **Custom order fields (bulletproof matching):** `subdomain` + `theme` are
  `addable=1, hidden=0` config options (ids 13/14) on pkg 4, so the customer's
  homepage choice is captured NATIVELY into `service_options` at checkout (the
  order template's prefill JS fills + hides them — see `portal-custom/NOTES.md`).
  The poller then reads them as the PRIMARY source (`[source=blesta_field]` in
  the log); `bridge.json`/token remains the fallback.
- One-time setup / re-sync: `bash /opt/falcon-provisioner/bin/setup_blesta_options.sh`

## API endpoints (served by OLS as user `falco6812`)
- `GET  https://falcon.codes/api/subdomain/check?name=NAME`
- `POST https://falcon.codes/api/subdomain/reserve` (body `name=NAME`)
- `POST https://falcon.codes/api/subdomain/bridge` (body `token,subdomain,theme[,email]`)

Slug rule: `^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$` (3–32 chars).
Reserved words rejected (see README §2). Reservation TTL: 45 min.

## Key paths
| Purpose | Path |
|---|---|
| Provisioner | `/opt/falcon-provisioner/bin/provision.sh` |
| Poller | `/opt/falcon-provisioner/bin/blesta_poll.php` |
| Blesta option setup | `/opt/falcon-provisioner/bin/setup_blesta_options.sh` |
| Theme map | `/opt/falcon-provisioner/templates/meta.json` |
| Config-option map | `/opt/falcon-provisioner/templates/blesta_options.json` |
| Reservations | `/var/lib/falcon-provisioner/reservations.json` |
| Bridge | `/var/lib/falcon-provisioner/bridge.json` |
| Provisioned sites | `/var/lib/falcon-provisioner/sites.json` |
| Temp WP passwords | `/var/lib/falcon-provisioner/secrets/<sub>.env` (root 600) |
| Log | `/var/log/falcon-provisioner.log` |
| Cron | `/etc/cron.d/falcon-provisioner` (every minute) |

## Manual provision
```
/opt/falcon-provisioner/bin/provision.sh \
  --subdomain mysite --theme cafe --email owner@example.com --client_id 3
```

## Teardown one site
```
cyberpanel deleteChild --childDomain <sub>.falcon.codes
mysql -e "DROP DATABASE IF EXISTS <db>;"
mysql -e "DROP USER IF EXISTS '<dbuser>'@'localhost';"
mysql cyberpanel -e "DELETE FROM users WHERE User='<sub>';"   # pure-ftpd FTP acct
rm -f /var/lib/falcon-provisioner/secrets/<sub>.env
# remove its record from /var/lib/falcon-provisioner/sites.json
```

## Disable pipeline
```
rm -f /etc/cron.d/falcon-provisioner
```

## Constraints
No Softaculous. Don't touch `/srv/clientexec/*` or `portal.clientexec.disabled`.
Don't change the Blesta `falcon` client branding. Don't revoke `/root/.blesta_api_key`.
