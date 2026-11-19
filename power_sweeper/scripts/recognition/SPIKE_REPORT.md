# Recognition spike report

Date: 2026-09-12

## Environment

- Host Python: 3.14 (`/usr/bin/python3`)
- `ultralytics` / `pip`: **not installable** on this machine (`No module named pip`; `ensurepip` failed)
- Sidecar script: [scripts/recognition/floorscanner_recognize.py](../../scripts/recognition/floorscanner_recognize.py)
- Contract example: [scripts/recognition/element_layers.schema.example.json](element_layers.schema.example.json)
- PHP consumer: `PowerSweeper\FloorScanner\ElementLayers` + `RoomDetector::withElementLayers()` / auto-load `*.layers.json`

## Command (when weights are available)

```bash
python3 -m pip install -r scripts/recognition/requirements.txt
python3 scripts/recognition/floorscanner_recognize.py \
  --image tests/fixtures/floor_scanner/corpus/bldg5_north_1f/empty_2000.png \
  --spike --out tests/fixtures/floor_scanner/corpus/bldg5_north_1f/empty_2000.layers.json
```

Default weights: `mudasir13cs/floorcad-yolov8n-detect` (AGPL / FloorPlanCAD-derived). Override with `FLOORSCANNER_YOLO_WEIGHTS`.

## Results on this host

| Plan | Result |
|---|---|
| bldg5 `empty_2000.png` | `source=ultralytics_missing`, 0 detections |
| little_residential `empty.png` | same |

Schema-valid empty layer documents were still written so PHP loading is exercised.

## Synthetic layers for PHP path testing

`bldg5_north_1f/synthetic.layers.json` places chair+table pairs near GT cubicle centroids so `cubicleEvidenceBoxes()` and furniture-negative growth can be unit-tested without YOLO.

## Next host requirement

Run the spike on a machine with pip + torch (CPU is fine for a one-shot report). Record which FloorCAD classes fire and at what confidence; if chairs/tables are weak on this CAD style, fine-tune on corpus furniture.json from CubiCasa + hand labels.
