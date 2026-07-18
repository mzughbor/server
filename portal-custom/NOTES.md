# Blesta customizations (portal-custom)

This folder contains **only** our Blesta customizations, never the licensed core.
The only tracked artifact is the custom client theme:

```
app/views/client/falcon/     # cloned from bootstrap for update-safety
```

## What we changed in the theme
- `config.json`      — name "Falcon", author "Falcon of Codes".
- `css/overrides.css`— NEW file: brand colors/buttons/links derived from the homepage
                       `styles.css` tokens. Loaded LAST so it wins.
- `structure.pdt`    — links `overrides.css` after all other stylesheets; footer text/link.
- `client_login.pdt` — Falcon logo added to the login card.

Everything else under `falcon/` is an unmodified copy of `bootstrap/` (needed so the
template renders standalone). See `docs/blesta-theme.md` for the full diff + rationale.

## Activate the theme (Blesta admin or setup script)
- Settings -> Company -> Look and Feel -> Template: **Falcon**
  (DB: `company_settings.client_view_dir = falcon`)
- Or run: `bash /opt/falcon-provisioner/bin/setup_blesta_options.sh`

## Branding / settings checklist (re-apply after reinstall)
- Company name: **Falcon of Codes**  (Settings -> Company -> General)
- Client logo:  `https://falcon.codes/assets/dark-falcon-logo.png`
  (DB: `company_settings.logo_client`); white variant: `/assets/white-logo.png`
- Order type: standard; **Trial Signup** order form; template group "Falcon".
- Registration enabled; manual review / captcha / TOS gate OFF for the $0 trial.

## Public URLs
- Homepage:        https://falcon.codes/
- Client login:    https://falcon.codes/portal/client/login/
- Sign up / order: https://falcon.codes/portal/order/
- Admin:           https://falcon.codes/portal/admin/

## Custom order fields (used by the provisioner to match a subdomain/theme)
- `subdomain` — the reserved subdomain the customer claimed on the homepage.
- `theme`     — homepage theme_id; mapped to a WP theme slug via
                `falcon-provisioner/templates/meta.json`.
These are also mirrored into the client-visible "Trial Site Access" configurable
options so WP + FTP credentials render in the client service page.

## Homepage order-form handoff
`falcon-codes/script.js` builds the order URL with the reserved subdomain + theme as
query params; `falcon-provisioner/web/api/subdomain/` handles availability + reservation
(bridge.json), which `blesta_poll.php` then reconciles with the paid/active order.

> Do NOT commit the full Blesta core, `vendor/`, `uploads/`, `cache/`, or any
> `config/database.php`. Reinstall core from the official Blesta zip (see docs/RECOVERY.md).
