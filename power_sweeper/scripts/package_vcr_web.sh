#!/usr/bin/env bash
# Build a standalone VCR web zip for Azure Zip Deploy or email.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/storage/out/vcr-web-azure.zip}"
if [[ "$OUT" != /* ]]; then
  OUT="$PWD/$OUT"
fi
exec php "$ROOT/scripts/package_vcr_web.php" "$OUT"
