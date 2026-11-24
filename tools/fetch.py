"""Ambil daftar & detail LSP dari bnsp.go.id.

Pemakaian: python3 tools/fetch.py <dir-kerja> map|list [jumlah-halaman]|detail
File yang sudah terunduh dipakai ulang (hapus <dir-kerja> untuk mengambil ulang semuanya)."""
import sys, os, re, json, html, time, ssl, urllib.request, urllib.parse
from concurrent.futures import ThreadPoolExecutor

OUT = sys.argv[1]
os.makedirs(f"{OUT}/pages", exist_ok=True); os.makedirs(f"{OUT}/detail", exist_ok=True)
CTX = ssl.create_default_context(cafile=os.environ.get("BNSPMAP_CAFILE") or None)
UA = {"User-Agent": "Mozilla/5.0 (prototype research; bnspmap)"}

def get(url, path):
    if os.path.exists(path) and os.path.getsize(path) > 1000:
        return open(path, encoding="utf-8").read()
    for attempt in range(4):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), context=CTX, timeout=60) as r:
                s = r.read().decode("utf-8", "replace")
            open(path, "w", encoding="utf-8").write(s)
            time.sleep(0.25)
            return s
        except Exception as e:
            time.sleep(2 * (attempt + 1))
    print("GAGAL", url, file=sys.stderr)
    return ""

def clean(x):
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", x))).strip()

stage = sys.argv[2]
if stage == "map":
    # titik koordinat LSP yang dipakai peta di beranda bnsp.go.id
    s = get("https://bnsp.go.id/lsp-map", f"{OUT}/lspmap.json")
    print(len(json.loads(s)["data"]), "titik peta")
elif stage == "list":
    if len(sys.argv) > 3:
        pages = int(sys.argv[3])
    else:
        first = get("https://bnsp.go.id/lsp?hal=1", f"{OUT}/pages/1.html")
        pages = int(re.search(r"/\s*(\d+)\s*</span>", first[first.find("Halaman"):]).group(1))
    with ThreadPoolExecutor(4) as ex:
        list(ex.map(lambda p: get(f"https://bnsp.go.id/lsp?hal={p}", f"{OUT}/pages/{p}.html"), range(1, pages + 1)))
    cards = []
    for p in range(1, pages + 1):
        s = open(f"{OUT}/pages/{p}.html", encoding="utf-8").read()
        for blk in s.split('<div class="bg-white rounded-lg border border-gray-100')[1:]:
            m = re.search(r'<h3[^>]*>\s*<a href="https://bnsp.go.id/lsp/([^"]+)"[^>]*>(.*?)</a>', blk, re.S)
            if not m: continue
            badge = re.search(r'fa-sparkles[^>]*></i>\s*([^<]+)<', blk) or re.search(r'rounded-md flex items-center gap-1.5">\s*<i[^>]*></i>\s*([^<]+)<', blk)
            info = re.search(r'Info Lisensi:</span>(.*?)</p>', blk, re.S)
            loc = re.search(r'fa-location-dot[^>]*></i>\s*([^<]+)<', blk)
            cards.append({"slug": m.group(1), "nama": clean(m.group(2)), "badge": clean(badge.group(1)) if badge else "",
                          "info": clean(info.group(1)) if info else "", "lokasi": clean(loc.group(1)) if loc else "", "page": p})
    json.dump(cards, open(f"{OUT}/cards.json", "w"), ensure_ascii=False, indent=0)
    print(len(cards), "kartu;", len({c['slug'] for c in cards}), "slug unik")

elif stage == "detail":
    cards = json.load(open(f"{OUT}/cards.json"))
    slugs = sorted({c["slug"] for c in cards})
    def one(slug):
        url = "https://bnsp.go.id/lsp/" + urllib.parse.quote(html.unescape(slug), safe="-")
        s = get(url, f"{OUT}/detail/{slug.replace('/', '_')}.html")
        if not s: return None
        body = re.sub(r"<script.*?</script>|<style.*?</style>", "", s, flags=re.S)
        lines = [l.strip() for l in html.unescape(re.sub(r"<[^>]+>", "\n", body)).split("\n") if l.strip()]
        d = {"slug": slug}
        try:
            i = lines.index("Profile LSP")
            d["nama"] = lines[i - 1]
        except ValueError:
            return d
        keys = {"No. SK Lisensi": "sk", "No Lisensi": "noLisensi", "Jenis": "jenisRaw", "No Telp": "telp", "No Hp": "hp",
                "Email": "email", "Website": "web", "Masa Berlaku Sertifikat": "berlaku", "Status Lisensi": "status"}
        for k, v in keys.items():
            if k in lines[i:i + 60]:
                j = lines.index(k, i)
                if lines[j + 1] == ":" and lines[j + 2] not in keys:
                    d[v] = lines[j + 2]
        if "Alamat" in lines[i:i + 60]:
            j = lines.index("Alamat", i)
            d["alamat"] = lines[j + 1] if lines[j + 1] != "DATA SKEMA" else ""
        m = re.search(r"/lsp/(\d+)/tuks", s); d["sisfoId"] = int(m.group(1)) if m else None
        try:
            a = lines.index("Unit", i); b = lines.index("Nomor", a)
            d["jmlSkema"] = (b - a - 1) // 3
        except ValueError:
            pass
        return d
    t0 = time.time()
    with ThreadPoolExecutor(4) as ex:
        res = [r for r in ex.map(one, slugs) if r]
    json.dump(res, open(f"{OUT}/details.json", "w"), ensure_ascii=False, indent=0)
    print(len(res), "detail dalam", round(time.time() - t0), "detik")
