#!/usr/bin/env bash
# Azure App Service (Linux PHP) startup command.
# Portal → Configuration → General settings → Startup Command:
#   bash /home/site/wwwroot/azure/startup.sh
#
# Also set Application Setting (recommended):
#   PHP_INI_SCAN_DIR = /usr/local/etc/php/conf.d:/home/site/ini
set -euo pipefail

ROOT="${HOME_SITE_WWWROOT:-/home/site/wwwroot}"
LOG="${DIAGNOSTICS_LOGGINGDIRECTORY:-/home/LogFiles}/power-sweeper-startup.log"
cd "$ROOT"

mkdir -p storage/tmp storage/out "$(dirname "$LOG")" 2>/dev/null || true
chmod -R ug+rwX storage || true
exec >>"$LOG" 2>&1
echo "==== $(date -u +%Y-%m-%dT%H:%M:%SZ) power-sweeper startup ===="

if [[ -f composer.json ]]; then
  if [[ ! -d vendor ]]; then
    if command -v composer >/dev/null 2>&1; then
      composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    elif [[ -f composer.phar ]]; then
      php composer.phar install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    else
      echo "WARNING: composer not found; vendor/ missing"
    fi
  fi
fi

apply_php_uploads() {
  local src="$ROOT/azure/php-uploads.ini"
  [[ -f "$src" ]] || { echo "no php-uploads.ini"; return 0; }

  # Dotfiles are often skipped by OneDeploy — rewrite from non-hidden source.
  cp -f "$src" "$ROOT/.user.ini" || true
  cp -f "$src" "$ROOT/api/.user.ini" || true
  cp -f "$src" "$ROOT/public/.user.ini" || true

  mkdir -p /home/site/ini 2>/dev/null || true
  cp -f "$src" /home/site/ini/power-sweeper-uploads.ini || true

  for dir in \
    /usr/local/etc/php/conf.d \
    /etc/php/*/fpm/conf.d \
    /etc/php/*/cli/conf.d \
    /usr/local/php/etc/conf.d
  do
    for expanded in $dir; do
      [[ -d "$expanded" ]] || continue
      cp -f "$src" "$expanded/zz-power-sweeper-uploads.ini" || true
      echo "php ini -> $expanded/zz-power-sweeper-uploads.ini"
    done
  done
}

bump_body_size_in_file() {
  local f="$1"
  [[ -f "$f" ]] || return 0
  if grep -qE 'client_max_body_size' "$f"; then
    sed -i -E 's/client_max_body_size[[:space:]]+[0-9]+[mMkKgG]?;/client_max_body_size 512M;/g' "$f" || true
  fi
}

apply_nginx_uploads() {
  local src="$ROOT/azure/nginx.conf"
  [[ -f "$src" ]] || { echo "no azure/nginx.conf"; return 0; }

  mkdir -p /home/site /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null || true

  # Azure PHP images commonly honor /home/site/default as the site config.
  cp -f "$src" /home/site/default || true
  cp -f "$src" /etc/nginx/sites-available/default 2>/dev/null || true
  cp -f "$src" /etc/nginx/sites-enabled/default 2>/dev/null || true
  cp -f "$src" /etc/nginx/conf.d/default.conf 2>/dev/null || true

  # Force 512M anywhere a body size is already declared (Oryx often uses 1m).
  while IFS= read -r -d '' conf; do
    bump_body_size_in_file "$conf"
  done < <(find /etc/nginx /home/site -type f \( -name '*.conf' -o -name 'default' -o -name 'nginx.conf' \) -print0 2>/dev/null)

  if [[ -f /etc/nginx/nginx.conf ]] && ! grep -qE 'client_max_body_size' /etc/nginx/nginx.conf; then
    sed -i '/http[[:space:]]*{/a\    client_max_body_size 512M;' /etc/nginx/nginx.conf || true
  fi

  # Drop-in server snippet used by some images that include conf.d/*.conf inside http{}.
  cat >/etc/nginx/conf.d/zz-power-sweeper-uploads.conf 2>/dev/null <<'EOF' || true
# Raised by Power Sweeper azure/startup.sh — must live inside http{} (conf.d is).
client_max_body_size 512M;
EOF

  if command -v nginx >/dev/null 2>&1; then
    if nginx -t; then
      nginx -s reload && echo "nginx reloaded" || echo "nginx reload failed"
    else
      echo "nginx -t failed"
    fi
  else
    echo "nginx binary not found yet"
  fi
}

apply_php_uploads
apply_nginx_uploads
echo "php upload_max_filesize=$(php -r 'echo ini_get("upload_max_filesize");' 2>/dev/null || echo '?')"
echo "php post_max_size=$(php -r 'echo ini_get("post_max_size");' 2>/dev/null || echo '?')"

# Platform entrypoint often regenerates nginx after Startup Command returns.
# Keep re-applying for a few minutes so the first request after boot is covered.
(
  for i in $(seq 1 40); do
    sleep 3
    apply_php_uploads
    apply_nginx_uploads
    echo "re-apply pass $i ok"
  done
) &

# Keep the container entrypoint's default PHP/nginx stack running.
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
