#!/usr/bin/env bash
# Run from a shell that has write access to freementls/power_sweeper
set -euo pipefail
cd "$(dirname "$0")"
git remote set-url origin https://github.com/freementls/power_sweeper.git
git push -u origin main
git push -u origin cursor/power-sweeper-mvp-65f8
echo "Pushed. Open a PR from cursor/power-sweeper-mvp-65f8 → main"
