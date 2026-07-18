# Falcon of Codes — Blesta Client Branding

Blesta 5.13.10 at `/home/falcon.codes/public_html/portal/` → https://falcon.codes/portal/
Site Linux user (owns web files): `falco6812`. Vhost PHP: lsphp83 (PHP 8.3) + ionCube.
Blesta DB: `falc_blesta` (root MySQL via socket). Company id: `1`.

This document describes the update-safe branding applied to the **client** interface only.
The **staff/admin** interface (logo + template + theme) was intentionally left untouched.

---

## 1. What was copied and why

The stock client template lives in:

    portal/app/views/client/bootstrap/

Blesta **overwrites** stock template directories on upgrade, so it must never be edited in place.
We created an update-safe copy:

    cp -a portal/app/views/client/bootstrap  portal/app/views/client/falcon

All branding edits live ONLY inside `falcon/`. The Falcon template is then selected as the
active client template (see section 4), so upgrades to the `bootstrap/` parent do not clobber it.

---

## 2. Files created / edited under app/views/client/falcon/

Full paths (all owned `falco6812:falco6812`, dirs 755, files 644):

- `/home/falcon.codes/public_html/portal/app/views/client/falcon/config.json`
  Template metadata. name="Falcon", author="Falcon of Codes" (url https://falcon.codes).
  This makes the template appear as "Falcon" in the admin Look-and-Feel dropdown.

- `/home/falcon.codes/public_html/portal/app/views/client/falcon/css/overrides.css`  (NEW file)
  Brand color/typography overrides only (no layout changes). Tokens mirror the homepage
  `/home/falcon.codes/public_html/styles.css` palette:
    --falcon-purple        #9354B7  (amethyst / primary accent)
    --falcon-purple-dark   #543A9C  (rebecca purple / hover)
    --falcon-purple-light  #C5A8E0  (wisteria)
    --falcon-purple-pale   #E3DAFE  (periwinkle)
    --falcon-text          #111827
    --falcon-text-muted    #6B7280
    --falcon-border        #E5E7EB
    font: "Inter", system-ui, ...
  Restyles: links, primary/info buttons + hovers + focus rings, outline buttons, active nav/tabs,
  card accent bars, form focus states, badges, pagination, progress bars, order-form accents,
  and the footer link.

- `/home/falcon.codes/public_html/portal/app/views/client/falcon/structure.pdt`  (EDITED)
  * Added, AFTER all other stylesheets (application.min.css, theme CSS, font-awesome), so it
    wins the cascade:
        <link href="<?php echo $this->view_dir;?>css/overrides.css" rel="stylesheet" ... />
    `$this->view_dir` resolves to WEBDIR . app/views/client/<active template>/ so the path is
    correct for whichever template is active.
  * Footer changed from "Powered by Blesta, © Phillips Data, Inc." to:
        © <year> Falcon of Codes  (link → https://falcon.codes/)

- `/home/falcon.codes/public_html/portal/app/views/client/falcon/client_login.pdt`  (EDITED)
  * The stock client login page sets `show_header=false` (in the core controller
    `app/controllers/client_login.php`), so the header logo is hidden on /client/login/.
    We added a centered brand logo at the top of the login card, using `$theme_logo`
    (the client logo setting) with a fallback to the public asset URL. This is a branding-only
    addition (no layout restructuring) and keeps the logo visible on the login screen.

Everything else in `falcon/` is a byte-for-byte copy of the stock `bootstrap/` template.

---

## 3. Logos

| Interface        | company_settings key | value                                                | file                     | changed |
|------------------|----------------------|------------------------------------------------------|--------------------------|---------|
| Client (public)  | `logo_client`        | `https://falcon.codes/assets/dark-falcon-logo.png`   | dark-falcon-logo.png     | YES     |
| Staff / Admin    | `logo_admin`         | `/portal/uploads/themes/asset/admin_logo.png`        | (unchanged)              | NO      |

- Client logo file: `/home/falcon.codes/public_html/assets/dark-falcon-logo.png`
  Public URL: https://falcon.codes/assets/dark-falcon-logo.png
- Why the DARK logo: the client theme header/login/order backgrounds are WHITE
  (client theme id 26 `theme_header_bg_color_top` = ffffff, page bg = ffffff), so the dark logo
  reads correctly on light backgrounds. The white logo
  (`/home/falcon.codes/public_html/assets/white-logo.png`) is reserved for dark backgrounds.
- Logo assets were chowned root:root → falco6812:falco6812.
- Blesta accepts a full URL as the logo value (the "logo URL" option in the Customize form),
  which is what we used — no need to place a file inside the Blesta uploads dir.

---

## 4. company_settings changed (company_id = 1)

| key               | old value                                       | new value                                          |
|-------------------|-------------------------------------------------|----------------------------------------------------|
| `logo_client`     | /portal/uploads/themes/asset/client_logo.png    | https://falcon.codes/assets/dark-falcon-logo.png   |
| `client_view_dir` | bootstrap                                        | falcon                                             |

`client_view_dir` = the active CLIENT interface template directory name (dir under
`app/views/client/`). `logo_admin`, `theme_admin` (25), `theme_client` (26) were NOT changed.

SQL used:
    UPDATE company_settings SET value=https://falcon.codes/assets/dark-falcon-logo.png
      WHERE `key`=logo_client AND company_id=1;
    UPDATE company_settings SET value=falcon
      WHERE `key`=client_view_dir AND company_id=1;

### Equivalent admin UI paths (Blesta staff area)
- Client logo:  Settings → Company → Look and Feel → **Customize** → **Client** tab →
  Logo → choose "Logo URL" and enter the URL (or upload a file) → Update Settings.
  (Field maps to company setting `logo_client`; height = `client_logo_height`.)
- Client template:  Settings → Company → Look and Feel → **Template** →
  **Client Template** = "Falcon" → Update Settings.  (Maps to `client_view_dir`.)
- Color theme is separate: Settings → Company → Look and Feel → **Themes**. We did NOT change
  the client color theme (still "FIVE", id 26).

After any of the above, clear cache (section 6).

---

## 5. Verify (no browser needed)

    curl -sI https://falcon.codes/portal/client/login/ | head -1          # HTTP/2 200
    curl -s  https://falcon.codes/portal/client/login/ | grep -iE "falcon|overrides.css|logo"
    curl -sIL https://falcon.codes/portal/order/ | grep -i "HTTP/" | tail -1   # HTTP/2 200
    curl -sL https://falcon.codes/portal/order/  | grep -iE "falcon|overrides.css"
    curl -sI https://falcon.codes/portal/app/views/client/falcon/css/overrides.css | head -1  # 200
    curl -sI https://falcon.codes/assets/dark-falcon-logo.png | head -1     # 200

Expected: login + order forms reference `dark-falcon-logo.png`, `.../falcon/css/overrides.css`,
and the "Falcon of Codes" footer, and all return 200 (no 500).

---

## 6. Clear Blesta cache after any change

    sudo -u falco6812 find /home/falcon.codes/public_html/portal/cache -mindepth 1 -delete

Only clears cache CONTENTS; never delete the cache dir itself or any backups.

---

## 7. Re-applying after a Blesta upgrade

Blesta upgrades refresh the stock `bootstrap/` template but leave custom template dirs alone,
so `falcon/` normally survives. After each upgrade:

1. Diff the NEW stock structure against our copy to catch upstream markup/security changes:
       diff -u portal/app/views/client/bootstrap/structure.pdt \
               portal/app/views/client/falcon/structure.pdt
   Re-apply upstream changes into `falcon/structure.pdt`, keeping our two edits:
     - the `css/overrides.css` <link> (must stay LAST among stylesheets)
     - the Falcon footer
   Do the same for `client_login.pdt` (keep the login-card logo block).
2. `css/overrides.css` is our own file and is unaffected by upgrades — it usually needs no change.
3. If the upgrade reset the active template, re-select it:
       UPDATE company_settings SET value=falcon WHERE `key`=client_view_dir AND company_id=1;
   (or UI: Look and Feel → Template → Client Template = Falcon)
4. Confirm `logo_client` still points to https://falcon.codes/assets/dark-falcon-logo.png.
5. Clear cache (section 6) and re-run the verify commands (section 5).

## 8. Rollback (if the client portal ever breaks)

    UPDATE company_settings SET value=bootstrap WHERE `key`=client_view_dir AND company_id=1;
    sudo -u falco6812 find /home/falcon.codes/public_html/portal/cache -mindepth 1 -delete

This reverts the live portal to the stock template without deleting the Falcon template.
