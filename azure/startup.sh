#!/usr/bin/env bash
# Azure App Service (Linux PHP) startup command.
# Portal → Configuration → General settings → Startup Command:
#   bash /home/site/wwwroot/azure/startup.sh
set -euo pipefail

ROOT="${HOME_SITE_WWWROOT:-/home/site/wwwroot}"
cd "$ROOT"

mkdir -p storage/tmp storage/out
chmod -R ug+rwX storage || true

if [[ -f composer.json ]]; then
  if [[ ! -d vendor ]]; then
    if command -v composer >/dev/null 2>&1; then
      composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    elif [[ -f composer.phar ]]; then
      php composer.phar install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    else
      echo "WARNING: composer not found; vendor/ missing" >&2
    fi
  fi
fi

# Prefer site-home nginx override when present (App Service convention).
if [[ -f "$ROOT/azure/nginx.conf" ]]; then
  if [[ -d /etc/nginx/sites-available ]]; then
    cp "$ROOT/azure/nginx.conf" /etc/nginx/sites-available/default || true
  fi
  mkdir -p /home/site
  cp "$ROOT/azure/nginx.conf" /home/site/default || true
  if command -v nginx >/dev/null 2>&1; then
    nginx -t && nginx -s reload || true
  fi
fi

# Keep the container entrypoint's default PHP/nginx stack running.
# When Azure invokes this as Startup Command, returning lets the platform
# continue; some images expect the script to exec the default start.
if [[ -x /usr/local/bin/entrypoint.sh ]]; then
  exec /usr/local/bin/entrypoint.sh
fi
if [[ -x /opt/startup/startup.sh ]]; then
  exec /opt/startup/startup.sh
fi
