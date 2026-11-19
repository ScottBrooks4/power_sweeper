# VCDS OHS Risk Assessment

Canvas app working copy, now kept inside Power Sweeper at `samples/ohs_risk_assessment/`. Power Sweeper’s sweep engine is unchanged; this folder is the OHS app plus its HTML mockups.

## Screens

| Screen | Role |
| --- | --- |
| Dashboard | Leadership risk picture (`cmpRiskGrid` in **Overview** mode) |
| Homepage | Inbox / identify |
| Details | Review & score (`cmpRiskGrid` in **Select** mode) |
| Approvals | Treat & close (`cmpRiskGrid` in **Select** mode) |
| Chat | Kept from the source app |
| Control Screen | Hosts translation / external-function components |

TDR **Admin Screen** and **Refresh Screen** were removed so their broken formulas no longer load.

## One sidebar

`SidebarMenu` is the only rail. It is inserted on Dashboard, Inbox, Review, Treat, and Chat. Per-screen transparent `btnRail*` overlays are gone — navigation lives in the component (Dashboard / Inbox / Review / Treat / Chat, plus the risk-cycle steps).

## Risk grid

`cmpRiskGrid.Mode`:

- `"Select"` — tap a cell to set P×S (`varHighlightP` / `varHighlightS`)
- `"Overview"` — show counts from `colRiskCounts`; empty cells (count 0) are faded

## Theme / filter pills

Edit colors on `gblThemeLight` / `gblThemeDark` in `app/Src/App.pa.yaml`. Screens read `gblTheme.*` (pills use `Chip` / `ChipOn` / `ChipOnInk` / `Border` / `Text`). Pill galleries use a 124×28 template with an 8px gap.

## Pack for Studio

```bash
python3 scripts/pack_msapp.py
```

Then import `~/Downloads/power_sweeper_import/VCDS_OHS_Risk_Assessment.mockup.msapp`.

HTML mockups: `mockups/ohs-risk-assessment/index.html`. `mockups/.../studio-src/` is the original downloaded snapshot and is not the live source.
