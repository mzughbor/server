# Falcon Theme Snapshots

WordPress **template snapshot** system for Falcon of Codes — the homepage theme picker
and `provision.sh` restore path (Phase 3). Blesta client theme file backups remain a
secondary tab in the same plugin.

## Phase 1 (done)

Blesta plugin skeleton + tables for Blesta theme file snapshots (`falcon_theme_snapshots`,
`falcon_theme_snapshot_files`).

## Phase 2 (done)

WordPress template capture, admin UI, public catalog API.

### Storage (VPS only — not in git)

```
/var/lib/falcon-provisioner/templates/{slug}/
  meta.json           # catalog metadata + active flag
  database.sql.gz     # mysqldump with {{SITE_URL}} token
  wp-content.tar.gz   # wp-content (cache excluded)
```

Setup once on the VPS:

```bash
ssh falcons 'bash -s' < falcon-provisioner/bin/setup_template_storage.sh
```

This creates the templates directory and sudoers so Blesta admin (`falco6812`) can run
`/opt/falcon-provisioner/bin/snapshot_capture.sh` as root.

### Database

**`falcon_wp_templates`** (plugin v2.0.0 — created on fresh install or upgrade from 1.x):

| Column | Notes |
|--------|-------|
| slug | Unique per company; matches storage dir name |
| staging_domain | e.g. `preview-restaurant.falcon.codes` |
| storage_dir | `/var/lib/falcon-provisioner/templates/{slug}` |
| active | Shown in public catalog when true **and** capture exists |
| last_captured | Updated after successful capture |

Blesta theme tables unchanged (secondary feature).

### Capture script

```bash
sudo /opt/falcon-provisioner/bin/snapshot_capture.sh \
  --domain preview-restaurant.falcon.codes \
  --slug restaurant \
  --name "Restaurant" \
  --description "Demo restaurant site"
```

- Exports DB via `wp db export` (live DB untouched).
- Replaces staging URLs in the **dump file** with `{{SITE_URL}}`.
- **Phase 3 restore:** import dump, then  
  `wp search-replace '{{SITE_URL}}' 'https://customer.falcon.codes' --all-tables`

### Admin UI

Blesta → **Tools → Theme Snapshots**

1. **WordPress Templates** tab — add staging domain, **Capture**, **Activate**
2. **Blesta Theme Files** tab — Phase 1 placeholder (capture UI later)

After deploying plugin **v2.0.0**, upgrade the installed plugin:
**Settings → Company → Plugins → Installed → Falcon Theme Snapshots → Upgrade**

### Catalog API

```
GET https://falcon.codes/api/themes/catalog.php
```

Returns active templates from `meta.json` files (`active: true`). Used by homepage
theme picker (Phase 2b: wire `falcon-codes/script.js` to this endpoint).

Example response:

```json
{
  "ok": true,
  "count": 1,
  "templates": [
    {
      "slug": "restaurant",
      "name": "Restaurant",
      "description": "...",
      "thumbnail_url": "https://...",
      "staging_domain": "preview-restaurant.falcon.codes",
      "preview_url": "https://preview-restaurant.falcon.codes",
      "sort_order": 0,
      "captured_at": "2026-07-20T..."
    }
  ]
}
```

## Phase 3 (not built)

- `provision.sh --template restaurant` restores snapshot on customer order
- Homepage `script.js` loads catalog API instead of hardcoded cards

## Deploy

```bash
bash falcon-provisioner/bin/sync_from_repo.sh falcons
```

Plugin code updates do **not** require reinstall; bump `config.json` version and
**Upgrade** in Blesta admin when schema changes.

## Repo layout

```
portal-custom/plugins/falcon_theme_snapshots/   # Blesta plugin
falcon-provisioner/bin/snapshot_capture.sh
falcon-provisioner/bin/setup_template_storage.sh
falcon-provisioner/web/api/themes/catalog.php
```
