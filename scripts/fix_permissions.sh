#!/bin/sh
# Allow Apache (http) to write upload/tmp, output, and estimate-learning dirs.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$ROOT/storage/tmp" "$ROOT/storage/out" "$ROOT/storage/benchmarks"
chmod 1777 "$ROOT/storage/tmp" "$ROOT/storage/out" "$ROOT/storage/benchmarks"
# Keep placeholders so empty dirs survive zip/git
touch "$ROOT/storage/tmp/.gitkeep" "$ROOT/storage/out/.gitkeep"
echo "OK: $ROOT/storage/tmp, out, and benchmarks are world-writable (sticky bit)."
