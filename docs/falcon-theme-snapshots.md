# Falcon Theme Snapshots — Phase 1

Blesta plugin to snapshot the custom **Falcon** client theme before upgrades.
Branding work lives in `portal-custom/app/views/client/falcon/` (see
`docs/blesta-theme.md`). Upgrades refresh stock `bootstrap/` but can leave
`falcon/` stale relative to upstream markup — snapshots make diff/restore safer.

## Scope (Phase 1)

| Delivered | Not yet |
|-----------|---------|
| Plugin skeleton + install/uninstall | Capture from disk |
| DB tables + models | Diff vs live / vs bootstrap |
| Staff Tools nav + empty list UI | Restore / rollback actions |
| Repo mirror + sync script | Pre-upgrade cron hook |

## Data model

### `falcon_theme_snapshots`

| Column | Type | Notes |
|--------|------|-------|
| id | int PK | |
| company_id | int | Blesta company |
| label | varchar(255) | e.g. "Before 5.14 upgrade" |
| template_dir | varchar(64) | default `falcon` |
| blesta_version | varchar(32) | nullable |
| staff_id | int | creator |
| notes | text | nullable |
| file_count | int | denormalized counter |
| total_bytes | bigint | denormalized size |
| status | enum | `pending`, `complete`, `failed` |
| date_created | datetime | |
| date_updated | datetime | nullable |

### `falcon_theme_snapshot_files`

| Column | Type | Notes |
|--------|------|-------|
| id | int PK | |
| snapshot_id | int | FK → snapshots |
| relative_path | varchar(512) | e.g. `css/overrides.css` |
| content_hash | char(64) | SHA-256 |
| content | mediumtext | file body |
| file_size | int | bytes |
| is_customized | tinyint | 1 = known branding file |
| date_created | datetime | |

Unique key: `(snapshot_id, relative_path)` — also serves lookups by `snapshot_id` (Blesta names indexes from the first column; do not add a separate `snapshot_id` index).

## Install

1. Deploy: `bash falcon-provisioner/bin/sync_from_repo.sh falcons`
2. Blesta admin → **Settings → Company → Plugins → Available**
3. Install **Falcon Theme Snapshots**
4. Open **Tools → Theme Snapshots** (empty list until Phase 2 capture)

Uninstall drops both tables when the last company instance is removed.

## Phase 2 preview

- "Capture snapshot" action (reads `CUSTOMIZED_FILES` + optional full tree)
- Diff view: snapshot vs live template vs stock `bootstrap/`
- Restore selected files with confirmation + cache clear
