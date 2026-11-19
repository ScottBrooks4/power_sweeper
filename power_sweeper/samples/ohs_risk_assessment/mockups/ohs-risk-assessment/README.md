# VCDS Group OHS Risk Assessment — Power Apps mockups

Canvas size: **1366 × 768** (Power Apps tablet default).

## Screens

| File | Screen | Workflow step |
| --- | --- | --- |
| `backgrounds/01-dashboard.png` | Risk picture | Leadership filters (L2, program, risk tolerance) |
| `backgrounds/02-inbox.png` | New risks identified | Role login → OHS notified |
| `backgrounds/03-review.png` | Review & score | Contact OPI, adjust P×S, RACI |
| `backgrounds/04-treat.png` | Treat & close | Hierarchy of controls, evidence, archive |

Power Apps formulas: `powerapps/App.OnStart.fx`, `App.Formulas.fx`, `cmpRiskGrid.fx`. WHMIS PNGs: `media/whmis/128/`.

Studio source from `VCDS OHS Risk Assessment.msapp` (on `main`): `studio-src/`.

## Drop into Power Apps

1. App settings → **Display** → 1366 × 768, landscape, scale to fit.
2. Media → **Upload** the four PNGs.
3. One screen per PNG. Set `Screen.Image` to the matching image, `ImagePosition` = Fit.
4. Overlay real controls (`Gallery`, `Button`, `Combo box`, `Text input`) with **Fill = Transparent** on chrome you want to keep from the background — or hide the image later and restyle controls to match.

Do not put formulas in the image. The mockup is look-only.

## Scoring (from the register)

`Score = Probability (1–5) × Severity (1–5)`

| Band | Scores |
| --- | --- |
| Low | 1–3 |
| Moderate | 4–6 |
| High | 8–12 |
| Extreme | 15–25 |

The app should store **Initial L2**, **L2 as of cycle**, and **L1 Safety Advisor** scores. L1 may downgrade when lagging indicators (DND 663, CF 98, WSIB, refusals, WHVP NoOs) are absent.

## WHMIS pictograms

Official WHMIS 2015 / GHS diamonds plus the circular **biohazardous infectious materials** symbol. Show them on Hazardous Substances, AESP, Fire, and other product-related programs — not on WHVP or generic GSP unless a product is named.
