# Blesta customizations (portal-custom)

This folder contains **only** our Blesta customizations, never the licensed core.
Tracked artifacts:

```
app/views/client/falcon/                                  # custom client theme (cloned from bootstrap)
plugins/falcon_theme_snapshots/                           # theme snapshot plugin (Phase 1: schema + skeleton)
plugins/order/views/templates/standard/config.pdt         # order-form prefill override (see below)
plugins/order/views/templates/standard/_falcon_prefill.snippet.html  # the appended block (re-apply source)
```

## Order-form subdomain/theme capture (source=blesta_field)
Goal: the customer's homepage choice (subdomain + theme) lands natively in Blesta
`service_options` at checkout, so `blesta_poll.php` reads it as the PRIMARY source
(`[source=blesta_field]`) instead of the `bridge.json` heuristic fallback.

How it works:
1. **DB flags** — the two configurable options `subdomain` (package_options.id 13)
   and `theme` (id 14) in the "Trial Site Access" group are set
   `addable=1, hidden=0, editable=0` (kept `disable_pricing=1, hide_on_invoice=1`,
   so no price shows and nothing hits the invoice). Only `addable=1` is what makes
   them render on the order **config** step and be captured to `service_options`
   on checkout (Blesta's order config step filters options by `addable=1`; it does
   NOT consult `hidden` or the option-group flags). The other credential fields
   stay `hidden=1, addable=0` (poller-written, display-only).
   Reproducible + idempotent via `bin/setup_blesta_options.sh` (per-field flags).
2. **Prefill JS** — `config.pdt` has an appended `<script>` (see
   `_falcon_prefill.snippet.html`). It reads `falcon_subdomain` / `falcon_theme` /
   `falcon_token` from `sessionStorage` (set on the same-origin homepage), falling
   back to the top-level URL query params (`?subdomain=&theme=&token=`), fills the
   `configoptions[13]` / `configoptions[14]` inputs, makes them readonly, and hides
   those rows so checkout stays clean. Config options load via AJAX into
   `.package_options`, so the script uses a MutationObserver + interval to re-apply.
3. **Fallback kept** — `bridge.json` still works; the poller prefers blesta_field.

### Re-apply after a Blesta / order-plugin update (NOT upgrade-safe)
The order plugin's `standard` template is licensed core and gets overwritten on
update. To re-apply:
```
CFG=/home/falcon.codes/public_html/portal/plugins/order/views/templates/standard/config.pdt
grep -q "Falcon of Codes prefill (BEGIN)" "$CFG" || {
  cp -a "$CFG" "$CFG.falcon.bak.$(date +%Y%m%d%H%M%S)"
  cat portal-custom/plugins/order/views/templates/standard/_falcon_prefill.snippet.html >> "$CFG"
  chown falco6812:falco6812 "$CFG"
}
```
(Or just copy the mirrored `portal-custom/.../standard/config.pdt` over the live
file if the surrounding template is unchanged.) Re-run
`bin/setup_blesta_options.sh` if the DB option flags were reset. If Blesta ever
changes the option IDs (13/14), update `OPTS` in the snippet to match
`package_options.id` for `subdomain`/`theme`.

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
These are captured at order time (see "Order-form subdomain/theme capture" above)
and live in the "Trial Site Access" configurable-option group; the poller also
writes the WP + FTP credentials back into that group so they render in the client
service page.

## Homepage order-form handoff
`falcon-codes/script.js` reserves the subdomain, stores `falcon_subdomain` /
`falcon_theme` / `falcon_token` in `sessionStorage`, posts a `bridge.json` entry,
and builds a **direct config URL** (skips package selection):

```
/portal/order/config/index/trial/?group_id=2&pricing_id=4&subdomain=…&theme=…&auto=1
```

`auto=1` triggers the prefill script to auto-submit the config form → item lands
in cart with subdomain/theme prefilled. Package/pricing IDs live in
`falcon-codes/packages.json` (generated by `setup_packages.sh`).

Paid plans use the same flow with `pricing_id` from `packages.starter|maker|pro`.

> Do NOT commit the full Blesta core, `vendor/`, `uploads/`, `cache/`, or any
> `config/database.php`. Reinstall core from the official Blesta zip (see docs/RECOVERY.md).
