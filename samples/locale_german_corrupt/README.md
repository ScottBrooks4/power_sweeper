# German locale corruption sample

Simulates a canvas app damaged by authoring under a **comma-decimal locale** (e.g. German), where Studio persisted locale separators into formulas — including classic JSON `InvariantScript` the formula bar may not expose.

This is the stress case for the **`unwhack_locale`** profile (thousands of errors, similar class to the ~23k German format incident).

## Corruption patterns baked in

| Pattern | Locale (broken) | Invariant (fixed) |
|---------|-----------------|-------------------|
| Decimal | `12,5` | `12.5` |
| Thousands + decimal | `1.234,56` | `1234.56` |
| List / args | `RGBA(255; 255; 255; 1)` | `RGBA(255, 255, 255, 1)` |
| Chaining | `Set(x; 1);; Notify(...)` | `Set(x, 1); Notify(...)` |
| Lookups / If | `LookUp(List; …; …)` / `If(a; b; c)` | commas |

Surfaces:

- `Src/*.pa.yaml` — visible control formulas  
- `Controls/*.json` — internal `InvariantScript` twins (editor-hard-to-reach)

Default size: **20 screens × 40 controls** ≈ **15k+ corrupted formulas** (YAML + JSON).

## Build

```bash
# regenerate sources + pack corrupt .msapp
php samples/locale_german_corrupt/build.php

# also run unwhack_locale and write fixed .msapp + report summary
php samples/locale_german_corrupt/build.php --with-unwhack

# larger / smaller
php samples/locale_german_corrupt/build.php --screens=30 --controls=50 --with-unwhack
```

Outputs:

- `locale_german_corrupt.msapp` — drop into Power Sweeper  
- `locale_german_corrupt.fixed.msapp` — after unwhack (with `--with-unwhack`)  
- `locale_german_corrupt.fixed.report.json` — totals + sample entries  
- `manifest.json` — generation stats  

## Test in the UI

1. Drop `locale_german_corrupt.msapp` into Power Sweeper  
2. Choose profile **`unwhack_locale`**  
3. Run → review the change report (thousands of formula fixes)  
4. Open the cleaned `.msapp` in Studio (**File → Open → Browse**) and save once  

## Notes

- Strings such as German UI copy (`"Gespeichert…"`, `"hoch"`) are left alone; only separators/decimals outside strings are rewritten.  
- Format strings like `"0,00"` inside quotes stay as-is (correct for `Text(..., "0,00")` when intentionally German).  
- Re-run `generate.php` anytime; committed `Src/` / `Controls/` may be large — prefer regenerating via `build.php`.
