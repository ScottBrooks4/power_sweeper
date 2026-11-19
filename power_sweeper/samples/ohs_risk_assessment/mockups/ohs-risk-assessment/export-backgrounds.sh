#!/usr/bin/env bash
# Re-export 1366x768 PNG backgrounds from the HTML screens.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/backgrounds"
mkdir -p "$OUT"
PORT="${PORT:-8765}"
python3 -m http.server "$PORT" --directory "$ROOT" >/tmp/ohs-mockup-http.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
sleep 0.4
for f in 01-dashboard 02-inbox 03-review 04-treat; do
  timeout 16 google-chrome --headless --disable-gpu --no-sandbox --no-first-run \
    --no-default-browser-check --hide-scrollbars --force-device-scale-factor=1 \
    --window-size=1366,768 \
    --screenshot="$OUT/${f}.png" \
    "http://127.0.0.1:${PORT}/screens/${f}.html" >/dev/null 2>&1 || true
  pkill -9 chrome 2>/dev/null || true
  echo "wrote $OUT/${f}.png"
done
