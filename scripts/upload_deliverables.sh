#!/usr/bin/env bash
# Upload Power Sweeper deliverables for sneakernet / work-PC download.
# Prefers ffsend (Firefox Send) for small files; litterbox for large .msapp (>~1MB).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="${1:-$ROOT/samples/import_debug}"
OUT="$DIR/DOWNLOAD_LINKS.txt"
FF="${FFSEND_BIN:-ffsend}"

if ! command -v "$FF" >/dev/null 2>&1; then
  FF="/tmp/ffsend"
  if [[ ! -x "$FF" ]]; then
    curl -sL "https://github.com/timvisee/ffsend/releases/download/v0.2.77/ffsend-v0.2.77-linux-x64" -o "$FF"
    chmod +x "$FF"
  fi
fi

upload_ffs() {
  local file="$1"
  local label="$2"
  if FFSEND_TIMEOUT=300 FFSEND_TRANSFER_TIMEOUT=600 "$FF" upload -h "https://send.vis.ee/" -e 7d "$file" 2>/dev/null | rg "Share link:" -o | sed 's/Share link:[[:space:]]*//' ; then
    echo "ffs|$label|$(basename "$file")|$link" >> "$OUT.tmp"
    return 0
  fi
  return 1
}

upload_litter() {
  local file="$1"
  local label="$2"
  local url
  url=$(curl -sS -F "reqtype=fileupload" -F "time=72h" -F "fileToUpload=@${file}" \
    https://litterbox.catbox.moe/resources/internals/api.php)
  echo "litterbox|$label|$(basename "$file")|$url" >> "$OUT.tmp"
  echo "$url"
}

: > "$OUT.tmp"
{
  echo "Power Sweeper deliverables — download links"
  echo "Generated: $(date -u +"%Y-%m-%d %H:%M UTC")"
  echo ""
  echo "START HERE (repair + dark):"
} > "$OUT"

FILES=(
  "CDLS_L_VCR_App_16.repair_then_dark.msapp|repair_then_dark (recommended)"
  "CDLS_L_VCR_App_16.repaired.msapp|repaired only"
  "CDLS (L) VCR App (16).msapp|original App 16"
  "CDLS_L_VCR_App_16.post_repair_validation.txt|post-repair check"
  "CDLS_L_VCR_App_16.errors.txt|pre-repair error list"
)

for entry in "${FILES[@]}"; do
  IFS='|' read -r name label <<< "$entry"
  path="$DIR/$name"
  [[ -f "$path" ]] || continue
  echo "Uploading $name ..."
  url=""
  if [[ $(stat -c%s "$path") -lt 500000 ]]; then
    url=$("$FF" upload -h "https://send.vis.ee/" -e 7d "$path" 2>/dev/null | rg "https://send\.vis\.ee/[^\s]+" -o || true)
  fi
  if [[ -z "$url" ]]; then
    url=$(upload_litter "$path" "$label")
    echo "  litterbox: $url" >> "$OUT"
  else
    echo "  ffsend: $url" >> "$OUT"
  fi
done

if [[ -f "$DIR/CDLS_L_VCR_App_16_pack.zip" ]]; then
  echo "" >> "$OUT"
  echo "All-in-one zip:" >> "$OUT"
  url=$(upload_litter "$DIR/CDLS_L_VCR_App_16_pack.zip" "full pack")
  echo "  $url" >> "$OUT"
fi

rm -f "$OUT.tmp"
echo "" >> "$OUT"
echo "Links expire: ffsend 7 days, litterbox 72 hours." >> "$OUT"
cat "$OUT"
