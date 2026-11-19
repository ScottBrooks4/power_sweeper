# Pending office portfolio exports

Office cubicle GT does not exist in public datasets. Add Synoptic Designer
Empty + Filled pairs here (same style as `bldg5_north_1f` when possible).

Suggested ids:
- bldg5_north_2f
- bldg5_south_1f
- bldg5_east_1f
- other wings / buildings from the same CAD family

For each plan:
1. Export Empty.svg and Filled.svg from Synoptic Designer 2.0.x
2. Place under `tests/fixtures/floor_scanner/corpus/<plan-id>/`
3. Run:
   ```bash
   php scripts/floor_scanner_extract_gt.php \
     --filled=tests/fixtures/floor_scanner/corpus/<plan-id>/filled.svg \
     --out=tests/fixtures/floor_scanner/corpus/<plan-id>/gt.json
   php scripts/floor_scanner_raster_variants.php --plan=tests/fixtures/floor_scanner/corpus/<plan-id>
   ```
4. Write `meta.json` with `"class":"office"`, `"split":"dev"|"holdout"`,
   `"licence":"internal"`. Keep ≥3 office plans in holdout.

Current office corpus: `bldg5_north_1f` (dev) + `bldg5_north_1f_raster` (holdout awkward PNG).
