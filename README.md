# Power Sweeper

Normalize and clean Power Apps canvas `.msapp` files with an ordered **hop** pipeline.

Drop an app — Power Sweeper scans it, picks which hops are needed and in what order, then you can tweak the sequence, run, and download the cleaned `.msapp` plus a change report.

## Local path

Canonical install: **`/srv/http/power_sweeper`** (same layout as sweeper/convert under `/srv/http`).

## Azure App Service (Linux PHP)

Publish-ready branch packaging (excludes customer `.msapp` samples):

```bash
composer install --no-dev
bash scripts/package_azure_zip.sh
# → storage/out/power_sweeper-azure.zip
```

In Azure Portal create a **PHP 8.2+** Linux Web App with the **zip** extension enabled, deploy that zip to `/home/site/wwwroot`, then set **Startup Command** to:

```bash
bash /home/site/wwwroot/azure/startup.sh
```

Confirm `upload_limits` via `/api/run.php` shows **256M** (from [`.user.ini`](.user.ini)). `storage/tmp` and `storage/out` must be writable. Nginx routing lives in [`azure/nginx.conf`](azure/nginx.conf).

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
2. **Analyze** scans for locale damage, formula errors, a11y gaps, generic names, container chrome, and theme presence — then proposes **only the hops expected to change something**, in order, plus write mode (`missing_only` vs `all`). If nothing actionable is found, the sequence stays empty.
3. Tweak hops if you want, then run. Power Sweeper unpacks the archive, edits `Src/**/*.pa.yaml` (and control JSON when present), repacks, and returns the file plus a report.

Order matters: the same hops in a different sequence can produce different results. There are no named “profiles” — only hops, detection, and optional CLI convenience chains in [`config/hop_chains/`](config/hop_chains/).

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
| `repair_converge_formulas` | Live-checker loop: repeat ref/locale/boolean fixes until formula errors stop decreasing |
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
| `translate` | Centralize label/button text into `gblStringsEn`/`gblStringsFr` packs; bind to `gblStrings.*`; detect or inject a language control (ties into existing English/French / `varLang` settings) |
| `enable_dark_mode` | Inject `gblThemeLight` / `gblThemeDark` / `gblTheme` palettes, add or reuse a dark-mode toggle, and point literal colors at `gblTheme.*` tokens |
| `correlate_sharepoint` | Correlate SharePoint datasources/connections with a list schema (or patterns learned from the package), flag bad connections, and repair list/column typos in metadata + formulas |
| `set_zip_path_style` | Force zip entry separators to `windows` (`\\`) or `posix` (`/`). Default is to **preserve** the source style (almost always Windows) |
| `export_web_ir` | Build structural web IR (`WebApp/power_sweeper_ir.json`) + static HTML scaffold; optional browser document layout |
| `import_web_ir` | Apply IR heuristics back onto the `.msapp` (document layout, label sync, Navigate renames) — not a full web runtime import |
| `configure_power_document` | Switch `Properties.json` toward classic Power (`mode=power`) or browser (`mode=web`) ScaleToFit defaults |

## Auto-detect and CLI hop chains

The UI does **not** use named profiles. Drop an `.msapp` and **Analyze** (`HopAdvisor`) decides which hops to run and in which order, plus write mode (`missing_only` vs `all` for hops that honor `force`).

For scripts and batch builds, thin named sequences live in [`config/hop_chains/`](config/hop_chains/) and are exposed via `HopChains`:

| Chain | Purpose |
|-------|---------|
| `studio_repair` | Full Studio checker repair order (locale → refs → booleans → a11y → delegation → SARIF) |
| `dark_mode` | Classic theme prep + `enable_dark_mode` |
| `powered` | `studio_repair` + forced dark mode (deliverable builds) |
| `smart_repair` | `meaningful_names` then `studio_repair` |
| `power_to_web` / `web_to_power` | Structural IR export / import (not a full compiler) |

```bash
php scripts/build_repaired.php input.msapp
php scripts/build_powered.php input.msapp
php scripts/build_smart_repair.php input.msapp
php scripts/matrix_hops.php --apps=vcr,thcee,tdr,pacs,template
php scripts/matrix_hops.php --web --skip-powered
```

Compose individual hops in the UI anytime — add, remove, and reorder freely.

### Power ↔ web conversion (structural, heuristic)

These chains are intentionally **not** a 100% Power App ↔ arbitrary web-app compiler. They move structure through an intermediate representation:

1. **`power_to_web`** — rename generic Studio controls, then extract screens, roles, labels, layout, literal state, navigation edges, theme tokens, and datasources into `WebApp/power_sweeper_ir.json`, plus a static HTML scaffold.
2. Edit the IR (or regenerate it) outside Studio when needed.
3. **`web_to_power`** — re-apply safe deltas: document meta, labels, literal layout/state, non-screen renames via `previous_name`, and `Navigate` renames when the destination already exists.

Power Fx formulas remain authoritative inside the `.msapp`. Studio repair uses context-aware reference repair and a live-checker converge loop — not blind find/replace.

Friday deliverables (repair + dark mode):

```bash
php scripts/build_friday_deliverables.php
php scripts/validate_powered.php samples/import_debug/CDLS_VCR_App_Friday.powered.msapp
php scripts/validate_powered.php samples/import_debug/VCDS_THCEE_Friday.powered.msapp
```

Download: [GitHub release `friday-deliverable-20260731`](https://github.com/freementls/power_sweeper/releases/tag/friday-deliverable-20260731)

Validate a powered deliverable (formula errors fail; soft advisories warn):

```bash
php scripts/validate_powered.php samples/import_debug/CDLS_L_VCR_App_repair2.powered.msapp
```

Dark mode alone does **not** fix locale/formula corruption; run repair hops first when App checker is noisy.

### Locale unwhack

When an app is edited under a comma-decimal locale (German, French, …), Studio can persist locale separators into formulas — including classic JSON rules you cannot open in the formula bar. The `unwhack_locale_formulas` hop converts those back to invariant Power Fx (`.` decimal, `,` list separator, `;` chaining) across `Src/**/*.pa.yaml` and control JSON `InvariantScript` / `AutoRuleBindingString`. Compact invariant colors like `RGBA(0,0,0,0)` are left alone (not mistaken for decimal commas).

### Dark mode

The `enable_dark_mode` hop builds an **editable central palette** instead of hard-coding `If(gblDarkMode, …)` / RGBA on every control:

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

The `correlate_sharepoint` hop then:

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

### Studio repair order

When formula/locale damage is detected (or when you run `HopChains::studioRepair()`), hops typically run in this order:

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

Use hop `scan_studio_issues` afterward for a report-only verify pass, or `regenerate_sarif` alone to refresh SARIF without formula edits.

## Tests

```bash
php tests/run_tests.php
```

## Notes

- Import cleaned apps via **Apps → Import app → From file (.msapp)** (local file picker), then **Save** once. Prefer `~/Downloads/power_sweeper_import/` copies (ASCII filenames).
- Packed `.msapp` files **preserve** the source zip path style (almost always Windows `\\`). Use hop `set_zip_path_style` only if you intentionally want forward slashes.
- Only hop-owned properties are changed; media, connections, and unrelated metadata are left alone.
- Prefer editing apps you can re-save in Studio; treat this as a companion cleanup tool, not a full source-control substitute.
