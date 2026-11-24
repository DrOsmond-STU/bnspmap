"""Nominatim helper dengan cache file. Batas 1 permintaan/detik sesuai kebijakan Nominatim."""
import os, json, time, ssl, hashlib, urllib.request, urllib.parse
CTX = ssl.create_default_context(cafile=os.environ.get("BNSPMAP_CAFILE") or None)
UA = {"User-Agent": "bnspmap-prototype/1.0 (LSP mapping dashboard)", "Accept-Language": "id"}
CACHE = os.environ.get("BNSPMAP_GEO_CACHE", "work/geo")
os.makedirs(CACHE, exist_ok=True)
_last = [0.0]

def _call(url):
    key = hashlib.md5(url.encode()).hexdigest()
    p = f"{CACHE}/{key}.json"
    if os.path.exists(p):
        return json.load(open(p))
    if os.environ.get("GEO_OFFLINE"):
        return None
    for attempt in range(4):
        wait = 1.1 - (time.time() - _last[0])
        if wait > 0: time.sleep(wait)
        _last[0] = time.time()
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), context=CTX, timeout=60) as r:
                data = json.loads(r.read().decode())
            json.dump(data, open(p, "w"))
            return data
        except Exception as e:
            time.sleep(5 * (attempt + 1))
    return None

def reverse(lat, lng):
    return _call(f"https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={lat:.6f}&lon={lng:.6f}&zoom=14&addressdetails=1")

def search(q, **structured):
    params = {"format": "jsonv2", "addressdetails": 1, "limit": 1, "countrycodes": "id"}
    if q: params["q"] = q
    params.update(structured)
    return _call("https://nominatim.openstreetmap.org/search?" + urllib.parse.urlencode(params))
