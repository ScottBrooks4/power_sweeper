#!/usr/bin/env bash
# Diagnose Git Bash → Azure Kudu (SCM) connectivity for Zip Deploy.
#
# Usage (from repo or laptop copy of this script):
#   bash scripts/azure_gitbash_doctor.sh [path/to/powersweeperfree.PublishSettings]
#
# Exit 0 if HTTPS to SCM succeeds (HTTP 401 without auth is OK).
set -euo pipefail

PROFILE="${1:-${AZURE_WEBAPP_PUBLISH_PROFILE:-powersweeperfree.PublishSettings}}"
APP_NAME="${AZURE_WEBAPP_NAME:-powersweeperfree}"

echo "=== Azure Git Bash doctor ==="
echo "pwd=$(pwd)"
echo "uname=$(uname -a 2>/dev/null || true)"
echo "shell=$SHELL"
echo

pick_curl() {
  if command -v curl.exe >/dev/null 2>&1; then
    echo "curl.exe"
  elif command -v curl >/dev/null 2>&1; then
    echo "curl"
  else
    echo ""
  fi
}

CURL_BIN="$(pick_curl)"
echo "curl_bin=${CURL_BIN:-MISSING}"
if [[ -n "$CURL_BIN" ]]; then
  "$CURL_BIN" --version 2>&1 | head -2
fi
echo

echo "=== Proxy env ==="
env | grep -i proxy || echo "(none)"
if command -v netsh.exe >/dev/null 2>&1; then
  echo "--- winhttp ---"
  netsh.exe winhttp show proxy || true
fi
echo

echo "=== Publish profile ==="
if [[ -f "$PROFILE" ]]; then
  echo "profile_file=$PROFILE ($(wc -c <"$PROFILE") bytes)"
  # Flatten for attribute scrape
  FLAT="$(tr '\n' ' ' <"$PROFILE")"
  USER="$(printf '%s' "$FLAT" | sed -n 's/.*userName="\([^"]*\)".*/\1/p' | head -1)"
  PASS="$(printf '%s' "$FLAT" | sed -n 's/.*userPWD="\([^"]*\)".*/\1/p' | head -1)"
  PUBLISH_URL="$(printf '%s' "$FLAT" | sed -n 's/.*publishUrl="\([^"]*\)".*/\1/p' | head -1)"
  echo "userName=${USER:-MISSING}"
  echo "userPWD=$([[ -n "${PASS:-}" ]] && echo set || echo MISSING)"
  echo "publishUrl=${PUBLISH_URL:-MISSING}"
else
  echo "profile_file=$PROFILE MISSING"
  echo "Pass path: bash scripts/azure_gitbash_doctor.sh /c/Users/YOU/powersweeperfree.PublishSettings"
  USER=""; PASS=""; PUBLISH_URL=""
fi
echo

# Candidate SCM hosts (regional publishUrl first)
HOSTS=()
if [[ -n "${PUBLISH_URL:-}" ]]; then
  H="$(printf '%s' "$PUBLISH_URL" | sed 's/:443$//; s#^https\?://##; s#/.*##')"
  HOSTS+=("$H")
fi
HOSTS+=("${APP_NAME}.scm.azurewebsites.net")
# Known regional host from prior successful browser Kudu session
HOSTS+=("powersweeperfree-h0cueaefhacbf0e8.scm.canadacentral-01.azurewebsites.net")

# Dedupe
declare -A SEEN=()
UNIQUE_HOSTS=()
for H in "${HOSTS[@]}"; do
  [[ -z "$H" ]] && continue
  [[ -n "${SEEN[$H]:-}" ]] && continue
  SEEN[$H]=1
  UNIQUE_HOSTS+=("$H")
done

echo "=== DNS ==="
for H in "${UNIQUE_HOSTS[@]}"; do
  echo "--- $H ---"
  if command -v nslookup.exe >/dev/null 2>&1; then
    nslookup.exe "$H" 2>&1 | head -12 || true
  elif command -v nslookup >/dev/null 2>&1; then
    nslookup "$H" 2>&1 | head -12 || true
  else
    getent hosts "$H" 2>&1 || true
  fi
done
echo

echo "=== TCP 443 (PowerShell Test-NetConnection) ==="
if command -v powershell.exe >/dev/null 2>&1; then
  for H in "${UNIQUE_HOSTS[@]}"; do
    powershell.exe -NoProfile -Command \
      "\$r=Test-NetConnection -ComputerName '$H' -Port 443 -WarningAction SilentlyContinue; Write-Output (\"$H TcpTestSucceeded=\" + \$r.TcpTestSucceeded + \" RemoteAddress=\" + \$r.RemoteAddress)" \
      2>&1 || true
  done
else
  echo "(powershell.exe not available)"
fi
echo

echo "=== HTTPS probe (expect 401 without creds = REACHABLE) ==="
OK_HOST=""
if [[ -z "$CURL_BIN" ]]; then
  echo "No curl available."
  exit 1
fi

for H in "${UNIQUE_HOSTS[@]}"; do
  URL="https://${H}/"
  echo -n "$H ... "
  CODE="$("$CURL_BIN" -4 -sS -m 15 -o /dev/null -w '%{http_code}' "$URL" 2>/tmp/azure_doctor_curl_err || true)"
  if [[ -z "$CODE" || "$CODE" == "000" ]]; then
    echo "FAIL"
    head -c 300 /tmp/azure_doctor_curl_err 2>/dev/null; echo
  else
    echo "HTTP $CODE"
    if [[ "$CODE" =~ ^(200|401|403)$ ]]; then
      OK_HOST="$H"
    fi
  fi
done
echo

if [[ -z "$OK_HOST" ]]; then
  echo "RESULT: No SCM host reachable on HTTPS:443 from this shell."
  echo
  echo "Next checks on the corp laptop:"
  echo "  1) Connect VPN if Azure requires it, then re-run this doctor."
  echo "  2) Try Windows Terminal PowerShell (not Git Bash) — same commands with curl.exe."
  echo "  3) Compare: browser can open https://<scm-host>/ — if browser works but this fails,"
  echo "     IT may be filtering non-browser TLS (SSL inspection / process allowlist)."
  echo "  4) Ask IT to allow outbound HTTPS to *.scm.azurewebsites.net and"
  echo "     *.scm.canadacentral-01.azurewebsites.net (or the exact host above)."
  exit 2
fi

echo "RESULT: SCM reachable via https://${OK_HOST}/"
export AZURE_SCM_HOST="$OK_HOST"
echo "export AZURE_SCM_HOST=$OK_HOST"

if [[ -n "${USER:-}" && -n "${PASS:-}" ]]; then
  echo
  echo "=== Authenticated VFS list (first entries) ==="
  "$CURL_BIN" -4 -sS -m 30 -u "${USER}:${PASS}" \
    "https://${OK_HOST}/api/vfs/site/wwwroot/" 2>&1 | head -c 800
  echo
  echo
  echo "If you see JSON with href/name, auth works. Deploy with:"
  echo "  AZURE_WEBAPP_PUBLISH_PROFILE=$PROFILE AZURE_SCM_HOST=$OK_HOST SKIP_PACKAGE=1 \\"
  echo "    bash scripts/deploy_azure_zip.sh"
fi

exit 0
