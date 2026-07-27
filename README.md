# Power Sweeper

Normalize and clean Power Apps canvas `.msapp` files with an ordered **hop** pipeline.

Drop an app, choose operations (or load a profile preset), reorder the sequence, run, then download the cleaned `.msapp` and a change report.

## Local path

Canonical install: **`/srv/http/power_sweeper`** (same layout as sweeper/convert under `/srv/http`).

## Quick start

```bash
cd /srv/http/power_sweeper
composer install
php -S localhost:8080 router.php
```

Open [http://localhost:8080](http://localhost:8080).

With Apache serving `/srv/http` (like convert/sweeper), use [http://localhost/power_sweeper/](http://localhost/power_sweeper/) — `.htaccess` routes `/`, `/assets/*`, and `/api/*`.

### Large `.msapp` uploads

Real apps (VCR / VCDS) often exceed PHP’s default **2M** upload cap. This repo sets **256M** via [`.htaccess`](.htaccess) (mod_php) and [`.user.ini`](.user.ini) (php-fpm). If you still see `Upload failed with error code 1` or `Missing msapp upload`:

1. Confirm limits: open `/power_sweeper/api/run.php` — check `upload_limits`
2. Restart Apache / php-fpm after pulling
3. Or set in `/etc/php/*/apache2/php.ini` (or fpm pool): `upload_max_filesize=256M` and `post_max_size=256M`

If storage is not writable by Apache (`http`), run:

```bash
sh scripts/fix_permissions.sh
```

### Studio import “Unknown error” after cleaning

If a plain `.msapp` imports but a cleaned one fails with a generic Studio error, check (in order):

1. **JSON empty objects** — PHP `json_decode($raw, true)` turns Studio `{}` into `[]` (e.g. `OverridableProperties`). Control JSON is now decoded as objects and written with `StudioJson`.
2. **YAML rewrite shape** — quoted Power Fx like `Fill: '=…'` or broken `Children` lists. Src YAML is dumped via `PowerAppsYaml`.
3. **ZIP host metadata** — Linux `ZipArchive` Unix stamps; packs use a DOS-compatible writer for Windows-style `.msapp`.

See [`samples/import_debug/from_plain/HOW_TO_TEST.txt`](samples/import_debug/from_plain/HOW_TO_TEST.txt) for a bisect pack built from a known-good plain app.

## How it works

1. Upload an `.msapp` (ZIP archive of canvas sources).
2. Build a hop sequence — or pick a **profile** to fill one.
3. Power Sweeper unpacks the archive, edits `Src/**/*.pa.yaml` (and control JSON when present), repacks, and returns the file plus a report.

Order matters: the same hops in a different sequence can produce different results.

## Built-in hops

| Hop | Purpose |
|-----|---------|
| `normalize_containers` | Clear default drop shadow, border, radius, and padding on containers |
| `accessibility_labels` | Fill missing `AccessibleLabel` from Text / Tooltip / name |
| `align_near_miss` | Snap sibling X/Y/Width/Height values off by a few pixels |
| `strip_default_fill` | Clear opaque white container fills |
| `normalize_classic_button_chrome` | Clear Hover/Pressed fills when Fill is already transparent |
| `tooltip_from_label` | Copy Text / AccessibleLabel into empty Tooltip |
| `unwhack_locale_formulas` | Repair comma-decimal / `;` list-separator corruption (e.g. after switching authoring language to German), including internal `InvariantScript` the formula bar may not expose |
| `enable_dark_mode` | Inject `gblThemeLight` / `gblThemeDark` / `gblTheme` palettes, add or reuse a dark-mode toggle, and point literal colors at `gblTheme.*` tokens |
| `correlate_sharepoint` | Correlate SharePoint datasources/connections with a list schema (or patterns learned from the package), flag bad connections, and repair list/column typos in metadata + formulas |
| `set_zip_path_style` | Force zip entry separators to `windows` (`\\`) or `posix` (`/`). Default is to **preserve** the source style (almost always Windows) |

## Profiles

PHP files in [`profiles/`](profiles/) return a description and ordered hop list (same idea as sweeper profiles). Examples: `default`, `containers_only`, `a11y_pass`, `transparent_buttons`, `unwhack_locale`, `repair_studio_errors`, `dark_mode`, `sharepoint_correlate`, `posix_zip_paths`, `windows_zip_paths`.

For apps like **CDLS VCR** / **VCDS THCEE**, run **two separate passes** (do not combine into one profile):

1. Profile **`repair_studio_errors`** → download → open/save in Studio if you want to verify checker cleanup  
2. Profile **`dark_mode`** on that cleaned `.msapp` → download → open/save in Studio, use the Dark mode toggle  

Or in the UI: load `repair_studio_errors`, run; then load `dark_mode` on the result. You can also build a custom hop sequence by adding hops from both profiles yourself — order should still be repair hops first, then `enable_dark_mode`.

### Locale unwhack

When an app is edited under a comma-decimal locale (German, French, …), Studio can persist locale separators into formulas — including classic JSON rules you cannot open in the formula bar. The `unwhack_locale` profile converts those back to invariant Power Fx (`.` decimal, `,` list separator, `;` chaining) across `Src/**/*.pa.yaml` and control JSON `InvariantScript`.

### Dark mode

The `dark_mode` profile builds an **editable central palette** instead of hard-coding `If(gblDarkMode, …)` / RGBA on every control:

1. `App.OnStart` gets `gblThemeLight` / `gblThemeDark` records (tokens like `Page`, `Surface`, `Text`, `Accent`, …) and `Set(gblTheme, gblThemeLight)`
2. Toggle sets `gblDarkMode` and swaps `gblTheme` between the two palettes
3. Literal fills/text/borders become `gblTheme.Surface`, `gblTheme.Text`, etc.

**Where to edit colors**

| Who | Where |
|-----|--------|
| App maker (after clean) | `App.OnStart` → `gblThemeLight` / `gblThemeDark` only |
| Operator / brand defaults | [`config/theme_defaults.php`](config/theme_defaults.php) or hop `theme_defaults` / `theme_defaults_file` options |

Open the cleaned `.msapp` in Studio, save once, then use the toggle.

### SharePoint correlate

Upload an optional SharePoint schema JSON (UI field or hop option `schema` / `schema_file`) shaped like:

```json
{
  "lists": [
    {
      "name": "Requests",
      "siteUrl": "https://contoso.sharepoint.com/sites/App",
      "columns": ["Title", "Status", "RequestNumber"]
    }
  ]
}
```

The `sharepoint_correlate` profile then:

1. Reads `References/DataSources.json`, `DataSources/*`, `Connections/*`, and `pkgs/TableDefinitions/*` from the `.msapp`
2. Flags bad/empty SharePoint connections and lists/columns missing vs the schema
3. Fuzzy-matches typos (`Reqeusts` → `Requests`, `Statu` → `Status`) using patterns from the schema **or** the app’s own table definitions
4. Repairs datasource metadata and formula references when `repair` is true (default), with every finding/fix in the sweep report

Hop options: `repair` (bool), `max_distance` (int, default 2), `repair_site_url` (bool, default false), `lists` / `schema` / `schema_file`.

### Samples (local only — not published to GitHub)

`.msapp` packages under `samples/` and `import/` are **gitignored**. Rebuild synthetic fixtures locally when needed:

```bash
php samples/dark_mode_kitchen_sink/build.php --with-dark-mode
php samples/locale_german_corrupt/build.php --with-unwhack
```

Cleaned customer apps for Studio import (ASCII names, Studio-compatible Windows zip paths):

- `~/Downloads/power_sweeper_import/`
- `/srv/http/power_sweeper/import/`

Import with **make.powerapps.com → Apps → Import app → From file (.msapp)** and pick a **local** file (avoids remote download/network blocks). Then **Save** once into your environment.

### Repair Studio errors (VCR-class apps)

Profile **`repair_studio_errors`** is the pass to use on apps like CDLS VCR after a language/region switch. It runs:

1. `unwhack_locale_formulas` — Expected operator, Invalid number of arguments (Size/Orientation), ParseJSON / If / LookUp separator damage, including internal JSON  
2. `repair_checked_booleans` — “Expecting a true or false value” on checkbox/toggle `Checked`/`Default` (`1`/`0`/`"true"`)  
3. `accessibility_labels` — missing AccessibleLabel  
4. `ensure_focus_visible` — App checker “Focus isn’t showing”  
5. `tooltip_from_label` — empty tooltips  

**Not auto-fixed** (different App checker categories): SharePoint **delegation** warnings, **unused variables/media**, missing Power Automate **Run** targets when the flow isn’t in the app.

## Tests

```bash
php tests/run_tests.php
```

## Notes

- Import cleaned apps via **Apps → Import app → From file (.msapp)** (local file picker), then **Save** once. Prefer `~/Downloads/power_sweeper_import/` copies (ASCII filenames).
- Packed `.msapp` files **preserve** the source zip path style (almost always Windows `\\`). Use profile **`posix_zip_paths`** (or hop `set_zip_path_style`) only if you intentionally want forward slashes.
- Only hop-owned properties are changed; media, connections, and unrelated metadata are left alone.
- Prefer editing apps you can re-save in Studio; treat this as a companion cleanup tool, not a full source-control substitute.
