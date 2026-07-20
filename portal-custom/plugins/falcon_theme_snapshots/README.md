# Falcon Theme Snapshots (Blesta plugin)

Custom Blesta plugin for **Falcon of Codes**. Captures point-in-time copies of the
custom client theme (`app/views/client/falcon/`) so branding edits survive Blesta
upgrades.

## Phase 1 (this release)

- Plugin skeleton installable from Blesta **Settings → Company → Plugins**
- Database schema:
  - `falcon_theme_snapshots` — snapshot metadata (label, template, Blesta version, status)
  - `falcon_theme_snapshot_files` — per-file contents keyed by snapshot
- Models with CRUD stubs (`FalconThemeSnapshots`, `FalconThemeSnapshotFiles`)
- Staff nav entry under **Tools → Theme Snapshots** (read-only list placeholder)

Capture, diff, restore, and pre-upgrade automation are **Phase 2+**.

## Default tracked files

Defined in `FalconThemeSnapshotsModel::CUSTOMIZED_FILES`:

- `config.json`
- `css/overrides.css`
- `structure.pdt`
- `client_login.pdt`

## Deploy

From the repo root (SSH alias `falcons` or `falcon-vps`):

```bash
bash falcon-provisioner/bin/sync_from_repo.sh falcons
```

Then in Blesta admin: **Settings → Company → Plugins → Available → Falcon Theme Snapshots → Install**.

## Live path

```
/home/falcon.codes/public_html/portal/plugins/falcon_theme_snapshots/
```

Mirrored in git at `portal-custom/plugins/falcon_theme_snapshots/`.

See also: `docs/falcon-theme-snapshots.md`
