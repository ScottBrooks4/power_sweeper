# Floor Scanner labelled corpus

This corpus is the regression and training surface for Floor Scanner. Every plan
ships an empty drawing, a human-labelled Filled overlay (or CubiCasa Space
annotation), extracted `gt.json`, and deliberate raster DPI variants.

## Layout

```text
corpus/<plan-id>/
  empty.svg | empty.png
  filled.svg              (Synoptic Designer annotation export)
  gt.json                 (extracted spaces + labels)
  meta.json               (class, split, licence, variants)
  empty_*.png             (DPI variants)
```

Target composition: ~10 office (portfolio), ~8 residential (CubiCasa5K),
~2 awkward rasters. Split **12 dev / 8 holdout**, with at least 3 office plans
in holdout. Do not tune against holdout.

## Labelling convention

These rules keep metrics comparable across annotators and tools.

### Cubicle extent
A cubicle rectangle covers the **floor area inside the partition walls**,
including the open front. It does **not** extend past the shared partition into
the neighbour cell. Desk and chair furniture sit *inside* the cubicle rect; the
desk lip is not a separate space.

### Rooms and offices
Closed offices and conference rooms are labelled to the **inner face of the
walls**. Shared open areas (lounge, collaboration) get one rect per named space
on the source drawing, not a flood of furniture stamps.

### Circulation
Corridors, stairs, elevators, and lobbies that are not named as rooms on the
source drawing are **not** labelled as spaces. Stairs/elevators are element
layers for the recognition sidecar, not room GT.

### Labels
Use the drawing's own space ID when present (`D04.27`, `E05.01`, `Kitchen`).
The extractor keeps `title` / `id` from Synoptic exports. Scoring compares both
exact and normalised forms (trailing `A00.00` codes).

### Type
- `cubicle`: module / workstation cells (roughly ≤ 40 000 viewBox-area units on
  Building 5 scale, or any space the annotator tagged as a cubicle ID pattern).
- `room`: everything else (offices, baths, kitchens, conference).

### Shared / open fronts
Open-front cubicles still get a full rectangle completed by the bank rhythm
(the missing fourth wall is implied). Do not leave open sides unlabelled.

## Licence

Recorded per plan in `meta.json`. Internal evaluation may include CC BY-NC
datasets (CubiCasa, FloorPlanCAD) and AGPL model weights; commercial product
use needs a separate review of those fields.

## Commands

```bash
# Extract GT from a Filled SVG
php scripts/floor_scanner_extract_gt.php --filled=corpus/<id>/filled.svg --out=corpus/<id>/gt.json

# Residential teal fills
php scripts/floor_scanner_extract_gt.php --filled=... --out=... --fill=#16A085

# CubiCasa Space SVG
php scripts/floor_scanner_extract_gt.php --cubicasa --filled=model.svg --out=gt.json

# Raster DPI variants
php scripts/floor_scanner_raster_variants.php --plan=corpus/<id>

# Bench (dev by default; --holdout / --full / --write)
php scripts/floor_scanner_bench.php
php scripts/floor_scanner_bench.php --holdout
php scripts/floor_scanner_bench.php --full --write
```
