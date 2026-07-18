# Falcon of Codes — Disaster Recovery / Rebuild Guide

This repo is the **"how to rebuild"** source of truth. It intentionally does **not**
contain secrets, the full Blesta core, WordPress core, or customer data. Those are
restored separately (see "Where secrets live" below).

Server: CyberPanel + OpenLiteSpeed VPS (`falcon.codes`, root@195.35.1.18).
Site Linux user: `falco6812`. Vhost PHP: lsphp83 (PHP 8.3) + ionCube.

--------------------------------------------------------------------------------
## Repo layout
--------------------------------------------------------------------------------
```
falcon-codes/          Live homepage (index.html, script.js, styles.css, assets/)
                       -> deploys to /home/falcon.codes/public_html/
falcon-provisioner/    Mirror of /opt/falcon-provisioner
  bin/                 provision.sh, blesta_poll.php, setup_blesta_options.sh, smoke_test.sh,
                       sync_from_repo.sh, sync_to_repo.sh
  templates/           meta.json (theme_id -> wp slug), blesta_options.json (Blesta field map)
  web/api/             SOURCE of the subdomain API -> deploys to public_html/api/
  README.txt           Full architecture + commands + teardown
portal-custom/
  app/views/client/falcon/   The update-safe Blesta CLIENT theme (only our customization)
  NOTES.md                   CMS/order-form snippets, logo URLs, settings checklist
docs/                  blesta-theme.md, provisioner.md, RECOVERY.md (this file)
.gitignore             Secret/backups/core exclusions
.env.example           Placeholder env; real secrets stay on the server
```

--------------------------------------------------------------------------------
## 1. Restore the homepage
--------------------------------------------------------------------------------
```bash
rsync -a falcon-codes/  falcon-vps:/home/falcon.codes/public_html/
ssh falcon-vps 'chown -R falco6812:falco6812 /home/falcon.codes/public_html/{index.html,script.js,styles.css,assets}'
# verify
curl -sI https://falcon.codes/ | head -1     # HTTP/2 200
```
(Do NOT let this delete `portal/`, `api/`, or child-site dirs — the sync scripts exclude them.)

--------------------------------------------------------------------------------
## 2. Reinstall Blesta + re-apply our theme
--------------------------------------------------------------------------------
Blesta core is licensed and NOT in git. Reinstall from the official zip:
1. Upload the licensed Blesta zip to the server, extract `blesta/` contents into
   `/home/falcon.codes/public_html/portal/`, uploads dir OUTSIDE webroot
   (`/home/falcon.codes/uploads/`). Ensure ionCube is loaded (lsphp83 has it).
2. Run the web installer (or CLI) with DB `falc_blesta` (restore DB from a private
   dump if you have one; schema otherwise created fresh by installer).
3. Re-apply our client theme:
   ```bash
   rsync -a portal-custom/app/views/client/falcon/ \
     falcon-vps:/home/falcon.codes/public_html/portal/app/views/client/falcon/
   ssh falcon-vps 'chown -R falco6812:falco6812 /home/falcon.codes/public_html/portal/app/views/client/falcon'
   ```
4. Activate theme + re-apply company/logo/order settings:
   ```bash
   ssh falcon-vps 'bash /opt/falcon-provisioner/bin/setup_blesta_options.sh'
   # or manually set company_settings: client_view_dir=falcon,
   #   logo_client=https://falcon.codes/assets/dark-falcon-logo.png
   ```
   See `docs/blesta-theme.md` for the exact keys and admin UI paths.

### After a Blesta UPGRADE (theme survival)
- Our overrides live in `falcon/css/overrides.css` — these normally survive upgrades.
- If Blesta changed the parent `bootstrap/` structure, re-diff **only** `structure.pdt`
  and `client_login.pdt` against the new `bootstrap/` versions, re-apply our small edits
  (overrides.css link after all other CSS; login-card logo; footer). Do not edit bootstrap.
- If the client template resets, re-activate: `client_view_dir=falcon` (setup_blesta_options.sh).

--------------------------------------------------------------------------------
## 3. Restore the provisioner
--------------------------------------------------------------------------------
```bash
rsync -a --exclude 'secrets' falcon-provisioner/bin falcon-provisioner/templates \
  falcon-provisioner/README.txt  falcon-vps:/opt/falcon-provisioner/
ssh falcon-vps 'chmod +x /opt/falcon-provisioner/bin/*.sh /opt/falcon-provisioner/bin/*.php'
# state dirs + log
ssh falcon-vps 'mkdir -p /var/lib/falcon-provisioner/secrets && chown -R falco6812:falco6812 /var/lib/falcon-provisioner && chmod 2750 /var/lib/falcon-provisioner && touch /var/log/falcon-provisioner.log'
# cron
ssh falcon-vps 'cp /opt/falcon-provisioner/falcon-provisioner.cron /etc/cron.d/falcon-provisioner 2>/dev/null; systemctl restart crond'
```

--------------------------------------------------------------------------------
## 4. Redeploy the subdomain API
--------------------------------------------------------------------------------
The API SOURCE lives in `falcon-provisioner/web/api/`. Deploy to the webroot:
```bash
rsync -a falcon-provisioner/web/api/  falcon-vps:/home/falcon.codes/public_html/api/
ssh falcon-vps 'chown -R falco6812:falco6812 /home/falcon.codes/public_html/api'
# verify
curl -s "https://falcon.codes/api/subdomain/check?name=janebakery"   # {"available":true,...}
```

--------------------------------------------------------------------------------
## 5. Recreate Blesta trial product + auto-activate settings
--------------------------------------------------------------------------------
```bash
ssh falcon-vps 'bash /opt/falcon-provisioner/bin/setup_blesta_options.sh'
```
This is idempotent: it ensures the $0 "Trial placeholder" package, the "Trial Signup"
order form with manual_review/captcha/tos OFF, and the custom order fields
(`subdomain`, `theme`) + the client-visible "Trial Site Access" configurable-option group.

--------------------------------------------------------------------------------
## 6. Where secrets live (NOT in git)
--------------------------------------------------------------------------------
| Secret | Location on server | Perms |
|--------|--------------------|-------|
| Blesta API user/key | `/root/.blesta_api_key` | 600 |
| Blesta DB creds | `/root/.blesta_db_credentials` | 600 |
| Blesta admin login | `/root/.blesta_admin_credentials` | 600 |
| Per-site WP + FTP creds | `/var/lib/falcon-provisioner/secrets/<sub>.env` | 600 |
| ClientExec backups (pre-Blesta) | `/srv/clientexec/` | root |
| Live DB (customer data) | MariaDB `falc_blesta` | — |

Recommended: keep an **encrypted** private backup of `/root/.*credentials`,
`/var/lib/falcon-provisioner/`, and a `mysqldump` of `falc_blesta` somewhere off-box.
See `.env.example` for the shape of these values.

--------------------------------------------------------------------------------
## 7. Leftover test subsites (as of last update)
--------------------------------------------------------------------------------
Live test WordPress subsites exist and are safe to remove when desired
(kept intentionally as proof): `demo-test`, `p4test`, `proof200345`, `proof200952`,
`mah`, `asdasdasd`. `falcondemo1` is the kept demo.

Teardown for one site (do NOT run unless retiring it):
```bash
ssh falcon-vps 'cyberpanel deleteChild --childDomain <sub>.falcon.codes'
ssh falcon-vps 'mysql -e "DROP DATABASE IF EXISTS wp_<sub>; DROP USER IF EXISTS \"wp_<sub>\"@\"localhost\";"'
ssh falcon-vps 'mysql cyberpanel -e "DELETE FROM users WHERE user=\"<sub>\";"'   # pure-ftpd user
ssh falcon-vps 'rm -f /var/lib/falcon-provisioner/secrets/<sub>.env'
# then remove its entry from /var/lib/falcon-provisioner/sites.json
```

--------------------------------------------------------------------------------
## 8. Do NOT commit
--------------------------------------------------------------------------------
Full Blesta core, WordPress core, `portal/uploads`, cache, any `*.env`,
`config/database.php`, `wp-config.php`, `/root/.*` secrets, ClientExec backups,
DB dumps with customer PII. The `.gitignore` enforces these.
