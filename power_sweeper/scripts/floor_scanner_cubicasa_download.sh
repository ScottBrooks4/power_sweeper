#!/usr/bin/env bash
# Download CubiCasa5K (large). Prefer cherry-picking a few high_quality_architectural
# samples rather than unpacking the full ~multi-GB archive onto the web host.
#
# Dataset: https://doi.org/10.5281/zenodo.2613548
# Code:    https://github.com/CubiCasa/CubiCasa5k
#
# After download, convert one plan:
#   php scripts/floor_scanner_cubicasa_convert.php \
#     --svg=/path/to/.../model.svg \
#     --image=/path/to/.../F1_scaled.png \
#     --plan-id=cubicasa_hq_001 \
#     --split=dev

set -euo pipefail
DEST="${1:-$HOME/datasets/cubicasa5k}"
mkdir -p "$DEST"
echo "Download the CubiCasa5K zip from Zenodo into: $DEST"
echo "  https://zenodo.org/record/2613548"
echo "Then extract and convert selected model.svg files with floor_scanner_cubicasa_convert.php"
