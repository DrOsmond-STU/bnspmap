#!/usr/bin/env bash
# Perbarui js/lsp-data.js dari bnsp.go.id.
# Butuh: python3, koneksi ke bnsp.go.id, nominatim.openstreetmap.org, dan registry npm (untuk idn-area-data).
# Lama proses: ±30–60 menit (detail ±2.700 halaman + geocoding Nominatim 1 permintaan/detik).
set -euo pipefail
cd "$(dirname "$0")/.."
W=work
rm -rf "$W/pages" "$W/detail" "$W/lspmap.json"      # ambil ulang data BNSP; cache geocoding di $W/geo dipertahankan
mkdir -p "$W"
if [ ! -d "$W/idn-area/package/data" ]; then
  mkdir -p "$W/idn-area" && (cd "$W/idn-area" && npm pack idn-area-data@4.0.1 --silent && tar xzf idn-area-data-4.0.1.tgz)
fi
export BNSPMAP_GEO_CACHE="$W/geo"
python3 tools/fetch.py "$W" map
python3 tools/fetch.py "$W" list
python3 tools/fetch.py "$W" detail
python3 tools/revmap.py tools "$W"
python3 tools/enrich.py tools "$W" "$W/idn-area/package/data" js/lsp-data.js
