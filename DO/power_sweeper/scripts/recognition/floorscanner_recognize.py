#!/usr/bin/env python3
"""Floor Scanner recognition sidecar.

Emits ElementLayers JSON (schema_version 1) for RoomDetector:

  walls[], doors[], stairs[], furniture[{class,bbox,confidence}], labels[]

Usage:
  python3 scripts/recognition/floorscanner_recognize.py --image path.png --out layers.json
  python3 scripts/recognition/floorscanner_recognize.py --image path.png --spike

Weights (optional):
  FLOORSCANNER_YOLO_WEIGHTS  path or HF id (default: mudasir13cs/floorcad-yolov8n-detect)
  FLOORSCANNER_YOLO_CONF     confidence floor (default 0.25)

When ultralytics is missing, writes an empty schema-valid document so PHP can
still load the contract.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from collections import Counter
from pathlib import Path

FURNITURE = {
    "chair", "table", "desk", "sofa", "bed", "sink", "toilet", "bathtub",
    "bath", "bath_tub", "wardrobe", "cabinet", "tv_cabinet", "refrigerator",
    "gas_stove", "washing_machine", "urinal", "squat_toilet", "bedside_cupboard",
    "half_height_cabinet", "high_cabinet",
}
DOORS = {"door", "single_door", "double_door", "sliding_door", "door2"}
STAIRS = {"stair", "elevator", "escalator"}
WALLS = {"wall", "parking"}


def empty_doc(width: int = 0, height: int = 0, source: str = "none") -> dict:
    return {
        "schema_version": 1,
        "width": width,
        "height": height,
        "source": source,
        "walls": [],
        "doors": [],
        "stairs": [],
        "furniture": [],
        "labels": [],
    }


def item(cls: str, xyxy, conf: float) -> dict:
    x0, y0, x1, y1 = [int(v) for v in xyxy]
    return {
        "class": cls,
        "bbox": {"x": x0, "y": y0, "w": max(1, x1 - x0), "h": max(1, y1 - y0)},
        "confidence": float(conf),
        "source": "yolo",
    }


def run_yolo(image: Path, conf: float, weights: str) -> dict:
    try:
        from ultralytics import YOLO  # type: ignore
        from PIL import Image
    except ImportError as exc:
        print(f"ultralytics not available: {exc}", file=sys.stderr)
        im = None
        try:
            from PIL import Image
            im = Image.open(image)
            w, h = im.size
        except Exception:
            w = h = 0
        return empty_doc(w, h, source="ultralytics_missing")

    im = Image.open(image)
    width, height = im.size
    doc = empty_doc(width, height, source=weights)

    try:
        model = YOLO(weights)
    except Exception as exc:
        print(f"failed to load weights {weights}: {exc}", file=sys.stderr)
        doc["source"] = f"load_failed:{weights}"
        return doc

    results = model.predict(str(image), conf=conf, verbose=False)
    if not results:
        return doc
    r0 = results[0]
    names = r0.names or {}
    if r0.boxes is None:
        return doc
    for box in r0.boxes:
        cls_id = int(box.cls.item())
        cls = str(names.get(cls_id, cls_id)).lower()
        xyxy = box.xyxy[0].tolist()
        score = float(box.conf.item())
        entry = item(cls, xyxy, score)
        if cls in FURNITURE or any(k in cls for k in ("chair", "table", "desk", "sofa", "bed")):
            doc["furniture"].append(entry)
        elif cls in DOORS or "door" in cls:
            doc["doors"].append(entry)
        elif cls in STAIRS or cls in ("stair", "elevator", "escalator"):
            doc["stairs"].append(entry)
        elif cls in WALLS:
            doc["walls"].append(entry)
        else:
            # Keep unknowns under furniture so they still act as growth negatives.
            doc["furniture"].append(entry)
    return doc


def spike_report(doc: dict) -> None:
    counts: Counter[str] = Counter()
    for key in ("furniture", "doors", "stairs", "walls"):
        for it in doc.get(key, []):
            counts[f"{key}:{it['class']}"] += 1
    print(f"source={doc.get('source')} size={doc.get('width')}x{doc.get('height')}")
    if not counts:
        print("  (no detections)")
        return
    for k, n in counts.most_common():
        print(f"  {k}: {n}")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--out", default="")
    ap.add_argument("--spike", action="store_true")
    ap.add_argument("--conf", type=float, default=float(os.environ.get("FLOORSCANNER_YOLO_CONF", "0.25")))
    ap.add_argument(
        "--weights",
        default=os.environ.get("FLOORSCANNER_YOLO_WEIGHTS", "mudasir13cs/floorcad-yolov8n-detect"),
    )
    args = ap.parse_args()
    image = Path(args.image)
    if not image.is_file():
        print(f"missing image: {image}", file=sys.stderr)
        return 1

    doc = run_yolo(image, args.conf, args.weights)
    if args.spike:
        spike_report(doc)
    out = Path(args.out) if args.out else image.with_suffix(".layers.json")
    out.write_text(json.dumps(doc, indent=2) + "\n")
    print(f"wrote {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
