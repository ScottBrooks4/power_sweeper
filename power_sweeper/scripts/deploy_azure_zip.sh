#!/usr/bin/env bash
# Build (optional) and Zip Deploy to Azure App Service via Kudu.
#
# Auth: set AZURE_WEBAPP_PUBLISH_PROFILE to either:
#   - path to a downloaded publish-profile XML file, or
#   - the raw XML content itself
#
# Optional:
#   AZURE_WEBAPP_NAME   (default: powersweeperfree)
#   AZURE_ZIP_PATH      (default: storage/out/power_sweeper-azure.zip)
#   AZURE_SCM_HOST      override SCM hostname (from azure_gitbash_doctor.sh)
#   SKIP_PACKAGE=1      skip rebuild; use existing zip
#
# Git Bash / Windows tips:
#   Prefer Windows curl.exe and IPv4. Run scripts/azure_gitbash_doctor.sh first.
#
# Example:
#   AZURE_WEBAPP_PUBLISH_PROFILE=~/powersweeperfree.PublishSettings \
#   SKIP_PACKAGE=1 \
#     bash scripts/deploy_azure_zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_NAME="${AZURE_WEBAPP_NAME:-powersweeperfree}"
ZIP_PATH="${AZURE_ZIP_PATH:-$ROOT/storage/out/power_sweeper-azure.zip}"
PROFILE_RAW="${AZURE_WEBAPP_PUBLISH_PROFILE:-}"

pick_curl() {
  if command -v curl.exe >/dev/null 2>&1; then
    echo "curl.exe"
  else
    echo "curl"
  fi
}
CURL_BIN="$(pick_curl)"

if [[ -z "$PROFILE_RAW" ]]; then
  # Convenience: profile in cwd (common on laptop ~/power_sweeper)
  for cand in \
    "$PWD/powersweeperfree.PublishSettings" \
    "$ROOT/powersweeperfree.PublishSettings" \
    "$HOME/powersweeperfree.PublishSettings" \
    "$HOME/Downloads/powersweeperfree.PublishSettings"
  do
    if [[ -f "$cand" ]]; then
      PROFILE_RAW="$cand"
      break
    fi
  done
fi

if [[ -z "$PROFILE_RAW" ]]; then
  echo "Set AZURE_WEBAPP_PUBLISH_PROFILE to the publish-profile XML (or a file path)." >&2
  echo "Or place powersweeperfree.PublishSettings in the current directory." >&2
  exit 1
fi

PROFILE_XML="$PROFILE_RAW"
if [[ -f "$PROFILE_RAW" ]]; then
  PROFILE_XML="$(cat "$PROFILE_RAW")"
fi

parse_attr() {
  local xml="$1" attr="$2"
  printf '%s' "$xml" | tr '\n' ' ' | sed -n "s/.*${attr}=\"\([^\"]*\)\".*/\1/p" | head -1
}

MSDEPLOY_BLOCK="$(printf '%s' "$PROFILE_XML" | tr '\n' ' ' | sed -n 's/.*\(<publishProfile[^>]*publishMethod="MSDeploy"[^>]*\/>\).*/\1/p')"
if [[ -z "$MSDEPLOY_BLOCK" ]]; then
  MSDEPLOY_BLOCK="$(printf '%s' "$PROFILE_XML" | tr '\n' ' ' | sed -n 's/.*\(<publishProfile[^>]*userName="[^"]*"[^>]*\/>\).*/\1/p')"
fi
if [[ -z "$MSDEPLOY_BLOCK" ]]; then
  echo "Could not find a usable <publishProfile> in AZURE_WEBAPP_PUBLISH_PROFILE." >&2
  exit 1
fi

USER="$(parse_attr "$MSDEPLOY_BLOCK" userName)"
PASS="$(parse_attr "$MSDEPLOY_BLOCK" userPWD)"
PUBLISH_URL="$(parse_attr "$MSDEPLOY_BLOCK" publishUrl)"

if [[ -z "$USER" || -z "$PASS" ]]; then
  echo "publish profile is missing userName/userPWD." >&2
  exit 1
fi

# Prefer explicit override, then publishUrl host (incl. regional *.scm.canadacentral-01.*),
# then classic short SCM name.
if [[ -n "${AZURE_SCM_HOST:-}" ]]; then
  SCM_HOST="$AZURE_SCM_HOST"
elif [[ -n "$PUBLISH_URL" ]]; then
  SCM_HOST="$(printf '%s' "$PUBLISH_URL" | sed 's/:443$//; s#^https\?://##; s#/.*##')"
else
  SCM_HOST="${APP_NAME}.scm.azurewebsites.net"
fi

DEPLOY_URL="https://${SCM_HOST}/api/zipdeploy?isAsync=true"

if [[ "${SKIP_PACKAGE:-0}" != "1" ]]; then
  if [[ -x "$ROOT/scripts/package_azure_zip.sh" ]] || [[ -f "$ROOT/scripts/package_azure_zip.sh" ]]; then
    bash "$ROOT/scripts/package_azure_zip.sh" "$ZIP_PATH"
  fi
fi

# Laptop convenience: zip in cwd
if [[ ! -f "$ZIP_PATH" && -f "$PWD/power_sweeper-azure.zip" ]]; then
  ZIP_PATH="$PWD/power_sweeper-azure.zip"
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "Missing deploy zip: $ZIP_PATH" >&2
  echo "Copy power_sweeper-azure.zip into this folder, or set AZURE_ZIP_PATH." >&2
  exit 1
fi

echo "curl=$CURL_BIN"
echo "Deploying $(basename "$ZIP_PATH") ($(du -h "$ZIP_PATH" 2>/dev/null | awk '{print $1}' || ls -lh "$ZIP_PATH")) → $APP_NAME"
echo "POST $DEPLOY_URL"

BODY="$(mktemp)"
HTTP_CODE="$("$CURL_BIN" -4 -sS -m 600 \
  -u "${USER}:${PASS}" \
  -H 'Content-Type: application/zip' \
  --data-binary @"$ZIP_PATH" \
  -o "$BODY" \
  -w '%{http_code}' \
  "$DEPLOY_URL" || true)"

echo "HTTP $HTTP_CODE"
if [[ -s "$BODY" ]]; then
  head -c 2000 "$BODY"
  echo
fi
rm -f "$BODY"

if [[ "$HTTP_CODE" != "200" && "$HTTP_CODE" != "202" ]]; then
  echo "Zip Deploy failed." >&2
  echo "Run: bash scripts/azure_gitbash_doctor.sh \"$PROFILE_RAW\"" >&2
  exit 1
fi

echo "Zip Deploy accepted. Site: https://${APP_NAME}.azurewebsites.net/"
echo "Startup Command should remain: bash /home/site/wwwroot/azure/startup.sh"
echo "Portal → Restart the app if the site does not refresh within a minute."
