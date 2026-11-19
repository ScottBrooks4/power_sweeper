#!/usr/bin/env bash
# Self-contained Git Bash Zip Deploy for a laptop copy of the site zip + publish profile.
# Drop this next to power_sweeper-azure.zip and powersweeperfree.PublishSettings, then:
#   bash gitbash_zipdeploy.sh
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

ZIP="${AZURE_ZIP_PATH:-$DIR/power_sweeper-azure.zip}"
PROFILE="${AZURE_WEBAPP_PUBLISH_PROFILE:-$DIR/powersweeperfree.PublishSettings}"
APP_NAME="${AZURE_WEBAPP_NAME:-powersweeperfree}"

if command -v curl.exe >/dev/null 2>&1; then
  CURL_BIN=curl.exe
else
  CURL_BIN=curl
fi

die() { echo "ERROR: $*" >&2; exit 1; }

[[ -f "$ZIP" ]] || die "Missing $ZIP"
[[ -f "$PROFILE" ]] || die "Missing $PROFILE — Azure Portal → Get publish profile → save here"

FLAT="$(tr '\n' ' ' <"$PROFILE")"
USER="$(printf '%s' "$FLAT" | sed -n 's/.*userName="\([^"]*\)".*/\1/p' | head -1)"
PASS="$(printf '%s' "$FLAT" | sed -n 's/.*userPWD="\([^"]*\)".*/\1/p' | head -1)"
PUBLISH_URL="$(printf '%s' "$FLAT" | sed -n 's/.*publishUrl="\([^"]*\)".*/\1/p' | head -1)"

[[ -n "$USER" && -n "$PASS" ]] || die "Could not parse userName/userPWD from $PROFILE"

if [[ -n "${AZURE_SCM_HOST:-}" ]]; then
  SCM_HOST="$AZURE_SCM_HOST"
elif [[ -n "$PUBLISH_URL" ]]; then
  SCM_HOST="$(printf '%s' "$PUBLISH_URL" | sed 's/:443$//; s#^https\?://##; s#/.*##')"
else
  SCM_HOST="${APP_NAME}.scm.azurewebsites.net"
fi

DEPLOY_URL="https://${SCM_HOST}/api/zipdeploy?isAsync=true"

echo "curl=$CURL_BIN"
echo "zip=$ZIP ($(ls -lh "$ZIP" | awk '{print $5}'))"
echo "scm=$SCM_HOST"
echo "user=$USER"
echo
echo "Probing SCM (expect HTTP 401 without auth)..."
PROBE="$("$CURL_BIN" -4 -sS -m 15 -o /dev/null -w '%{http_code}' "https://${SCM_HOST}/" || true)"
echo "probe HTTP $PROBE"
if [[ "$PROBE" == "000" || -z "$PROBE" ]]; then
  die "Cannot connect to https://${SCM_HOST}/ — VPN? firewall? Run azure_gitbash_doctor.sh"
fi

echo "Uploading (async zipdeploy)..."
HTTP_CODE="$("$CURL_BIN" -4 -sS -m 600 \
  -u "${USER}:${PASS}" \
  -H 'Content-Type: application/zip' \
  --data-binary @"$ZIP" \
  -o /tmp/ps_zipdeploy_body.txt \
  -w '%{http_code}' \
  "$DEPLOY_URL" || true)"

echo "HTTP $HTTP_CODE"
[[ -s /tmp/ps_zipdeploy_body.txt ]] && head -c 500 /tmp/ps_zipdeploy_body.txt && echo

if [[ "$HTTP_CODE" != "200" && "$HTTP_CODE" != "202" ]]; then
  die "Zip Deploy failed (HTTP $HTTP_CODE)"
fi

echo "OK — Zip Deploy accepted."
echo "Portal → Restart powersweeperfree"
echo "Startup Command: bash /home/site/wwwroot/azure/startup.sh"
echo "Site: https://${APP_NAME}.azurewebsites.net/"
