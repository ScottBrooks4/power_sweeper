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
| `meaningful_names` | Rename auto-generated names (`Button1`, `Container54_2`, …) to PascalCase identifiers from Text / AccessibleLabel / Tooltip / child labels |
| `accessibility_labels` | Fill missing `AccessibleLabel` from Text / Tooltip / name |
| `align_near_miss` | Snap sibling X/Y/Width/Height values off by a few pixels |
| `strip_default_fill` | Clear opaque white container fills |
| `normalize_classic_button_chrome` | Clear Hover/Pressed fills when Fill is already transparent |
| `tooltip_from_label` | Copy Text / AccessibleLabel into empty Tooltip |
| `unwhack_locale_formulas` | Repair comma-decimal / `;` list-separator corruption (e.g. after switching authoring language to German), including internal `InvariantScript` and `AutoRuleBindingString` |
| `repair_control_refs` | Qualify bare cross-screen control references in formulas |
| `meaningful_names` | Rename auto-generated names (`Button1`, `Container54_2`, …) to PascalCase identifiers from Text / AccessibleLabel / Tooltip / child labels |
| `repair_context_aware_refs` | Iteratively repair stale refs, copy-paste mistakes, and typos using pattern detection and live error verification |
| `repair_double_qualified_refs` | Collapse double-qualified refs (`'Screen'.'Screen'.Control`) |
| `repair_sharepoint_fields` | Fix SharePoint list/column name typos in datasource metadata and formulas |
| `repair_var_current_package` | Repair `varCurrentPackage` record shape after screen duplication |
| `repair_ghost_patch_fields` | Comment out Patch fields referencing controls removed from duplicated screens |
| `repair_checked_booleans` | Normalize `Checked`/`Default`/`Visible`/… from `1`/`0`/`"true"` or `If(cond, 1, 0)` to real booleans |
| `repair_maintainability` | Address maintainability-class App checker issues where safe |
| `repair_delegation` | Repair SharePoint delegation warnings (email filters, collection CountIf, split Filters, admin lookup) |
| `regenerate_sarif` | Run the live Studio-equivalent App checker and write `AppCheckerResult.sarif` (updates error counts without Studio Save) |
| `ensure_focus_visible` | Set focus ring thickness/color on interactive controls (“Focus isn’t showing”) |
| `ensure_tab_index` | Set `TabIndex = 0` on interactive controls when unset |
| `scan_studio_issues` | Report remaining locale/boolean/focus issues without modifying the app (verify after repair) |
| `analyze_app_checker` | Read embedded `AppCheckerResult.sarif`, summarize formula errors, and repair known patterns (locale separators, empty layout formulas, boolean Checked) |
| `enable_dark_mode` | Inject `gblThemeLight` / `gblThemeDark` / `gblTheme` palettes, add or reuse a dark-mode toggle, and point literal colors at `gblTheme.*` tokens |
| `correlate_sharepoint` | Correlate SharePoint datasources/connections with a list schema (or patterns learned from the package), flag bad connections, and repair list/column typos in metadata + formulas |
| `set_zip_path_style` | Force zip entry separators to `windows` (`\\`) or `posix` (`/`). Default is to **preserve** the source style (almost always Windows) |

## Profiles

PHP files in [`profiles/`](profiles/) return a description and ordered hop list (same idea as sweeper profiles).

| Profile | Purpose |
|---------|---------|
| `default` | Balanced cleanup: containers, align, accessibility labels |
| `containers_only` | Container normalization only |
| `a11y_pass` | Accessibility labels and tooltips |
| `meaningful_names` | Rename generic Studio control names from visible/accessible text, then fill a11y labels and tooltips |
| `transparent_buttons` | Strip default button chrome |
| `unwhack_locale` | Locale separator repair only |
| `repair_formula_refs` | Control refs, SharePoint fields, package shape, ghost Patch fields (+ SARIF) |
| `repair_delegation` | SharePoint delegation fixes (+ SARIF) |
| `repair_studio_errors` | **Full Studio checker repair** (locale, context-aware refs, booleans, a11y, delegation, SARIF) |
| `repair_smart` | **Meaningful names + full repair** — rename generic controls first, then `repair_studio_errors` chain |
| `repair_powered` | **Full repair + dark mode** — outputs `*.powered.msapp` with `gblTheme` toggle (CDLS VCR preset) |
| `powered_thcee` | **THCEE full repair + dark mode** — same repair chain, preserves global component hosts |
| `repair_studio_errors_then_dark` | Full repair + dark mode (CDLS VCR one-shot) |
| `scan_studio_issues` | Report-only verify pass (no formula edits) |
| `regenerate_sarif` | Refresh `AppCheckerResult.sarif` from live checker only |
| `dark_mode` | `gblTheme` palettes and dark-mode toggle |
| `sharepoint_correlate` | SharePoint schema correlate + typo repair |
| `posix_zip_paths` / `windows_zip_paths` | Zip entry separator style |

For apps like **CDLS VCR** / **VCDS THCEE**, you can either:

1. Profile **`repair_powered`** or **`powered_thcee`** (or `php scripts/build_powered.php input.msapp`) → `*.powered.msapp` with full repair + `gblTheme` toggle  
2. Profile **`repair_studio_errors`** → download → verify in Studio  
3. Profile **`dark_mode`** on that cleaned `.msapp` (or use **`repair_studio_errors_then_dark`** for both in one run)

Friday deliverables (repair + dark mode, live checker 0):

```bash
php scripts/build_friday_deliverables.php
php scripts/validate_powered.php samples/import_debug/CDLS_VCR_App_Friday.powered.msapp
php scripts/validate_powered.php samples/import_debug/VCDS_THCEE_Friday.powered.msapp
```

Download: [GitHub release `friday-deliverable-20260731`](https://github.com/freementls/power_sweeper/releases/tag/friday-deliverable-20260731)

Validate a powered deliverable:

```bash
php scripts/validate_powered.php samples/import_debug/CDLS_L_VCR_App_repair2.powered.msapp
```

Or in the UI: load `repair_studio_errors`, run; then load `dark_mode` on the result. Order should still be repair hops first, then `enable_dark_mode`.

**Modular repair profiles** (compose or run standalone):

- **`repair_formula_refs`** — when formulas break after screen duplication (unqualified refs, double qualification, SharePoint typos)  
- **`repair_delegation`** — when performance/delegation hints are the only remaining issues  
- **`regenerate_sarif`** — refresh embedded App checker counts without editing formulas  
- **`scan_studio_issues`** — report-only verification after any repair pass

Dark mode alone does **not** fix locale/formula corruption; run repair first when App checker is noisy.

### Locale unwhack

When an app is edited under a comma-decimal locale (German, French, …), Studio can persist locale separators into formulas — including classic JSON rules you cannot open in the formula bar. The `unwhack_locale` profile converts those back to invariant Power Fx (`.` decimal, `,` list separator, `;` chaining) across `Src/**/*.pa.yaml` and control JSON `InvariantScript` / `AutoRuleBindingString`. Compact invariant colors like `RGBA(0,0,0,0)` are left alone (not mistaken for decimal commas).

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

Profile **`repair_studio_errors`** is the full pass for apps like CDLS VCR after a language/region switch or screen duplication. It runs, in order:

1. `unwhack_locale_formulas` — Expected operator, invalid arity, ParseJSON / If / LookUp separator damage (YAML + JSON `InvariantScript` / `AutoRuleBindingString`)  
2. `repair_control_refs` — qualify bare cross-screen control references  
3. `repair_double_qualified_refs` — collapse `'Screen'.'Screen'.Control` double qualification  
4. `repair_sharepoint_fields` — list/column typos in metadata and formulas  
5. `repair_var_current_package` — `varCurrentPackage` record shape  
6. `repair_ghost_patch_fields` — ghost Patch fields from duplicated screens  
7. `repair_studio_syntax` — trailing Concatenate commas, screen-qualified `Date()`, App bootstrap  
8. `repair_checked_booleans` — “Expecting a true or false value” on Checked/Default/Visible  
9. `accessibility_labels`, `ensure_focus_visible`, `ensure_tab_index`, `tooltip_from_label`  
10. `repair_maintainability` — safe maintainability fixes  
11. `repair_delegation` — SharePoint delegation (email filters, collection CountIf, split duplicate-request Filters)  
12. `regenerate_sarif` — write fresh `AppCheckerResult.sarif` from the live App checker  

Use **`scan_studio_issues`** afterward for a report-only verify pass, or **`regenerate_sarif`** alone to refresh SARIF without formula edits.

**Related profiles:** `repair_formula_refs` (refs/SharePoint only), `repair_delegation` (delegation only), `repair_studio_errors_then_dark` (repair + dark mode).

## Tests

```bash
php tests/run_tests.php
```

## Notes

- Import cleaned apps via **Apps → Import app → From file (.msapp)** (local file picker), then **Save** once. Prefer `~/Downloads/power_sweeper_import/` copies (ASCII filenames).
- Packed `.msapp` files **preserve** the source zip path style (almost always Windows `\\`). Use profile **`posix_zip_paths`** (or hop `set_zip_path_style`) only if you intentionally want forward slashes.
- Only hop-owned properties are changed; media, connections, and unrelated metadata are left alone.
- Prefer editing apps you can re-save in Studio; treat this as a companion cleanup tool, not a full source-control substitute.
