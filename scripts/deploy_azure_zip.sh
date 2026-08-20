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
#   SKIP_PACKAGE=1      skip rebuild; use existing zip
#
# Example:
#   AZURE_WEBAPP_PUBLISH_PROFILE=~/powersweeperfree.PublishSettings \
#     bash scripts/deploy_azure_zip.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_NAME="${AZURE_WEBAPP_NAME:-powersweeperfree}"
ZIP_PATH="${AZURE_ZIP_PATH:-$ROOT/storage/out/power_sweeper-azure.zip}"
PROFILE_RAW="${AZURE_WEBAPP_PUBLISH_PROFILE:-}"

if [[ -z "$PROFILE_RAW" ]]; then
  echo "Set AZURE_WEBAPP_PUBLISH_PROFILE to the publish-profile XML (or a file path)." >&2
  exit 1
fi

PROFILE_XML="$PROFILE_RAW"
if [[ -f "$PROFILE_RAW" ]]; then
  PROFILE_XML="$(cat "$PROFILE_RAW")"
fi

# Prefer MSDeploy publish profile (has userPWD); fall back to first profile with userName.
parse_attr() {
  local xml="$1" attr="$2"
  printf '%s' "$xml" | tr '\n' ' ' | sed -n "s/.*${attr}=\"\([^\"]*\)\".*/\1/p" | head -1
}

# Extract the MSDeploy profile block when present.
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

# publishUrl is often host:443 — Kudu Zip Deploy uses https://{app}.scm.azurewebsites.net/api/zipdeploy
SCM_HOST="${APP_NAME}.scm.azurewebsites.net"
if [[ -n "$PUBLISH_URL" && "$PUBLISH_URL" == *scm.azurewebsites.net* ]]; then
  SCM_HOST="$(printf '%s' "$PUBLISH_URL" | sed 's/:443$//; s#^https\?://##; s#/.*##')"
fi
DEPLOY_URL="https://${SCM_HOST}/api/zipdeploy?isAsync=true"

if [[ "${SKIP_PACKAGE:-0}" != "1" ]]; then
  bash "$ROOT/scripts/package_azure_zip.sh" "$ZIP_PATH"
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "Missing deploy zip: $ZIP_PATH" >&2
  exit 1
fi

echo "Deploying $(basename "$ZIP_PATH") ($(du -h "$ZIP_PATH" | awk '{print $1}')) → $APP_NAME"
echo "POST $DEPLOY_URL"

HTTP_CODE="$(
  curl -sS -m 600 \
    -u "${USER}:${PASS}" \
    -H 'Content-Type: application/zip' \
    --data-binary @"$ZIP_PATH" \
    -o /tmp/azure-zipdeploy-body.txt \
    -w '%{http_code}' \
    "$DEPLOY_URL"
)"

echo "HTTP $HTTP_CODE"
if [[ -s /tmp/azure-zipdeploy-body.txt ]]; then
  head -c 2000 /tmp/azure-zipdeploy-body.txt
  echo
fi

if [[ "$HTTP_CODE" != "200" && "$HTTP_CODE" != "202" ]]; then
  echo "Zip Deploy failed." >&2
  exit 1
fi

echo "Zip Deploy accepted. Site: https://${APP_NAME}.azurewebsites.net/"
echo "Startup Command should remain: bash /home/site/wwwroot/azure/startup.sh"
