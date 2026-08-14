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

apply_php_uploads() {
  local src="$ROOT/azure/php-uploads.ini"
  [[ -f "$src" ]] || return 0
  # Non-dotfile source — OneDeploy / zip often skips .user.ini
  cp "$src" "$ROOT/.user.ini" || true
  mkdir -p /home/site/ini /usr/local/etc/php/conf.d 2>/dev/null || true
  cp "$src" /home/site/ini/power-sweeper-uploads.ini 2>/dev/null || true
  cp "$src" /usr/local/etc/php/conf.d/zz-power-sweeper-uploads.ini 2>/dev/null || true
}

apply_nginx_uploads() {
  local src="$ROOT/azure/nginx.conf"
  [[ -f "$src" ]] || return 0

  for dest in \
    /etc/nginx/sites-available/default \
    /etc/nginx/sites-enabled/default \
    /home/site/default
  do
    if [[ -d "$(dirname "$dest")" ]] || [[ "$dest" == /home/site/default ]]; then
      mkdir -p "$(dirname "$dest")" 2>/dev/null || true
      cp "$src" "$dest" 2>/dev/null || true
    fi
  done

  # Oryx/default images often set client_max_body_size 1m in the http{} block.
  if [[ -f /etc/nginx/nginx.conf ]]; then
    if grep -qE 'client_max_body_size' /etc/nginx/nginx.conf; then
      sed -i -E 's/client_max_body_size[[:space:]]+[0-9]+[mMkKgG]?;/client_max_body_size 512M;/g' /etc/nginx/nginx.conf || true
    else
      sed -i '/http[[:space:]]*{/a\    client_max_body_size 512M;' /etc/nginx/nginx.conf || true
    fi
  fi

  if command -v nginx >/dev/null 2>&1; then
    nginx -t 2>/dev/null && nginx -s reload 2>/dev/null || true
  fi
}

apply_php_uploads
apply_nginx_uploads

# Entrypoint regenerates nginx after we run — re-apply shortly after it starts.
(
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 3
    apply_php_uploads
    apply_nginx_uploads
  done
) >/home/LogFiles/power-sweeper-startup.log 2>&1 &

# Keep the container entrypoint's default PHP/nginx stack running.
if [[ -x /usr/local/bin/entrypoint.sh ]]; then
  exec /usr/local/bin/entrypoint.sh
fi
if [[ -x /opt/startup/startup.sh ]]; then
  exec /opt/startup/startup.sh
fi
