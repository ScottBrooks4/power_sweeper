#!/usr/bin/env bash
# Build the Azure deploy zip and publish/replace the rolling GitHub release
# tag `azure-latest` on freementls/power_sweeper.
#
# Requires: gh auth with contents write; composer; zip
#
# Usage:
#   bash scripts/publish_azure_release.sh
#   bash scripts/publish_azure_release.sh --skip-package   # reuse existing zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP_PATH="${AZURE_ZIP_PATH:-$ROOT/storage/out/power_sweeper-azure.zip}"
TAG="${AZURE_RELEASE_TAG:-azure-latest}"
REPO="${GITHUB_REPOSITORY:-freementls/power_sweeper}"
SKIP_PACKAGE=0

for arg in "$@"; do
  case "$arg" in
    --skip-package) SKIP_PACKAGE=1 ;;
    *) echo "Unknown arg: $arg" >&2; exit 1 ;;
  esac
done

cd "$ROOT"
SHA="$(git rev-parse --short HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
DATE_UTC="$(date -u +%Y-%m-%dT%H:%MZ)"

if [[ "$SKIP_PACKAGE" != "1" ]]; then
  bash "$ROOT/scripts/package_azure_zip.sh" "$ZIP_PATH"
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "Missing zip: $ZIP_PATH" >&2
  exit 1
fi

NOTES="$(cat <<EOF
## Azure deploy zip (rolling)

- **Commit:** \`${SHA}\` on \`${BRANCH}\`
- **Built:** ${DATE_UTC}
- **App:** powersweeperfree

### Deploy options (pick one)

1. **Azure Portal Zip Deploy** — App Service → Deployment Center / Advanced Tools (Kudu) → Zip Deploy upload \`power_sweeper-azure.zip\`. No GitHub access needed from a locked-down laptop.
2. **Agent / CLI** — with publish profile set:
   \`\`\`bash
   AZURE_WEBAPP_PUBLISH_PROFILE=/path/to/profile.xml bash scripts/deploy_azure_zip.sh
   \`\`\`
3. **GitHub Actions** — push \`main\` or \`cursor/deploy-latest-150e\`, or run **Build and deploy PHP app to Azure Web App - powersweeperfree** → Run workflow (needs \`AZURE_WEBAPP_PUBLISH_PROFILE\` secret on this repo).

Startup Command:

\`\`\`bash
bash /home/site/wwwroot/azure/startup.sh
\`\`\`
EOF
)"

if gh release view "$TAG" -R "$REPO" >/dev/null 2>&1; then
  gh release upload "$TAG" "$ZIP_PATH" -R "$REPO" --clobber
  gh release edit "$TAG" -R "$REPO" --title "Azure deploy zip (latest: ${SHA})" --notes "$NOTES"
else
  gh release create "$TAG" "$ZIP_PATH" -R "$REPO" \
    --title "Azure deploy zip (latest: ${SHA})" \
    --notes "$NOTES"
fi

echo "Published $TAG → https://github.com/${REPO}/releases/tag/${TAG}"
gh release view "$TAG" -R "$REPO" --json url,tagName,assets --jq '{url,tagName,assets:[.assets[].name]}'
