#!/usr/bin/env python3
"""Pack app/ into a Studio-importable .msapp with Windows zip paths."""
from __future__ import annotations

import zipfile
from pathlib import Path

ROOT = Path("/srv/http/power_sweeper/samples/ohs_risk_assessment/app")
TEMPLATE = Path("/home/lulu/Downloads/power_sweeper_import/VCDS_OHS_Risk_Assessment.mockup.msapp")
OUTPUT = Path("/home/lulu/Downloads/power_sweeper_import/VCDS_OHS_Risk_Assessment.mockup.msapp")


def pack_msapp(source_dir: Path, output: Path, template: Path) -> None:
    with zipfile.ZipFile(template, "r") as zf:
        entry_order = zf.namelist()
    tmp = output.with_suffix(".msapp.tmp")
    if tmp.exists():
        tmp.unlink()
    with zipfile.ZipFile(tmp, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        written: set[str] = set()
        for name in entry_order:
            rel = name.replace("\\", "/")
            src = source_dir / rel
            if not src.is_file():
                continue
            zf.write(src, name)
            written.add(rel)
        for path in source_dir.rglob("*"):
            if not path.is_file():
                continue
            rel = path.relative_to(source_dir).as_posix()
            if rel in written:
                continue
            zf.write(path, rel.replace("/", "\\"))
    tmp.replace(output)
    print("packed", output, "bytes", output.stat().st_size)


if __name__ == "__main__":
    pack_msapp(ROOT, OUTPUT, TEMPLATE)
