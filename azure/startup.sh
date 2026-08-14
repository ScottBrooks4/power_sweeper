#!/usr/bin/env bash
# Azure App Service (Linux PHP) startup command.
# Portal → Configuration → General settings → Startup Command:
#   bash /home/site/wwwroot/azure/startup.sh
#
# Recommended Application Setting:
#   PHP_INI_SCAN_DIR = /usr/local/etc/php/conf.d:/home/site/ini
set -euo pipefail

ROOT="${HOME_SITE_WWWROOT:-/home/site/wwwroot}"
LOG="${DIAGNOSTICS_LOGGINGDIRECTORY:-/home/LogFiles}/power-sweeper-startup.log"
NGINX_GOOD="$ROOT/azure/nginx-app-service-default.conf"
cd "$ROOT"

mkdir -p storage/tmp storage/out "$(dirname "$LOG")" 2>/dev/null || true
chmod -R ug+rwX storage || true
exec >>"$LOG" 2>&1
echo "==== $(date -u +%Y-%m-%dT%H:%M:%SZ) power-sweeper startup ===="

if [[ -f composer.json && ! -d vendor ]]; then
  if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
  elif [[ -f composer.phar ]]; then
    php composer.phar install --no-dev --no-interaction --prefer-dist --optimize-autoloader
  else
    echo "WARNING: composer not found; vendor/ missing"
  fi
fi

apply_php_uploads() {
  local src="$ROOT/azure/php-uploads.ini"
  [[ -f "$src" ]] || return 0

  cp -f "$src" "$ROOT/.user.ini" || true
  cp -f "$src" "$ROOT/api/.user.ini" || true
  cp -f "$src" "$ROOT/public/.user.ini" || true

  mkdir -p /home/site/ini 2>/dev/null || true
  cp -f "$src" /home/site/ini/power-sweeper-uploads.ini || true

  for expanded in \
    /usr/local/etc/php/conf.d \
    /etc/php/*/fpm/conf.d \
    /etc/php/*/cli/conf.d \
    /usr/local/php/etc/conf.d
  do
    [[ -d "$expanded" ]] || continue
    cp -f "$src" "$expanded/zz-power-sweeper-uploads.ini" || true
  done
}

bump_body_size_in_file() {
  local f="$1"
  [[ -f "$f" && -w "$f" ]] || return 0
  if grep -qE 'client_max_body_size' "$f"; then
    sed -i -E 's/client_max_body_size[[:space:]]+[0-9]+[mMkKgG]?;/client_max_body_size 512M;/g' "$f" || true
  fi
}

restore_nginx() {
  # /home/site/default persists across restarts. A previous broken overlay
  # caused site-wide 404s — always replace it with the known-good template.
  mkdir -p /home/site /etc/nginx/sites-enabled /etc/nginx/sites-available /etc/nginx/conf.d 2>/dev/null || true

  if [[ -f "$NGINX_GOOD" ]]; then
    cp -f "$NGINX_GOOD" /home/site/default
    cp -f "$NGINX_GOOD" /etc/nginx/sites-enabled/default 2>/dev/null || true
    cp -f "$NGINX_GOOD" /etc/nginx/sites-available/default 2>/dev/null || true
    echo "installed known-good nginx site config"
  fi

  # Remove leftover broken drop-ins from earlier attempts.
  rm -f /etc/nginx/conf.d/zz-power-sweeper-uploads.conf 2>/dev/null || true
  if [[ -f /etc/nginx/conf.d/default.conf ]] && grep -q 'location \^~ /api/' /etc/nginx/conf.d/default.conf 2>/dev/null; then
    rm -f /etc/nginx/conf.d/default.conf
    echo "removed broken conf.d/default.conf"
  fi

  while IFS= read -r -d '' conf; do
    bump_body_size_in_file "$conf"
  done < <(find /etc/nginx -type f \( -name '*.conf' -o -name 'default' -o -name 'nginx.conf' \) -print0 2>/dev/null)

  if [[ -f /etc/nginx/nginx.conf ]] && ! grep -qE 'client_max_body_size' /etc/nginx/nginx.conf; then
    sed -i '/http[[:space:]]*{/a\    client_max_body_size 512M;' /etc/nginx/nginx.conf || true
  fi

  cat >/etc/nginx/conf.d/zz-power-sweeper-body-size.conf 2>/dev/null <<'EOF' || true
client_max_body_size 512M;
EOF

  if command -v nginx >/dev/null 2>&1; then
    if nginx -t; then
      # App Service images accept either reload form.
      nginx -s reload 2>/dev/null || service nginx reload 2>/dev/null || true
      echo "nginx reloaded"
    else
      echo "nginx -t failed"
      nginx -t 2>&1 || true
    fi
  else
    echo "nginx not available yet"
  fi
}

apply_php_uploads
restore_nginx
echo "php upload_max_filesize=$(php -r 'echo ini_get("upload_max_filesize");' 2>/dev/null || echo '?')"

(
  for i in $(seq 1 20); do
    sleep 3
    apply_php_uploads
    restore_nginx
    echo "re-apply pass $i ok"
  done
) &

if [[ -x /usr/local/bin/entrypoint.sh ]]; then
  echo "exec entrypoint.sh"
  exec /usr/local/bin/entrypoint.sh
fi
if [[ -x /opt/startup/startup.sh ]]; then
  echo "exec /opt/startup/startup.sh"
  exec /opt/startup/startup.sh
fi

echo "WARNING: no platform entrypoint found; sleeping"
sleep infinity
