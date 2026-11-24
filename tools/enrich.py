"""Gabungkan daftar+detail LSP bnsp.go.id dengan koordinat peta BNSP, tentukan wilayah
(provinsi/kab-kota/kecamatan, referensi Kemendagri via idn-area-data), lalu tulis js/lsp-data.js.

argv: <dir skrip> <dir data unduhan> <dir idn-area csv> <file output js> [--no-geocode]
"""
import sys, os, re, json, csv, html, datetime, collections
sys.path.insert(0, sys.argv[1]); import geo
DL, AREA, OUT = sys.argv[2], sys.argv[3], sys.argv[4]
NO_GEOCODE = "--no-geocode" in sys.argv

# ---------------- referensi wilayah ----------------
def rd(f): return list(csv.DictReader(open(f"{AREA}/{f}", encoding="utf-8")))
PROV = {r["code"]: r["name"] for r in rd("provinces.csv")}
PROV_DISPLAY = dict(PROV); PROV_DISPLAY["31"] = "DKI Jakarta"; PROV_DISPLAY["34"] = "DI Yogyakarta"
REG = {r["code"]: r for r in rd("regencies.csv")}
DIST = {r["code"]: r for r in rd("districts.csv")}
DIST_BY_REG = collections.defaultdict(list)
for c, r in DIST.items(): DIST_BY_REG[r["regency_code"]].append(c)

def pulau_of(pc):
    n = int(pc)
    return ("Sumatera" if n < 30 else "Jawa" if n < 50 else "Bali & Nusa Tenggara" if n < 60 else
            "Kalimantan" if n < 70 else "Sulawesi" if n < 80 else "Maluku" if n < 90 else "Papua")

def norm(s):
    s = html.unescape(s or "").lower()
    s = re.sub(r"\bkab\b\.?", " kabupaten ", s)
    s = re.sub(r"\bkec\b\.?", " kecamatan ", s)
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " " + re.sub(r"\s+", " ", s).strip() + " "

def reg_core(name):
    return re.sub(r"^(kabupaten administrasi|kota administrasi|kabupaten|kota) ", "", norm(name).strip())
def reg_display(code):
    return REG[code]["name"].replace(" Administrasi", "")
REG_CORE = {c: reg_core(r["name"]) for c, r in REG.items()}
REG_IS_KOTA = {c: r["name"].startswith("Kota") for c, r in REG.items()}
DIST_CORE = {c: norm(r["name"]).strip() for c, r in DIST.items()}
DIST_COUNT = collections.Counter(DIST_CORE.values())

PROV_ALIAS = collections.defaultdict(set)
for c, n in PROV.items(): PROV_ALIAS[c].add(norm(n).strip())
for c, al in {"31": ["dki jakarta", "dki", "jakarta"], "34": ["diy", "yogyakarta", "jogja", "jogjakarta", "di yogyakarta"],
              "32": ["jabar"], "33": ["jateng"], "35": ["jatim"], "52": ["ntb"], "53": ["ntt"], "12": ["sumut"], "13": ["sumbar"],
              "16": ["sumsel"], "64": ["kaltim"], "63": ["kalsel"], "61": ["kalbar"], "62": ["kalteng"], "65": ["kaltara"],
              "73": ["sulsel"], "71": ["sulut"], "72": ["sulteng"], "74": ["sultra"], "76": ["sulbar"], "19": ["babel", "bangka belitung"],
              "21": ["kepri"]}.items():
    PROV_ALIAS[c].update(al)

STREET = ("jl", "jln", "jalan", "gg", "gang", "komplek", "kompleks", "perum", "perumahan", "blok", "ruko")
GENERIC = {"raya", "kota", "baru", "tengah", "utara", "selatan", "timur", "barat", "pusat", "jaya", "indah", "kali", "gunung",
           "pasar", "sungai", "batu", "bukit", "tanjung", "lima", "dua", "tiga", "empat", "kampung", "pantai", "danau", "laut",
           "teluk", "pulau", "air", "bumi", "mulia", "sari", "makmur", "permai", "harapan", "merdeka", "pahlawan", "sentosa"}

def has(t, w):
    if f" {w} " in t:
        return True
    w2 = w.replace(" ", "")
    return w2 != w and f" {w2} " in t

def has_place(t, w):
    """Seperti has(), tetapi abaikan kemunculan sebagai nama jalan/kompleks (mis. 'Jl. Raya', 'Jl. Riung Mulia')."""
    for v in {w, w.replace(" ", "")}:
        if f" {v} " not in t: continue
        for m in re.finditer(f" {re.escape(v)} ", t):
            before = t[:m.start()].split()[-1:] or [""]
            if before[0] not in STREET:
                return True
    return False

JKT_VILLAGES = []
VIL_BY_REG = collections.defaultdict(lambda: collections.defaultdict(list))
for r in rd("villages.csv"):
    VIL_BY_REG[r["district_code"][:5]][norm(r["name"]).strip()].append(r["district_code"])
    if r["code"].startswith("31."):
        n = norm(r["name"]).strip()
        if len(n) >= 5: JKT_VILLAGES.append((n, r["district_code"]))

def resolve_text(main, hint=""):
    """Tebak kab/kota & kecamatan dari teks alamat (main) dan teks petunjuk (hint, bobot setengah)."""
    best = None
    for src, wt in ((main, 1.0), (hint, 0.5)):
        pass
    t, h = norm(main), norm(hint)
    pmatch = {c for c, al in PROV_ALIAS.items() if any(has(t, a) or has(h, a) for a in al)}
    scores = {}
    for c, core in REG_CORE.items():
        r = 0.0
        for txt, wt in ((t, 1.0), (h, 0.5)):
            kind = "kota" if REG_IS_KOTA[c] else "kabupaten"
            other = "kabupaten" if REG_IS_KOTA[c] else "kota"
            cc = core.replace(" ", "")
            if has(txt, f"{kind} {core}") or has(txt, f"{kind} {cc}"): r = max(r, 3 * wt)
            elif has_place(txt, core) and not (has(txt, f"{other} {core}") or has(txt, f"{other} {cc}")):
                r = max(r, (1.5 if len(core) > 4 else 0.5) * wt)
        d, dbest = 0.0, None
        for dc in DIST_BY_REG[c]:
            dn = DIST_CORE[dc]
            if len(dn) < 4: continue
            if dn in GENERIC and not has(t, f"kecamatan {dn}"): continue
            if has(t, f"kecamatan {dn}"): v = 2.0
            elif has_place(t, dn): v = 1.0
            elif has_place(h, dn): v = 0.4
            else: continue
            if dn == core: v -= 0.1   # kecamatan bernama sama dgn kab/kota: kalah dari kecamatan lain yang disebut
            if v > d: d, dbest = v, dc
        p = 1.5 if REG[c]["province_code"] in pmatch else 0
        if r == 0 and d == 0: continue
        uniq_d = dbest and DIST_COUNT[DIST_CORE[dbest]] == 1 and len(DIST_CORE[dbest]) >= 6
        ok = r >= 0.75 or d >= 2 or (d >= 1 and p) or (d >= 1 and uniq_d) or (r > 0 and p)
        if not ok: continue
        sc = r + d + p + (0.1 if REG_IS_KOTA[c] else 0)
        if best is None or sc > best[0]: best = (sc, c, dbest)
    if best: return best[1], best[2]
    if not pmatch or "31" in pmatch:   # alamat Jakarta sering hanya mencantumkan kelurahan
        for vn, dc in JKT_VILLAGES:
            if has_place(t, vn): return DIST[dc]["regency_code"], dc
    if len(pmatch) == 1: return ("P" + pmatch.pop(), None)
    return None, None

def resolve_reverse(rv):
    if not rv or "address" not in rv: return None, None
    a = rv["address"]
    st = norm(a.get("state", "")).strip()
    pc = next((c for c, al in PROV_ALIAS.items() if st in al or norm(PROV[c]).strip() == st), None)
    if not pc: return None, None
    regs = [c for c in REG if REG[c]["province_code"] == pc]
    reg = None
    for key in ("city", "county", "municipality", "town"):
        v = norm(a.get(key, "")).strip()
        v = re.sub(r"^(kabupaten|kota) ", "", v)
        if not v: continue
        m = [c for c in regs if REG_CORE[c] == v]
        if m:
            reg = sorted(m, key=lambda c: (not REG_IS_KOTA[c]) if key == "city" else REG_IS_KOTA[c])[0]; break
    if not reg: return "P" + pc, None
    dist = None
    for key in ("city_district", "district", "municipality", "town", "suburb", "village", "quarter", "hamlet"):
        v = norm(a.get(key, "")).strip()
        v = re.sub(r"^kecamatan ", "", v)
        m = [dc for dc in DIST_BY_REG[reg] if DIST_CORE[dc] == v]
        if m: dist = m[0]; break
    if not dist:   # Nominatim sering hanya memberi nama desa/kelurahan -> cari kecamatannya
        for key in ("village", "suburb", "neighbourhood", "quarter", "hamlet", "city_block"):
            v = re.sub(r"^(desa|kelurahan) ", "", norm(a.get(key, "")).strip())
            cands = sorted(set(VIL_BY_REG[reg].get(v, []))) if v else []
            if len(cands) == 1:
                dist = cands[0]; break
            if len(cands) > 1:   # nama desa ganda dalam satu kab/kota: pilih yang cocok dgn nama kota/kecamatan Nominatim
                hints = {re.sub(r"^(kabupaten|kota|kecamatan) ", "", norm(a.get(k, "")).strip()) for k in ("city", "town", "municipality", "city_district", "district")}
                m = [dc for dc in cands if DIST_CORE[dc] in hints]
                if len(m) == 1: dist = m[0]; break
    return reg, dist

# ---------------- data BNSP ----------------
cards = {c["slug"]: c for c in json.load(open(f"{DL}/cards.json"))}
details = {d["slug"]: d for d in json.load(open(f"{DL}/details.json"))}
mapd = json.load(open(f"{DL}/lspmap.json"))["data"]

def key_name(s):
    s = norm(s)
    s = re.sub(r" lsp | p1 | p2 | p3 | lembaga sertifikasi profesi ", " ", s)
    s = re.sub(r" lsp | p1 | p2 | p3 ", " ", s)
    return re.sub(r"\s+", "", s)
def key_name_np(s): return key_name(re.sub(r"\(.*?\)", " ", s or ""))
def key_web(s):
    s = (s or "").lower().split("/")[0] if "://" not in (s or "") else (s or "").lower().split("://", 1)[1].split("/")[0]
    s = re.sub(r"^www\.", "", s.strip())
    return s if "." in s else ""
def key_hp(s):
    d = re.sub(r"\D", "", s or "")
    return d[-9:] if len(d) >= 9 else ""

idx = collections.defaultdict(list)
for m in mapd:
    for k in ("n:" + key_name(m["nama"]), "p:" + key_name_np(m["nama"]), "w:" + key_web(m.get("web")), "h:" + key_hp(m.get("hp"))):
        if len(k) > 4: idx[k].append(m)

PLACE = (-6.2091, 106.8592)
def parse_ll(lok):
    s = (lok or "").strip()
    m = re.match(r"^\s*(-?\d+),(\d+)\s*,\s*(-?\d+),(\d+)\s*$", s)
    if m: lat, lng = float(m[1] + "." + m[2]), float(m[3] + "." + m[4])
    else:
        m = re.match(r"^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$", s)
        if not m: return None
        lat, lng = float(m[1]), float(m[2])
    if not (-11.5 < lat < 6.5 and 94 < lng < 141.5): return None
    if abs(lat - PLACE[0]) < 1e-3 and abs(lng - PLACE[1]) < 1e-3: return None
    return round(lat, 6), round(lng, 6)

def cf_email(slug):
    s = open(f"{DL}/detail/{slug}.html", encoding="utf-8").read()
    i = s.find("Email")
    m = re.search(r'data-cfemail="([0-9a-f]+)"', s[i:i + 2000]) if i > 0 else None
    if not m: return ""
    b = bytes.fromhex(m[1]); return "".join(chr(x ^ b[0]) for x in b[1:])

JENIS = {"pertama": "P1", "kesatu": "P1", "kedua": "P2", "ketiga": "P3"}
TODAY = datetime.date.today()
geocode_q = {}
out, stats = [], collections.Counter()

for slug, c in cards.items():
    d = details.get(slug, {})
    nama = d.get("nama") or c["nama"]
    jr = (d.get("jenisRaw") or "").lower()
    jenis = next((v for k, v in JENIS.items() if k in jr), "")
    jenisPerkiraan = False
    if not jenis:
        jenisPerkiraan = True
        m = re.search(r"\bP([123])\b", nama)
        if m: jenis = f"P{m[1]}"
        elif re.search(r"\b(smk|smks|smkn|slb|universitas|politeknik|institut|akademi|sekolah tinggi|stikes|poltek)\b", nama, re.I): jenis = "P1"
        elif re.search(r"\b(kementerian|balai|badan|pusdiklat)\b", nama, re.I): jenis = "P2"
        else: jenis = "P3"
    info = c.get("info", "")
    nol = d.get("noLisensi") or (re.search(r"BNSP-LSP-\S+", info) or [""])[0]
    berlaku = d.get("berlaku") or ""
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", berlaku): berlaku = ""
    # cocokkan ke data peta
    mm = None
    for k in ("n:" + key_name(nama), "p:" + key_name_np(nama), "w:" + key_web(d.get("web")), "h:" + key_hp(d.get("hp") or d.get("telp"))):
        if len(k) > 4 and len(idx.get(k, [])) == 1: mm = idx[k][0]; break
    ll = parse_ll(mm["lokasi"]) if mm else None
    hint = " ".join(x for x in [c.get("lokasi", ""), (mm or {}).get("lokasi_lsp", ""), nama] if x)
    alamat = d.get("alamat", "") or ""
    reg = dist = None; akurasi = ""
    if ll:
        reg, dist = resolve_reverse(geo.reverse(*ll))
        akurasi = "titik"
        if reg and not reg.startswith("P") and not dist:
            r2, d2 = resolve_text(alamat, hint)
            if r2 == reg and d2: dist = d2
    if not reg or reg.startswith("P"):
        r2, d2 = resolve_text(alamat, hint)
        if r2 and not r2.startswith("P"):
            if not reg or REG[r2]["province_code"] == reg[1:]: reg, dist = r2, d2
        elif r2 and not reg: reg = r2
    rec = {"slug": slug, "nama": nama, "jenis": jenis, "jenisPerkiraan": jenisPerkiraan, "noLisensi": nol, "sk": d.get("sk", ""),
           "tglBerakhir": berlaku, "statusResmi": d.get("status") or {"Lisensi Aktif": "Aktif"}.get(c.get("badge", ""), c.get("badge", "")),
           "alamat": alamat, "telepon": d.get("hp") or d.get("telp") or "", "email": cf_email(slug) if slug in details else "",
           "web": d.get("web", "") if d.get("web", "") not in ("-", "0") else "", "jmlSkema": d.get("jmlSkema")}
    if reg and not reg.startswith("P"):
        pc = REG[reg]["province_code"]
        rec.update(provinsi=PROV_DISPLAY[pc], pulau=pulau_of(pc), kabkota=reg_display(reg), kecamatan=DIST[dist]["name"] if dist else "")
    elif reg:
        pc = reg[1:]; rec.update(provinsi=PROV_DISPLAY[pc], pulau=pulau_of(pc), kabkota="", kecamatan="")
    else:
        rec.update(provinsi="", pulau="", kabkota="", kecamatan="")
    if ll:
        rec["lat"], rec["lng"] = ll
    elif rec["kabkota"]:
        akurasi = "kecamatan" if rec["kecamatan"] else "kabkota"
        q = (rec["kecamatan"], rec["kabkota"], PROV[REG[reg]["province_code"]])
        geocode_q[slug] = q
    elif rec["provinsi"]:
        akurasi = "provinsi"; geocode_q[slug] = ("", "", PROV[reg[1:]])
    rec["akurasi"] = akurasi or "tidak ada"
    out.append(rec)

# geocode pusat kecamatan / kab-kota / provinsi
def centroid(kec, kab, prov):
    if kec:
        r = geo.search(f"Kecamatan {kec}, {kab}, {prov}")
        if r: return r
        r = geo.search(f"{kec}, {kab}, {prov}")
        if r: return r
    if kab:
        r = geo.search(f"{kab}, {prov}")
        if r: return r
    return geo.search(prov)

if not NO_GEOCODE:
    uniq = sorted(set(geocode_q.values()))
    print("geocode", len(uniq), "wilayah unik", flush=True)
    res = {}
    for i, q in enumerate(uniq):
        r = centroid(*q)
        res[q] = (round(float(r[0]["lat"]), 6), round(float(r[0]["lon"]), 6)) if r else None
        if i % 50 == 0: print(" ", i, flush=True)
    for rec in out:
        q = geocode_q.get(rec["slug"])
        if q and res.get(q): rec["lat"], rec["lng"] = res[q]

out.sort(key=lambda r: r["nama"].lower())
for i, r in enumerate(out, 1): r["id"] = i
stats.update(r["akurasi"] for r in out)
print("total", len(out), dict(stats))
print("jenis", collections.Counter(r["jenis"] for r in out))
print("tanpa provinsi", sum(1 for r in out if not r["provinsi"]), "tanpa koordinat", sum(1 for r in out if "lat" not in r))
meta = {"sumber": "https://bnsp.go.id/lsp", "diambil": TODAY.isoformat(), "jumlah": len(out)}
with open(OUT, "w", encoding="utf-8") as f:
    f.write("/* Data LSP dari bnsp.go.id (daftar & detail LSP + koordinat peta LSP BNSP).\n"
            "   Wilayah ditentukan dari koordinat BNSP (reverse geocoding OpenStreetMap/Nominatim) atau dari teks alamat\n"
            "   dicocokkan dengan referensi wilayah Kemendagri (idn-area-data). Dibuat otomatis, jangan diedit manual. */\n")
    f.write("window.LSP_META=" + json.dumps(meta, ensure_ascii=False) + ";\n")
    f.write("window.LSP_DATA=[\n" + ",\n".join(json.dumps(r, ensure_ascii=False, separators=(",", ":")) for r in out) + "\n];\n")
print("ditulis", OUT, os.path.getsize(OUT), "byte")
