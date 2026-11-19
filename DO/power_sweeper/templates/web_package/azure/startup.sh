#!/usr/bin/env bash
# Azure App Service Startup Command:
#   bash /home/site/wwwroot/azure/startup.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$ROOT/data" || true
chmod -R ug+rwX "$ROOT/data" || true
if [[ -f "$ROOT/data/schema.sql" && ! -f "$ROOT/data/app.sqlite" ]]; then
  if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "$ROOT/data/app.sqlite" < "$ROOT/data/schema.sql" || true
  fi
fi
echo "power-sweeper-web-package startup $(date -u +%Y-%m-%dT%H:%M:%SZ)"
