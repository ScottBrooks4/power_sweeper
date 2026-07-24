# Dark Mode Kitchen Sink

Exhaustive light-theme sample for testing Power Sweeper’s `enable_dark_mode` hop.

## What’s covered

| Area | Examples |
|------|----------|
| Screens | `HomeScreen` (intro/landing — receives injected toggle), `ControlsScreen` |
| Surfaces | white, near-white, slate, tinted blue, amber warning, footer bar |
| Text | primary, muted, inverse, danger, success |
| Buttons | primary / secondary / danger / ghost / disabled (+ hover, pressed, focused, disabled colors) |
| Inputs | text input, icon, image chrome, HTML viewer |
| Choices | toggle (`TrueFill`/`FalseFill`/`HandleFill`), checkbox, slider rails/handles, radio |
| Lists | dropdown/combo chevrons + selection, gallery item/selected/hover/pressed fills |
| Shadows | `DropShadow.None` / `Light` / `Regular` / `Semibold` / `Bold` (enums kept; color chrome still themes) |
| Color forms | `RGBA(...)`, `Color.White` / `Color.Black` / `Color.Transparent`, `ColorValue("#…")` |
| Skips | transparent fills stay transparent (not wrapped) |

## Build the `.msapp`

From the repo root:

```bash
# light-theme sample only
php samples/dark_mode_kitchen_sink/build.php

# also run enable_dark_mode and write *.dark.msapp + report JSON
php samples/dark_mode_kitchen_sink/build.php --with-dark-mode
```

Outputs:

- `dark_mode_kitchen_sink.msapp` — input for Power Sweeper
- `dark_mode_kitchen_sink.dark.msapp` — after dark-mode hop (with `--with-dark-mode`)
- `dark_mode_kitchen_sink.dark.report.json` — full change report

## Run in the UI

1. Open Power Sweeper
2. Drop `dark_mode_kitchen_sink.msapp`
3. Choose profile **dark_mode** (or hop `enable_dark_mode`)
4. Run → download cleaned app
5. Open in Power Apps Studio (**File → Open → Browse**), save once
6. On `HomeScreen`, use **Dark mode** toggle and confirm surfaces/text/borders flip

## Source layout

```
Src/
  App.pa.yaml
  HomeScreen.pa.yaml
  ControlsScreen.pa.yaml
```
