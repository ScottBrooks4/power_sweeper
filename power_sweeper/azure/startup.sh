#!/usr/bin/env bash
# Azure App Service Startup Command:
#   bash /home/site/wwwroot/azure/startup.sh
#
# App Setting (PHP uploads):
#   PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/home/site/ini
#
# This script must finish and return. Do NOT exec the platform entrypoint.
set -euo pipefail

ROOT=/home/site/wwwroot
GOOD="$ROOT/azure/nginx-app-service-default.conf"
PHPINI="$ROOT/azure/php-uploads.ini"

mkdir -p "$ROOT/storage/tmp" "$ROOT/storage/out" /home/site/ini /home/LogFiles || true
chmod -R ug+rwX "$ROOT/storage" || true

if [[ -f "$PHPINI" ]]; then
  cp -f "$PHPINI" "$ROOT/.user.ini" || true
  cp -f "$PHPINI" "$ROOT/api/.user.ini" || true
  cp -f "$PHPINI" "$ROOT/public/.user.ini" || true
  cp -f "$PHPINI" /home/site/ini/power-sweeper-uploads.ini || true
  cp -f "$PHPINI" /usr/local/etc/php/conf.d/zz-power-sweeper-uploads.ini 2>/dev/null || true
fi

# Persisted broken overlays caused site-wide 404. Always replace with known-good.
rm -f /etc/nginx/conf.d/default.conf /etc/nginx/conf.d/zz-power-sweeper-uploads.conf 2>/dev/null || true
if [[ -f "$GOOD" ]]; then
  cp -f "$GOOD" /home/site/default
  cp -f "$GOOD" /etc/nginx/sites-enabled/default
  cp -f "$GOOD" /etc/nginx/sites-available/default 2>/dev/null || true
fi

if command -v nginx >/dev/null 2>&1; then
  nginx -t
  nginx -s reload 2>/dev/null || service nginx reload 2>/dev/null || service nginx restart 2>/dev/null || true
fi

echo "power-sweeper startup done $(date -u +%Y-%m-%dT%H:%M:%SZ)" >> /home/LogFiles/power-sweeper-startup.log
