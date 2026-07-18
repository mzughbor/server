# Falcon Provisioner (Phase 4) — operator quick reference

Full docs: `/opt/falcon-provisioner/README.txt`

## Flow
Homepage → subdomain check/reserve API → Blesta $0 trial order → cron poller →
`provision.sh` → live WordPress on `<sub>.falcon.codes` (SSL, chosen theme).

No Softaculous. CyberPanel CLI + WP-CLI only.

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
| Theme map | `/opt/falcon-provisioner/templates/meta.json` |
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
# remove its record from /var/lib/falcon-provisioner/sites.json
```

## Disable pipeline
```
rm -f /etc/cron.d/falcon-provisioner
```

## Constraints
No Softaculous. Don't touch `/srv/clientexec/*` or `portal.clientexec.disabled`.
Don't change the Blesta `falcon` client branding. Don't revoke `/root/.blesta_api_key`.
