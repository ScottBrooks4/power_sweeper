#!/usr/bin/env bash
# Build a deploy zip for Azure App Service (Linux PHP) without customer .msapp samples.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/storage/out/power_sweeper-azure.zip}"
mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"

cd "$ROOT"
if [[ ! -d vendor ]]; then
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi

zip -r "$OUT" . \
  -x '.git/*' \
  -x '.github/*' \
  -x 'storage/tmp/*' \
  -x 'storage/out/*' \
  -x 'dist/*' \
  -x 'samples/**/*.msapp' \
  -x 'samples/import_debug/*' \
  -x 'tests/fixtures/*.msapp' \
  -x '*.log' \
  -x '.DS_Store' \
  -x 'composer.phar'

# Ensure empty runtime dirs exist in the zip
(
  cd "$ROOT"
  echo -n > storage/tmp/.gitkeep
  echo -n > storage/out/.gitkeep
  zip "$OUT" storage/tmp/.gitkeep storage/out/.gitkeep >/dev/null
)

echo "Wrote $OUT"
ls -lh "$OUT"
