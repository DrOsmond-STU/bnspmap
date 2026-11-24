import sys, json, re
sys.path.insert(0, sys.argv[1]); import geo
PLACEHOLDER = (-6.2091, 106.8592)
def parse(lok):
    s = lok.strip()
    m = re.match(r"^\s*(-?\d+),(\d+)\s*,\s*(-?\d+),(\d+)\s*$", s)   # koma desimal
    if m: return float(m[1] + "." + m[2]), float(m[3] + "." + m[4])
    m = re.match(r"^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$", s)
    if m: return float(m[1]), float(m[2])
    return None
d = json.load(open(sys.argv[2] + "/lspmap.json"))["data"]
pts = []
for x in d:
    c = parse(x.get("lokasi") or "")
    if not c: continue
    lat, lng = c
    if not (-11.5 < lat < 6.5 and 94 < lng < 141.5): continue
    if abs(lat - PLACEHOLDER[0]) < 1e-3 and abs(lng - PLACEHOLDER[1]) < 1e-3: continue
    pts.append((round(lat, 6), round(lng, 6)))
pts = sorted(set(pts))
print(len(pts), "titik unik", flush=True)
for i, (lat, lng) in enumerate(pts):
    geo.reverse(lat, lng)
    if i % 100 == 0: print(i, flush=True)
print("selesai", flush=True)
