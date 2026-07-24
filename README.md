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
| `enable_dark_mode` | Ensure `gblDarkMode`, add or reuse a dark-mode toggle, and wrap literal fills/text/borders for accessible dark contrast |
| `correlate_sharepoint` | Correlate SharePoint datasources/connections with a list schema (or patterns learned from the package), flag bad connections, and repair list/column typos in metadata + formulas |

## Profiles

PHP files in [`profiles/`](profiles/) return a description and ordered hop list (same idea as sweeper profiles). Examples: `default`, `containers_only`, `a11y_pass`, `transparent_buttons`, `unwhack_locale`, `dark_mode`, `sharepoint_correlate`.

### Locale unwhack

When an app is edited under a comma-decimal locale (German, French, …), Studio can persist locale separators into formulas — including classic JSON rules you cannot open in the formula bar. The `unwhack_locale` profile converts those back to invariant Power Fx (`.` decimal, `,` list separator, `;` chaining) across `Src/**/*.pa.yaml` and control JSON `InvariantScript`.

### Dark mode

The `dark_mode` profile initializes `gblDarkMode` on `App.OnStart`, reuses a settings/theme toggle when one exists (or injects `tglPowerSweeperDarkMode` on an intro/home screen), and rewrites literal color properties to `If(gblDarkMode, <dark>, <light>)` with contrast-aware dark mappings. Open the cleaned `.msapp` in Studio and save once, then verify the toggle and contrast.

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

Drop `locale_german_corrupt.msapp` → profile **unwhack_locale** → expect thousands of formula repairs (default build reports **14801** changes).

## Tests

```bash
php tests/run_tests.php
```

## Notes

- Open the cleaned `.msapp` in Power Apps Studio (**File → Open → Browse**) and save once after import.
- Only hop-owned properties are changed; media, connections, and unrelated metadata are left alone.
- Prefer editing apps you can re-save in Studio; treat this as a companion cleanup tool, not a full source-control substitute.
