#!/usr/bin/env bash
# Build a deploy zip for Azure App Service (Linux PHP) without customer .msapp samples.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/storage/out/power_sweeper-azure.zip}"
# Absolute path so staging `cd` + trap cleanup cannot delete/misplace the zip.
if [[ "$OUT" != /* ]]; then
  OUT="$PWD/$OUT"
fi
mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"

cd "$ROOT"
if [[ ! -d vendor ]]; then
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi

STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

# Stage only what Azure needs (no samples .msapp, no benchmarks, no .git).
# Prefer rsync when available; fall back to tar (no rsync on some agents).
if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude '.git/' \
    --exclude '.github/' \
    --exclude 'storage/tmp/' \
    --exclude 'storage/out/' \
    --exclude 'storage/benchmarks/' \
    --exclude 'dist/' \
    --exclude 'samples/import_debug/' \
    --exclude 'web/' \
    --exclude '*.msapp' \
    --exclude '*.log' \
    --exclude '.DS_Store' \
    --exclude 'composer.phar' \
    ./ "$STAGE/"
else
  tar -C "$ROOT" \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='storage/tmp' \
    --exclude='storage/out' \
    --exclude='storage/benchmarks' \
    --exclude='dist' \
    --exclude='samples/import_debug' \
    --exclude='web' \
    --exclude='*.msapp' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='composer.phar' \
    -cf - . | tar -C "$STAGE" -xf -
fi

mkdir -p "$STAGE/storage/tmp" "$STAGE/storage/out"
: > "$STAGE/storage/tmp/.gitkeep"
: > "$STAGE/storage/out/.gitkeep"

(
  cd "$STAGE"
  zip -r "$OUT" . -x '*.msapp' -x '*.log' -x '.DS_Store'
)

echo "Wrote $OUT"
ls -lh "$OUT"
