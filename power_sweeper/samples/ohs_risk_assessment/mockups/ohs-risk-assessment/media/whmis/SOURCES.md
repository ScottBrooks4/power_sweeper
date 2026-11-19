# WHMIS 2015 pictograms

Source: [CCOHS WHMIS Pictograms Kit](https://www.ccohs.ca/WHMISpictograms.html) (Hazardous Products Regulations, updated Dec 2022). Free for workplace OHS use. Do not treat the mockup SVG sprite as the official artwork.

Upload **`128/`** into Power Apps Media (one file per pictogram). Use **`64/`** only if a gallery needs a smaller file; the Image control can also scale the 128s down.

| File | Pictogram | Required in WHMIS |
| --- | --- | --- |
| `whmis_flame.png` | Flame | Yes |
| `whmis_flame_over_circle.png` | Flame over circle (oxidizer) | Yes |
| `whmis_exploding_bomb.png` | Exploding bomb | Yes (some classes) |
| `whmis_gas_cylinder.png` | Gas cylinder | Yes |
| `whmis_skull.png` | Skull and crossbones | Yes |
| `whmis_corrosion.png` | Corrosion | Yes |
| `whmis_exclamation.png` | Exclamation mark | Yes |
| `whmis_health_hazard.png` | Health hazard | Yes |
| `whmis_environment.png` | Environment (aquatic) | Not required; may be used |
| `whmis_biohazardous.png` | Biohazardous infectious materials | Yes (WHMIS-only; circular, not a GHS diamond) |

`official/` is the CCOHS PNG as downloaded (~290–304 px). Studio: Media → Upload the ten `128/` files. Image control: Width/Height 32 (tables) or 48 (cards), ImagePosition = Fit.
