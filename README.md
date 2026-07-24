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

## Profiles

PHP files in [`profiles/`](profiles/) return a description and ordered hop list (same idea as sweeper profiles). Examples: `default`, `containers_only`, `a11y_pass`, `transparent_buttons`, `unwhack_locale`, `dark_mode`, `sharepoint_correlate`.

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

### Dark mode kitchen-sink sample

An exhaustive light-theme sample lives in [`samples/dark_mode_kitchen_sink/`](samples/dark_mode_kitchen_sink/) — screens, labels, buttons (hover/pressed/disabled), inputs, toggle/checkbox/slider/radio, dropdown/combo, gallery item surfaces, shadows, and `RGBA` / `Color.*` / `ColorValue("#…")` forms.

```bash
php samples/dark_mode_kitchen_sink/build.php              # light .msapp
php samples/dark_mode_kitchen_sink/build.php --with-dark-mode  # + themed .msapp + report
```

Drop `samples/dark_mode_kitchen_sink/dark_mode_kitchen_sink.msapp` into Power Sweeper with the **dark_mode** profile to exercise the full toggle rewrite path.

### German locale corruption sample

Stress test for **`unwhack_locale`**: ~15k formulas with German separators baked into YAML **and** internal `InvariantScript` (`samples/locale_german_corrupt/`).

```bash
php samples/locale_german_corrupt/build.php                 # corrupt .msapp
php samples/locale_german_corrupt/build.php --with-unwhack  # + fixed .msapp + report summary
```

Drop `locale_german_corrupt.msapp` → profile **unwhack_locale** → expect thousands of formula repairs (default build reports **17681** changes, including Size/Orientation/ParseJSON/Checked patterns).

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

- Open the cleaned `.msapp` in Power Apps Studio (**File → Open → Browse**) and save once after import.
- Only hop-owned properties are changed; media, connections, and unrelated metadata are left alone.
- Prefer editing apps you can re-save in Studio; treat this as a companion cleanup tool, not a full source-control substitute.
