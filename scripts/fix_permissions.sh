#!/bin/sh
# Allow Apache (http) to write upload/tmp and output dirs.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$ROOT/storage/tmp" "$ROOT/storage/out"
chmod 1777 "$ROOT/storage/tmp" "$ROOT/storage/out"
# Keep placeholders so empty dirs survive zip/git
touch "$ROOT/storage/tmp/.gitkeep" "$ROOT/storage/out/.gitkeep"
echo "OK: $ROOT/storage/tmp and $ROOT/storage/out are world-writable (sticky bit)."
