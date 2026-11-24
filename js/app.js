/* Peta LSP BNSP — logika dashboard (filter, peta, grafik, tabel, export). */
(function () {
  "use strict";

  const DATA = window.LSP_DATA;
  const PULAU = window.LSP_PULAU;
  const META = window.LSP_META || {};
  const UNKNOWN = "Belum diketahui";
  const JENIS = [
    { k: "P1", lbl: "LSP P1", desc: "Pihak pertama" },
    { k: "P2", lbl: "LSP P2", desc: "Pihak kedua" },
    { k: "P3", lbl: "LSP P3", desc: "Pihak ketiga" },
  ];
  const STATUS = [
    { k: "aktif", lbl: "Aktif", desc: "Sisa > 6 bulan" },
    { k: "segera", lbl: "Segera berakhir", desc: "Sisa ≤ 6 bulan" },
    { k: "habis", lbl: "Kedaluwarsa", desc: "Lisensi sudah lewat" },
  ];
  const SOON_DAYS = 180;
  const DAY = 86400000;
  const TODAY = new Date(); TODAY.setHours(0, 0, 0, 0);

  const $ = (s, el = document) => el.querySelector(s);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const nf = new Intl.NumberFormat("id-ID");
  const BULAN = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
  const fmtDate = (iso) => { const [y, m, d] = iso.split("-"); return `${+d} ${BULAN[+m - 1]} ${y}`; };
  const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

  // Lengkapi field turunan: sisa masa berlaku, status, label wilayah yang belum diketahui
  DATA.forEach((d) => {
    d.pulau = d.pulau || UNKNOWN;
    d.provinsi = d.provinsi || UNKNOWN;
    d.kabkota = d.kabkota || UNKNOWN;
    d.kecamatan = d.kecamatan || UNKNOWN;
    d.jenis = d.jenis || "P3";
    if (d.tglBerakhir) {
      const end = new Date(d.tglBerakhir + "T00:00:00");
      d.sisa = Math.round((end - TODAY) / DAY);
      d.status = d.sisa < 0 ? "habis" : d.sisa <= SOON_DAYS ? "segera" : "aktif";
    } else {
      d.sisa = null;
      d.status = /aktif/i.test(d.statusResmi) && !/tidak/i.test(d.statusResmi) ? "aktif" : "habis";
    }
    if (/tidak aktif/i.test(d.statusResmi || "")) d.status = "habis";
    d._search = `${d.nama} ${d.noLisensi} ${d.alamat} ${d.kecamatan} ${d.kabkota} ${d.provinsi}`.toLowerCase();
  });

  const provToPulau = {};
  DATA.forEach((d) => (provToPulau[d.provinsi] = d.pulau));
  const uniq = (rows, k) => [...new Set(rows.map((d) => d[k]))].sort((a, b) => (a === UNKNOWN) - (b === UNKNOWN) || a.localeCompare(b, "id"));

  // ---------------- state ----------------
  const initial = () => ({
    q: "", pulau: "", provinsi: "", kabkota: "", kecamatan: "",
    jenis: new Set(["P1", "P2", "P3"]),
    status: new Set(["aktif", "segera", "habis"]),
    from: "", to: "", preset: "",
  });
  let S = initial();
  let sortKey = "nama", sortDir = 1, page = 1;

  function matches(d, skip) {
    if (skip !== "q" && S.q && !d._search.includes(S.q)) return false;
    if (skip !== "pulau" && S.pulau && d.pulau !== S.pulau) return false;
    if (skip !== "provinsi" && S.provinsi && d.provinsi !== S.provinsi) return false;
    if (skip !== "provinsi" && S.kabkota && d.kabkota !== S.kabkota) return false;
    if (skip !== "provinsi" && S.kecamatan && d.kecamatan !== S.kecamatan) return false;
    if (skip !== "jenis" && !S.jenis.has(d.jenis)) return false;
    if (skip !== "status" && !S.status.has(d.status)) return false;
    if (skip !== "status" && S.from && d.tglBerakhir < S.from) return false;
    if (skip !== "status" && S.to && d.tglBerakhir > S.to) return false;
    return true;
  }
  const filtered = () => DATA.filter((d) => matches(d));
  const countBy = (rows, key) => rows.reduce((m, d) => ((m[d[key]] = (m[d[key]] || 0) + 1), m), {});

  // ---------------- UI builders ----------------
  function buildPulauTabs() {
    const rows = DATA.filter((d) => matches(d, "pulau"));
    const c = countBy(rows, "pulau");
    const tabs = [["", "Semua pulau", rows.length], ...PULAU.map((p) => [p, p, c[p] || 0])];
    if (DATA.some((d) => d.pulau === UNKNOWN)) tabs.push([UNKNOWN, "Lokasi belum diketahui", c[UNKNOWN] || 0]);
    $("#pulauTabs").innerHTML = tabs.map(([v, l, n]) =>
      `<button class="ptab" role="tab" data-v="${esc(v)}" aria-selected="${S.pulau === v}">${esc(l)} <span class="n">${nf.format(n)}</span></button>`).join("");
  }

  function fillSelect(sel, items, value, allLabel) {
    sel.innerHTML = `<option value="">${allLabel}</option>` + items.map((i) => `<option${i === value ? " selected" : ""}>${esc(i)}</option>`).join("");
  }
  function buildRegionSelects() {
    const provs = uniq(DATA.filter((d) => !S.pulau || d.pulau === S.pulau), "provinsi");
    fillSelect($("#fProv"), provs, S.provinsi, "Semua provinsi");
    const kabs = S.provinsi ? uniq(DATA.filter((d) => d.provinsi === S.provinsi), "kabkota") : [];
    fillSelect($("#fKab"), kabs, S.kabkota, S.provinsi ? "Semua kab/kota" : "Pilih provinsi dulu");
    $("#fKab").disabled = !S.provinsi;
    const kecs = S.kabkota ? uniq(DATA.filter((d) => d.provinsi === S.provinsi && d.kabkota === S.kabkota), "kecamatan") : [];
    fillSelect($("#fKec"), kecs, S.kecamatan, S.kabkota ? "Semua kecamatan" : "Pilih kab/kota dulu");
    $("#fKec").disabled = !S.kabkota;
  }

  function buildChecks() {
    const rj = countBy(DATA.filter((d) => matches(d, "jenis")), "jenis");
    $("#fJenis").innerHTML = JENIS.map((j) => `
      <label class="check${S.jenis.has(j.k) ? "" : " off"}">
        <input type="checkbox" value="${j.k}" ${S.jenis.has(j.k) ? "checked" : ""}>
        <span class="sw" style="background:var(--${j.k.toLowerCase()})"></span>
        <span class="lbl">${j.lbl}<small>${j.desc}</small></span>
        <span class="n">${nf.format(rj[j.k] || 0)}</span>
      </label>`).join("");
    // hitung status dengan filter selain status (tetapi tetap hormati rentang tanggal)
    const rs = countBy(DATA.filter((d) => matches(d, "status") && (!S.from || d.tglBerakhir >= S.from) && (!S.to || d.tglBerakhir <= S.to)), "status");
    $("#fStatus").innerHTML = STATUS.map((s) => `
      <label class="check${S.status.has(s.k) ? "" : " off"}">
        <input type="checkbox" value="${s.k}" ${S.status.has(s.k) ? "checked" : ""}>
        ${statusIcon(s.k, 14)}
        <span class="lbl">${s.lbl}<small>${s.desc}</small></span>
        <span class="n">${nf.format(rs[s.k] || 0)}</span>
      </label>`).join("");
  }

  const PRESETS = [
    { k: "3m", lbl: "Berakhir ≤ 3 bln", months: 3 },
    { k: "6m", lbl: "≤ 6 bln", months: 6 },
    { k: "12m", lbl: "≤ 12 bln", months: 12 },
    { k: "y1", lbl: String(TODAY.getFullYear() + 1), year: TODAY.getFullYear() + 1 },
    { k: "y2", lbl: String(TODAY.getFullYear() + 2), year: TODAY.getFullYear() + 2 },
  ];
  function buildPresets() {
    $("#fPresets").innerHTML = PRESETS.map((p) => `<button class="preset" data-k="${p.k}" aria-pressed="${S.preset === p.k}">${p.lbl}</button>`).join("");
    $("#fFrom").value = S.from; $("#fTo").value = S.to;
  }
  function applyPreset(k) {
    if (S.preset === k) { S.preset = ""; S.from = ""; S.to = ""; return; }
    const p = PRESETS.find((x) => x.k === k);
    S.preset = k;
    if (p.months) { const e = new Date(TODAY); e.setMonth(e.getMonth() + p.months); S.from = isoDate(TODAY); S.to = isoDate(e); }
    else { S.from = `${p.year}-01-01`; S.to = `${p.year}-12-31`; }
  }

  function statusIcon(k, size = 12) {
    const c = k === "aktif" ? "var(--ok)" : k === "segera" ? "var(--warn)" : "var(--crit)";
    const path = k === "aktif" ? '<path d="M4 8.2l2.6 2.6L12 5.4" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
      : k === "segera" ? '<path d="M8 4.5V8.5l2.4 1.4" stroke="#141821" stroke-width="1.8" fill="none" stroke-linecap="round"/>'
        : '<path d="M5.5 5.5l5 5m0-5l-5 5" stroke="#fff" stroke-width="2" stroke-linecap="round"/>';
    return `<svg viewBox="0 0 16 16" width="${size}" height="${size}" aria-hidden="true"><circle cx="8" cy="8" r="8" fill="${c}"/>${path}</svg>`;
  }
  const statusLabel = (k) => STATUS.find((s) => s.k === k).lbl;
  const locText = (d) => [d.kecamatan !== UNKNOWN ? "Kec. " + d.kecamatan : "", d.kabkota !== UNKNOWN ? d.kabkota : "", d.provinsi !== UNKNOWN ? d.provinsi : ""].filter(Boolean).join(", ") || "Lokasi belum diketahui";
  const AKURASI = { titik: "Titik dari peta LSP BNSP", kecamatan: "Perkiraan: pusat kecamatan", kabkota: "Perkiraan: pusat kab/kota", provinsi: "Perkiraan: pusat provinsi", "tidak ada": "Belum ada titik lokasi" };
  const sisaText = (d) => d.sisa == null ? "tanggal tidak tercantum" : d.sisa < 0 ? `lewat ${nf.format(-d.sisa)} hari` : d.sisa > 365 ? `${(d.sisa / 365).toFixed(1).replace(".", ",")} thn lagi` : `${nf.format(d.sisa)} hari lagi`;
  const statusPill = (d) => `<span class="status ${d.status}">${statusIcon(d.status)}${statusLabel(d.status)}</span>`;

  function buildChips() {
    const chips = [];
    if (S.q) chips.push(["q", `Cari: "${S.q}"`]);
    if (S.pulau) chips.push(["pulau", `Pulau: ${S.pulau}`]);
    if (S.provinsi) chips.push(["provinsi", `Provinsi: ${S.provinsi}`]);
    if (S.kabkota) chips.push(["kabkota", S.kabkota]);
    if (S.kecamatan) chips.push(["kecamatan", `Kec. ${S.kecamatan}`]);
    if (S.jenis.size < 3) chips.push(["jenis", `Jenis: ${[...S.jenis].sort().join(", ") || "—"}`]);
    if (S.status.size < 3) chips.push(["status", `Status: ${[...S.status].map(statusLabel).join(", ") || "—"}`]);
    if (S.from || S.to) chips.push(["tgl", `Berakhir ${S.from ? fmtDate(S.from) : "…"} – ${S.to ? fmtDate(S.to) : "…"}`]);
    $("#activeChips").innerHTML = chips.map(([k, l]) => `<span class="chip">${esc(l)}<button data-k="${k}" aria-label="Hapus filter ${esc(l)}">✕</button></span>`).join("");
  }
  function clearChip(k) {
    if (k === "q") { S.q = ""; $("#fSearch").value = ""; }
    else if (k === "pulau") { S.pulau = ""; S.provinsi = S.kabkota = S.kecamatan = ""; }
    else if (k === "provinsi") { S.provinsi = S.kabkota = S.kecamatan = ""; }
    else if (k === "kabkota") { S.kabkota = S.kecamatan = ""; }
    else if (k === "kecamatan") S.kecamatan = "";
    else if (k === "jenis") S.jenis = new Set(["P1", "P2", "P3"]);
    else if (k === "status") S.status = new Set(["aktif", "segera", "habis"]);
    else if (k === "tgl") { S.from = S.to = S.preset = ""; }
  }

  function buildKpis(rows) {
    const j = countBy(rows, "jenis"), s = countBy(rows, "status");
    const tot = rows.length || 1;
    const prov = new Set(rows.filter((d) => d.provinsi !== UNKNOWN).map((d) => d.provinsi)).size;
    const kab = new Set(rows.filter((d) => d.kabkota !== UNKNOWN).map((d) => d.provinsi + d.kabkota)).size;
    const tile = (cls, k, v, sub, color, pct) => `
      <div class="kpi ${cls}">
        <span class="k">${k}</span>
        <span class="v">${nf.format(v)}</span>
        <span class="s">${sub}</span>
        ${color ? `<div class="bar"><i style="width:${pct}%;background:${color}"></i></div>` : ""}
      </div>`;
    const pct = (n) => ((n / tot) * 100).toFixed(1).replace(".", ",");
    $("#kpis").innerHTML =
      tile("main", "Total LSP", rows.length, `${prov} provinsi · ${kab} kab/kota`) +
      JENIS.map((x) => tile("", `<span class="dot" style="background:var(--${x.k.toLowerCase()})"></span>${x.lbl}`, j[x.k] || 0, `${pct(j[x.k] || 0)}% dari total`, `var(--${x.k.toLowerCase()})`, pct(j[x.k] || 0).replace(",", "."))).join("") +
      tile("", `${statusIcon("segera")} Segera berakhir`, s.segera || 0, "≤ 6 bulan, perlu perpanjangan", "var(--warn)", pct(s.segera || 0).replace(",", ".")) +
      tile("", `${statusIcon("habis")} Kedaluwarsa`, s.habis || 0, `${nf.format(s.aktif || 0)} lisensi aktif`, "var(--crit)", pct(s.habis || 0).replace(",", "."));
  }

  function buildProvList(rows) {
    const byProv = {};
    rows.forEach((d) => { (byProv[d.provinsi] ||= { n: 0, P1: 0, P2: 0, P3: 0 }); byProv[d.provinsi].n++; byProv[d.provinsi][d.jenis]++; });
    const list = Object.entries(byProv).sort((a, b) => b[1].n - a[1].n);
    if (!list.length) { $("#provList").innerHTML = `<li class="empty">Tidak ada LSP yang cocok dengan filter.</li>`; return; }
    $("#provList").innerHTML = list.map(([p, c]) => `
      <li><button class="prov" data-p="${esc(p)}" aria-pressed="${S.provinsi === p}">
        <span class="pin"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 2a7 7 0 00-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 00-7-7zm0 9.5A2.5 2.5 0 1112 6a2.5 2.5 0 010 5.5z" fill="#fff"/></svg></span>
        <span><span class="nm">${esc(p)}</span><span class="pl">${esc(provToPulau[p])}</span>
          <span class="mix" title="P1 ${c.P1} · P2 ${c.P2} · P3 ${c.P3}">${["P1", "P2", "P3"].filter((k) => c[k]).map((k) => `<i style="flex:${c[k]};background:var(--${k.toLowerCase()})"></i>`).join("")}</span></span>
        <span class="ct"><b>${nf.format(c.n)}</b><small>LSP</small></span>
      </button></li>`).join("");
  }

  // ---------------- map ----------------
  let map, cluster, markersById = {}, lastRegionKey = null;
  function initMap() {
    map = L.map("map", { zoomControl: true, preferCanvas: false, minZoom: 4 }).setView([-2.5, 118], 5);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 18, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    cluster = L.markerClusterGroup({
      showCoverageOnHover: false, maxClusterRadius: 60, spiderfyOnMaxZoom: true, chunkedLoading: true,
      iconCreateFunction: (c) => {
        const n = c.getChildCount();
        const cls = n < 20 ? "c-s" : n < 100 ? "c-m" : "c-l";
        const size = n < 20 ? 38 : n < 100 ? 46 : 56;
        return L.divIcon({ html: `<div>${nf.format(n)}</div>`, className: `lsp-cluster ${cls}`, iconSize: [size, size] });
      },
    });
    map.addLayer(cluster);
    $("#mapLegend").innerHTML = JENIS.map((j) => `<span><span class="dot" style="background:var(--${j.k.toLowerCase()})"></span>${j.k}</span>`).join("") + `<span style="color:var(--muted);font-weight:500">angka = jumlah LSP</span>`;
  }
  function pinIcon(d) {
    return L.divIcon({ className: "", html: `<div class="lsp-pin" style="background:var(--${d.jenis.toLowerCase()})">${d.jenis.slice(1)}</div>`, iconSize: [22, 22], iconAnchor: [11, 11], popupAnchor: [0, -10] });
  }
  function popupHtml(d) {
    return `<div class="pop"><h4>${esc(d.nama)}</h4>
      <div class="meta">${esc(d.noLisensi)}${d.jmlSkema != null ? " · " + d.jmlSkema + " skema" : ""}</div>
      <div class="row"><span class="jbadge ${d.jenis}">${d.jenis}</span>${statusPill(d)}</div>
      <div class="meta">${esc(locText(d))}<br>${d.tglBerakhir ? `Berlaku s.d. ${fmtDate(d.tglBerakhir)} (${sisaText(d)})` : "Masa berlaku tidak tercantum"}${d.akurasi !== "titik" ? `<br><i>Titik perkiraan (pusat ${d.akurasi === "kecamatan" ? "kecamatan" : d.akurasi === "kabkota" ? "kab/kota" : "provinsi"})</i>` : ""}</div>
      <button data-detail="${d.id}">Lihat detail</button></div>`;
  }
  function updateMap(rows) {
    cluster.clearLayers();
    markersById = {};
    const withLL = rows.filter((d) => d.lat != null && d.lng != null);
    const ms = withLL.map((d) => {
      const m = L.marker([d.lat, d.lng], { icon: pinIcon(d), title: d.nama });
      m.bindPopup(() => popupHtml(d));
      markersById[d.id] = m;
      return m;
    });
    cluster.addLayers(ms);
    const miss = rows.length - withLL.length;
    $("#mapSub").textContent = `${nf.format(withLL.length)} titik LSP${S.pulau ? " · " + S.pulau : ""}${S.provinsi ? " · " + S.provinsi : ""}${miss ? ` · ${nf.format(miss)} tanpa lokasi` : ""}`;
    const regionKey = [S.pulau, S.provinsi, S.kabkota, S.kecamatan].join("|");
    if (regionKey !== lastRegionKey) {
      lastRegionKey = regionKey;
      if (withLL.length && regionKey !== "|||") map.flyToBounds(L.latLngBounds(withLL.map((d) => [d.lat, d.lng])).pad(0.15), { maxZoom: 12, duration: 0.6 });
      else if (regionKey === "|||") map.flyTo([-2.5, 118], 5, { duration: 0.6 });
    }
  }

  // ---------------- charts ----------------
  let chPulau, chExpiry, chKab;
  function chartDefaults() {
    Chart.defaults.font.family = css("--font");
    Chart.defaults.font.size = 12;
    Chart.defaults.color = css("--fg-2");
    Chart.defaults.borderColor = css("--grid");
  }
  const tooltip = () => ({ backgroundColor: css("--fg"), titleColor: css("--surface"), bodyColor: css("--surface"), padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true });

  function buildCharts(rows) {
    chartDefaults();
    const surface = css("--surface");
    // 1) per pulau, stacked by jenis
    const pc = {}; PULAU.forEach((p) => (pc[p] = { P1: 0, P2: 0, P3: 0 }));
    rows.forEach((d) => { if (pc[d.pulau]) pc[d.pulau][d.jenis]++; });
    const pulauDs = JENIS.map((j) => ({
      label: j.k, data: PULAU.map((p) => pc[p][j.k]), backgroundColor: css(`--${j.k.toLowerCase()}`),
      borderColor: surface, borderWidth: { right: 2 }, borderSkipped: false, borderRadius: 3, barThickness: 16,
    }));
    chPulau = upsert(chPulau, "#chPulau", {
      type: "bar",
      data: { labels: PULAU, datasets: pulauDs },
      options: {
        indexAxis: "y", maintainAspectRatio: false, animation: { duration: 250 },
        scales: { x: { stacked: true, grid: { color: css("--grid") }, border: { display: false }, ticks: { precision: 0 } }, y: { stacked: true, grid: { display: false }, border: { color: css("--line-strong") } } },
        plugins: { legend: { position: "top", align: "end", labels: { usePointStyle: true, pointStyle: "circle", boxWidth: 8, boxHeight: 8 } }, tooltip: { ...tooltip(), mode: "index", intersect: false, callbacks: { footer: (it) => `Total: ${nf.format(it.reduce((a, b) => a + b.raw, 0))}` } } },
        onClick: (e, els) => { if (els.length) { const p = PULAU[els[0].index]; setPulau(S.pulau === p ? "" : p); } },
        onHover: (e, els) => (e.native.target.style.cursor = els.length ? "pointer" : "default"),
      },
    });

    // 2) expiry per year, stacked by status
    const years = []; for (let y = TODAY.getFullYear() - 2; y <= TODAY.getFullYear() + 5; y++) years.push(y);
    const ec = {}; years.forEach((y) => (ec[y] = { aktif: 0, segera: 0, habis: 0 }));
    rows.forEach((d) => { const y = +d.tglBerakhir.slice(0, 4); if (ec[y]) ec[y][d.status]++; });
    const stCol = { aktif: css("--ok"), segera: css("--warn"), habis: css("--crit") };
    chExpiry = upsert(chExpiry, "#chExpiry", {
      type: "bar",
      data: { labels: years.map(String), datasets: STATUS.map((s) => ({ label: s.lbl, data: years.map((y) => ec[y][s.k]), backgroundColor: stCol[s.k], borderColor: surface, borderWidth: { top: 2 }, borderSkipped: false, borderRadius: 3, maxBarThickness: 28 })) },
      options: {
        maintainAspectRatio: false, animation: { duration: 250 },
        scales: { x: { stacked: true, grid: { display: false }, border: { color: css("--line-strong") } }, y: { stacked: true, grid: { color: css("--grid") }, border: { display: false }, ticks: { precision: 0 } } },
        plugins: { legend: { position: "top", align: "end", labels: { usePointStyle: true, pointStyle: "circle", boxWidth: 8, boxHeight: 8 } }, tooltip: { ...tooltip(), mode: "index", intersect: false } },
        onClick: (e, els) => { if (els.length) { const y = years[els[0].index]; const k = `y${y - TODAY.getFullYear()}`; S.preset = ""; S.from = `${y}-01-01`; S.to = `${y}-12-31`; if (PRESETS.some((p) => p.k === k)) S.preset = k; render(); } },
        onHover: (e, els) => (e.native.target.style.cursor = els.length ? "pointer" : "default"),
      },
    });

    // 3) kab/kota terbanyak
    const kc = {};
    rows.forEach((d) => { if (d.kabkota !== UNKNOWN) { const k = d.kabkota + "|" + d.provinsi; kc[k] = (kc[k] || 0) + 1; } });
    const sc = Object.entries(kc).sort((a, b) => b[1] - a[1]).slice(0, 8);
    chKab = upsert(chKab, "#chKab", {
      type: "bar",
      data: { labels: sc.map((x) => x[0].split("|")[0].replace(/^Kabupaten /, "Kab. ")), datasets: [{ label: "LSP", data: sc.map((x) => x[1]), backgroundColor: css("--p1"), borderRadius: 3, barThickness: 14 }] },
      options: {
        indexAxis: "y", maintainAspectRatio: false, animation: { duration: 250 },
        scales: { x: { grid: { color: css("--grid") }, border: { display: false }, ticks: { precision: 0 } }, y: { grid: { display: false }, border: { color: css("--line-strong") } } },
        plugins: { legend: { display: false }, tooltip: { ...tooltip(), callbacks: { title: (it) => sc[it[0].dataIndex][0].replace("|", ", ") } } },
        onClick: (e, els) => { if (els.length) { const [kab, prov] = sc[els[0].index][0].split("|"); if (S.kabkota === kab) { S.kabkota = S.kecamatan = ""; } else { S.provinsi = prov; S.pulau = provToPulau[prov]; S.kabkota = kab; S.kecamatan = ""; } page = 1; render(); } },
        onHover: (e, els) => (e.native.target.style.cursor = els.length ? "pointer" : "default"),
      },
    });
  }
  function upsert(chart, sel, cfg) {
    if (chart) { chart.data = cfg.data; chart.options = cfg.options; chart.update(); return chart; }
    return new Chart($(sel), cfg);
  }

  // ---------------- table ----------------
  function sortRows(rows) {
    return rows.slice().sort((a, b) => {
      const x = a[sortKey], y = b[sortKey];
      if (x == null || x === "") return 1;
      if (y == null || y === "") return -1;
      return (typeof x === "number" ? x - y : String(x).localeCompare(String(y), "id")) * sortDir;
    });
  }
  function buildTable(rows) {
    const size = +$("#pageSize").value;
    const pages = Math.max(1, Math.ceil(rows.length / size));
    page = Math.min(page, pages);
    const sorted = sortRows(rows);
    const slice = sorted.slice((page - 1) * size, page * size);
    $("#table tbody").innerHTML = slice.length ? slice.map((d) => `
      <tr data-id="${d.id}" tabindex="0">
        <td class="mono">${esc(d.noLisensi)}</td>
        <td class="nm">${esc(d.nama)}</td>
        <td><span class="jbadge ${d.jenis}">${d.jenis}</span></td>
        <td class="mono">${d.jmlSkema != null ? d.jmlSkema : "–"}</td>
        <td class="loc">${esc(d.kabkota)}${d.kecamatan !== UNKNOWN ? `<small>Kec. ${esc(d.kecamatan)}</small>` : ""}</td>
        <td>${esc(d.provinsi)}</td>
        <td class="mono">${d.tglBerakhir ? fmtDate(d.tglBerakhir) : "–"}</td>
        <td>${statusPill(d)}<div class="mono" style="font-size:11.5px;margin-top:2px">${sisaText(d)}</div></td>
      </tr>`).join("") : `<tr><td colspan="8" class="empty">Tidak ada LSP yang cocok. Coba longgarkan filter atau klik “Atur ulang”.</td></tr>`;
    document.querySelectorAll("#table th").forEach((th) => th.setAttribute("aria-sort", th.dataset.k === sortKey ? (sortDir > 0 ? "ascending" : "descending") : "none"));
    $("#tableSub").textContent = `${nf.format(rows.length)} LSP sesuai filter`;
    // pager
    const from = rows.length ? (page - 1) * size + 1 : 0, to = Math.min(page * size, rows.length);
    const nums = [];
    for (let p = 1; p <= pages; p++) if (p === 1 || p === pages || Math.abs(p - page) <= 1) nums.push(p); else if (nums[nums.length - 1] !== "…") nums.push("…");
    $("#pager").innerHTML = `<span class="info">Menampilkan ${nf.format(from)}–${nf.format(to)} dari ${nf.format(rows.length)}</span>
      <button data-pg="${page - 1}" ${page <= 1 ? "disabled" : ""} aria-label="Halaman sebelumnya">‹</button>
      ${nums.map((p) => p === "…" ? `<span>…</span>` : `<button data-pg="${p}" ${p === page ? 'aria-current="page"' : ""}>${p}</button>`).join("")}
      <button data-pg="${page + 1}" ${page >= pages ? "disabled" : ""} aria-label="Halaman berikutnya">›</button>`;
  }

  // ---------------- drawer ----------------
  function openDetail(id, fly = true) {
    const d = DATA.find((x) => x.id === +id);
    if (!d) return;
    const row = (k, v) => v ? `<dt>${k}</dt><dd>${v}</dd>` : "";
    const web = d.web ? (/^https?:/i.test(d.web) ? d.web : "https://" + d.web) : "";
    $("#dJenis").className = `jbadge ${d.jenis}`;
    $("#dJenis").textContent = `${d.jenis} · ${JENIS.find((j) => j.k === d.jenis).desc}`;
    $("#drawerBody").innerHTML = `
      <div><h2>${esc(d.nama)}</h2><div class="lic">${esc(d.noLisensi)}${d.sk ? " · SK " + esc(d.sk) : ""}</div></div>
      <div class="validity">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">${statusPill(d)}<b style="font-variant-numeric:tabular-nums">${sisaText(d)}</b></div>
        <div class="dates"><span>Berlaku s.d. ${d.tglBerakhir ? fmtDate(d.tglBerakhir) : "–"}</span><span>Status di BNSP: ${esc(d.statusResmi || "–")}</span></div>
      </div>
      <dl class="dl">
        ${row("Jumlah skema", d.jmlSkema != null ? String(d.jmlSkema) : "")}
        ${row("Alamat", esc(d.alamat || "Belum terdaftar"))}
        ${row("Kecamatan", esc(d.kecamatan))}
        ${row("Kab/Kota", esc(d.kabkota))}
        ${row("Provinsi", esc(d.provinsi))}
        ${row("Telepon", esc(d.telepon))}
        ${row("Email", esc(d.email))}
        ${row("Website", web ? `<a href="${esc(web)}" target="_blank" rel="noopener">${esc(d.web)}</a>` : "")}
        ${row("Lokasi peta", esc(AKURASI[d.akurasi] || ""))}
      </dl>
      <a class="btn btn-ghost" style="justify-content:center;text-decoration:none" href="https://bnsp.go.id/lsp/${encodeURIComponent(d.slug)}" target="_blank" rel="noopener">Lihat di bnsp.go.id ↗</a>
      ${d.lat != null ? `<button class="btn btn-ghost" id="btnShowOnMap" style="justify-content:center">Tampilkan di peta</button>` : ""}`;
    if ($("#btnShowOnMap")) $("#btnShowOnMap").onclick = () => { closeDetail(); focusMarker(d.id); };
    $("#drawer").classList.add("open");
    $("#drawer").setAttribute("aria-hidden", "false");
    if (fly && window.innerWidth > 900) focusMarker(d.id, false);
  }
  function closeDetail() { $("#drawer").classList.remove("open"); $("#drawer").setAttribute("aria-hidden", "true"); }
  function focusMarker(id, scroll = true) {
    const m = markersById[id];
    if (!m) return;
    if (scroll) $("#map").scrollIntoView({ behavior: "smooth", block: "center" });
    cluster.zoomToShowLayer(m, () => m.openPopup());
  }

  // ---------------- render ----------------
  function render() {
    const rows = filtered();
    buildPulauTabs(); buildRegionSelects(); buildChecks(); buildPresets(); buildChips();
    buildKpis(rows); buildProvList(rows); updateMap(rows); buildCharts(rows); buildTable(rows);
  }
  function setPulau(p) {
    S.pulau = p;
    if (S.provinsi && provToPulau[S.provinsi] !== p && p) S.provinsi = S.kabkota = S.kecamatan = "";
    if (!p) S.provinsi = S.kabkota = S.kecamatan = "";
    page = 1; render();
  }

  // ---------------- export ----------------
  function filterSummary() {
    const parts = [];
    parts.push(`Pulau: ${S.pulau || "Semua"}`);
    parts.push(`Provinsi: ${S.provinsi || "Semua"}`);
    if (S.kabkota) parts.push(`Kab/Kota: ${S.kabkota}`);
    if (S.kecamatan) parts.push(`Kecamatan: ${S.kecamatan}`);
    parts.push(`Jenis: ${[...S.jenis].sort().join(", ") || "-"}`);
    parts.push(`Status: ${[...S.status].map(statusLabel).join(", ") || "-"}`);
    if (S.from || S.to) parts.push(`Berakhir: ${S.from ? fmtDate(S.from) : "..."} s.d. ${S.to ? fmtDate(S.to) : "..."}`);
    if (S.q) parts.push(`Kata kunci: "${S.q}"`);
    return parts;
  }
  const fileStamp = () => isoDate(new Date()).replace(/-/g, "");
  const exportRow = (d, i) => ({
    "No": i + 1, "No. Lisensi": d.noLisensi, "No. SK": d.sk, "Nama LSP": d.nama, "Jenis": d.jenis,
    "Alamat": d.alamat, "Kecamatan": d.kecamatan, "Kabupaten/Kota": d.kabkota, "Provinsi": d.provinsi, "Pulau": d.pulau,
    "Berlaku s.d.": d.tglBerakhir, "Sisa Hari": d.sisa, "Status": statusLabel(d.status), "Status di BNSP": d.statusResmi,
    "Jml Skema": d.jmlSkema, "Telepon": d.telepon, "Email": d.email, "Website": d.web,
    "Latitude": d.lat, "Longitude": d.lng, "Ketepatan Lokasi": AKURASI[d.akurasi] || "", "Halaman BNSP": `https://bnsp.go.id/lsp/${d.slug}`,
  });

  function exportXlsx() {
    const rows = sortRows(filtered());
    if (!rows.length) return toast("Tidak ada data untuk diekspor.");
    const wb = XLSX.utils.book_new();
    // Sheet ringkasan
    const j = countBy(rows, "jenis"), s = countBy(rows, "status");
    const aoa = [
      ["Rekap LSP Terlisensi BNSP"], [`Diekspor: ${fmtDate(isoDate(new Date()))}`], [`Sumber: bnsp.go.id/lsp, diambil ${META.diambil ? fmtDate(META.diambil) : "-"}. Kecamatan & titik lokasi sebagian hasil perkiraan.`], [],
      ["Filter aktif"], ...filterSummary().map((p) => [p]), [],
      ["Ringkasan", "Jumlah"], ["Total LSP", rows.length], ["LSP P1", j.P1 || 0], ["LSP P2", j.P2 || 0], ["LSP P3", j.P3 || 0],
      ["Aktif", s.aktif || 0], ["Segera berakhir (≤ 6 bln)", s.segera || 0], ["Kedaluwarsa", s.habis || 0], [],
      ["Rekap per provinsi", "Pulau", "P1", "P2", "P3", "Total"],
    ];
    const byProv = {};
    rows.forEach((d) => { (byProv[d.provinsi] ||= { P1: 0, P2: 0, P3: 0, n: 0 }); byProv[d.provinsi][d.jenis]++; byProv[d.provinsi].n++; });
    Object.entries(byProv).sort((a, b) => b[1].n - a[1].n).forEach(([p, c]) => aoa.push([p, provToPulau[p], c.P1, c.P2, c.P3, c.n]));
    const ws1 = XLSX.utils.aoa_to_sheet(aoa);
    ws1["!cols"] = [{ wch: 42 }, { wch: 22 }, { wch: 8 }, { wch: 8 }, { wch: 8 }, { wch: 8 }];
    const ws2 = XLSX.utils.json_to_sheet(rows.map(exportRow));
    ws2["!cols"] = [5, 20, 26, 48, 7, 50, 22, 28, 26, 20, 12, 10, 16, 18, 10, 18, 30, 30, 10, 10, 26, 50].map((w) => ({ wch: w }));
    ws2["!autofilter"] = { ref: ws2["!ref"] };
    XLSX.utils.book_append_sheet(wb, ws2, "Data LSP");
    XLSX.utils.book_append_sheet(wb, ws1, "Ringkasan");
    XLSX.writeFile(wb, `LSP_BNSP_${fileStamp()}.xlsx`);
    toast(`Excel berisi ${nf.format(rows.length)} LSP sedang diunduh.`);
  }

  function exportPdf() {
    const rows = sortRows(filtered());
    if (!rows.length) return toast("Tidak ada data untuk diekspor.");
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: "landscape", unit: "mm", format: "a4" });
    const W = doc.internal.pageSize.getWidth();
    const red = [200, 25, 31];
    // header band
    doc.setFillColor(...red); doc.rect(0, 0, W, 22, "F");
    doc.setTextColor(255); doc.setFont("helvetica", "bold"); doc.setFontSize(15);
    doc.text("Rekap LSP Terlisensi BNSP", 12, 10);
    doc.setFont("helvetica", "normal"); doc.setFontSize(9);
    doc.text(`Diekspor ${fmtDate(isoDate(new Date()))} · Sumber: bnsp.go.id/lsp (diambil ${META.diambil ? fmtDate(META.diambil) : "-"})`, 12, 16);
    doc.setTextColor(30);
    doc.setFontSize(8.5);
    doc.text(doc.splitTextToSize("Filter: " + filterSummary().join("  |  "), W - 24), 12, 29);
    // KPI boxes
    const j = countBy(rows, "jenis"), s = countBy(rows, "status");
    const kp = [["Total LSP", rows.length], ["LSP P1", j.P1 || 0], ["LSP P2", j.P2 || 0], ["LSP P3", j.P3 || 0], ["Segera berakhir", s.segera || 0], ["Kedaluwarsa", s.habis || 0]];
    const bw = (W - 24 - 5 * 4) / 6;
    kp.forEach(([k, v], i) => {
      const x = 12 + i * (bw + 4);
      doc.setDrawColor(220); doc.setFillColor(i === 0 ? 253 : 248, i === 0 ? 236 : 249, i === 0 ? 234 : 251);
      doc.roundedRect(x, 36, bw, 17, 2, 2, "FD");
      doc.setFontSize(8); doc.setTextColor(110); doc.text(k, x + 4, 42);
      doc.setFontSize(14); doc.setFont("helvetica", "bold"); doc.setTextColor(20); doc.text(nf.format(v), x + 4, 50);
      doc.setFont("helvetica", "normal");
    });
    // charts as images (rendered on white for print)
    const colW = (W - 24 - 8) / 2;
    let chartsBottom = 64;
    [[chPulau, "LSP per pulau (menurut jenis)"], [chExpiry, "Lisensi berakhir per tahun (menurut status)"]].forEach(([ch, title], i) => {
      const x = 12 + i * (colW + 8);
      const ratio = ch.canvas.height / ch.canvas.width;
      let w = colW, h = w * ratio;
      if (h > 70) { h = 70; w = h / ratio; }   // jaga proporsi grafik
      doc.setFontSize(9.5); doc.setFont("helvetica", "bold"); doc.text(title, x, 61); doc.setFont("helvetica", "normal");
      try { doc.addImage(chartPng(ch), "PNG", x, 64, w, h); } catch (e) { /* abaikan */ }
      chartsBottom = Math.max(chartsBottom, 64 + h);
    });
    // per-province table
    const byProv = {};
    rows.forEach((d) => { (byProv[d.provinsi] ||= { P1: 0, P2: 0, P3: 0, n: 0, segera: 0, habis: 0 }); const c = byProv[d.provinsi]; c[d.jenis]++; c.n++; if (d.status !== "aktif") c[d.status]++; });
    const ry = chartsBottom + 10;
    doc.setFontSize(12); doc.setFont("helvetica", "bold"); doc.text("Rekap per provinsi", 12, ry); doc.setFont("helvetica", "normal");
    doc.autoTable({
      startY: ry + 3, margin: { left: 12, right: 12, top: 14 },
      head: [["Provinsi", "Pulau", "P1", "P2", "P3", "Segera berakhir", "Kedaluwarsa", "Total"]],
      body: Object.entries(byProv).sort((a, b) => b[1].n - a[1].n).map(([p, c]) => [p, provToPulau[p], c.P1, c.P2, c.P3, c.segera, c.habis, c.n]),
      styles: { fontSize: 8.5, cellPadding: 1.8 }, headStyles: { fillColor: [20, 24, 33] }, alternateRowStyles: { fillColor: [247, 248, 250] },
      columnStyles: { 2: { halign: "right" }, 3: { halign: "right" }, 4: { halign: "right" }, 5: { halign: "right" }, 6: { halign: "right" }, 7: { halign: "right", fontStyle: "bold" } },
    });
    // full list
    doc.addPage();
    doc.setFontSize(12); doc.setFont("helvetica", "bold"); doc.text(`Daftar LSP (${nf.format(rows.length)})`, 12, 14); doc.setFont("helvetica", "normal");
    doc.autoTable({
      startY: 18, margin: { left: 12, right: 12 },
      head: [["No", "No. Lisensi", "Nama LSP", "Jenis", "Skema", "Kecamatan", "Kab/Kota", "Provinsi", "Berlaku s.d.", "Status"]],
      body: rows.map((d, i) => [i + 1, d.noLisensi, d.nama, d.jenis, d.jmlSkema ?? "-", d.kecamatan, d.kabkota, d.provinsi, d.tglBerakhir ? fmtDate(d.tglBerakhir) : "-", statusLabel(d.status)]),
      styles: { fontSize: 7.5, cellPadding: 1.5, overflow: "linebreak" }, headStyles: { fillColor: red }, alternateRowStyles: { fillColor: [247, 248, 250] },
      columnStyles: { 0: { cellWidth: 9, halign: "right" }, 2: { cellWidth: 62 }, 3: { cellWidth: 11, halign: "center" } },
      didParseCell: (h) => {
        if (h.section !== "body") return;
        if (h.column.index === 9) { const v = h.cell.raw; h.cell.styles.textColor = v === "Aktif" ? [0, 99, 0] : v === "Kedaluwarsa" ? [177, 42, 42] : [138, 90, 0]; h.cell.styles.fontStyle = "bold"; }
        if (h.column.index === 3) { const c = { P1: [42, 120, 214], P2: [235, 104, 52], P3: [27, 175, 122] }[h.cell.raw]; if (c) { h.cell.styles.textColor = c; h.cell.styles.fontStyle = "bold"; } }
      },
    });
    const n = doc.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i); doc.setFontSize(7.5); doc.setTextColor(130);
      doc.text(`Peta LSP BNSP · halaman ${i} dari ${n}`, W - 12, doc.internal.pageSize.getHeight() - 6, { align: "right" });
    }
    doc.save(`LSP_BNSP_${fileStamp()}.pdf`);
    toast(`PDF berisi ${nf.format(rows.length)} LSP sedang diunduh.`);
  }
  // Render ulang grafik dengan latar putih untuk PDF agar terbaca saat dicetak
  function chartPng(ch) {
    const src = ch.canvas;
    const c = document.createElement("canvas");
    c.width = src.width; c.height = src.height;
    const g = c.getContext("2d");
    g.fillStyle = "#ffffff"; g.fillRect(0, 0, c.width, c.height);
    g.drawImage(src, 0, 0);
    return c.toDataURL("image/png");
  }

  let toastT;
  function toast(msg) { const t = $("#toast"); t.textContent = msg; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => (t.hidden = true), 2800); }

  // ---------------- events ----------------
  function bind() {
    $("#pulauTabs").addEventListener("click", (e) => { const b = e.target.closest(".ptab"); if (b) setPulau(b.dataset.v); });
    let qT;
    $("#fSearch").addEventListener("input", (e) => { clearTimeout(qT); qT = setTimeout(() => { S.q = e.target.value.trim().toLowerCase(); page = 1; render(); }, 200); });
    $("#fProv").addEventListener("change", (e) => { S.provinsi = e.target.value; S.kabkota = S.kecamatan = ""; if (S.provinsi) S.pulau = provToPulau[S.provinsi]; page = 1; render(); });
    $("#fKab").addEventListener("change", (e) => { S.kabkota = e.target.value; S.kecamatan = ""; page = 1; render(); });
    $("#fKec").addEventListener("change", (e) => { S.kecamatan = e.target.value; page = 1; render(); });
    $("#fJenis").addEventListener("change", (e) => { const v = e.target.value; S.jenis.has(v) ? S.jenis.delete(v) : S.jenis.add(v); page = 1; render(); });
    $("#fStatus").addEventListener("change", (e) => { const v = e.target.value; S.status.has(v) ? S.status.delete(v) : S.status.add(v); page = 1; render(); });
    $("#fPresets").addEventListener("click", (e) => { const b = e.target.closest(".preset"); if (b) { applyPreset(b.dataset.k); page = 1; render(); } });
    $("#fFrom").addEventListener("change", (e) => { S.from = e.target.value; S.preset = ""; page = 1; render(); });
    $("#fTo").addEventListener("change", (e) => { S.to = e.target.value; S.preset = ""; page = 1; render(); });
    $("#btnReset").addEventListener("click", () => { S = initial(); $("#fSearch").value = ""; page = 1; render(); });
    $("#activeChips").addEventListener("click", (e) => { const b = e.target.closest("button[data-k]"); if (b) { clearChip(b.dataset.k); page = 1; render(); } });
    $("#provList").addEventListener("click", (e) => {
      const b = e.target.closest(".prov"); if (!b) return;
      const p = b.dataset.p;
      if (S.provinsi === p) { S.provinsi = S.kabkota = S.kecamatan = ""; } else { S.provinsi = p; S.kabkota = S.kecamatan = ""; S.pulau = provToPulau[p]; }
      page = 1; render();
    });
    $("#table thead").addEventListener("click", (e) => { const th = e.target.closest("th"); if (!th) return; const k = th.dataset.k; if (sortKey === k) sortDir *= -1; else { sortKey = k; sortDir = 1; } buildTable(filtered()); });
    $("#table tbody").addEventListener("click", (e) => { const tr = e.target.closest("tr[data-id]"); if (tr) openDetail(tr.dataset.id); });
    $("#table tbody").addEventListener("keydown", (e) => { if (e.key === "Enter") { const tr = e.target.closest("tr[data-id]"); if (tr) openDetail(tr.dataset.id); } });
    $("#pageSize").addEventListener("change", () => { page = 1; buildTable(filtered()); });
    $("#pager").addEventListener("click", (e) => { const b = e.target.closest("button[data-pg]"); if (b && !b.disabled) { page = +b.dataset.pg; buildTable(filtered()); $("#table").scrollIntoView({ behavior: "smooth", block: "start" }); } });
    $("#map").addEventListener("click", (e) => { const b = e.target.closest("button[data-detail]"); if (b) openDetail(b.dataset.detail, false); });
    $("#btnDrawerClose").addEventListener("click", closeDetail);
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") { closeDetail(); closeFilters(); } });
    $("#btnXlsx").addEventListener("click", exportXlsx);
    $("#btnPdf").addEventListener("click", exportPdf);
    // mobile filter drawer
    $("#btnFilterOpen").addEventListener("click", () => { $("#sidebar").classList.add("open"); $("#scrim").hidden = false; });
    $("#btnFilterClose").addEventListener("click", closeFilters);
    $("#scrim").addEventListener("click", closeFilters);
    // tema berubah → warna grafik ikut
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    (mq.addEventListener ? mq.addEventListener("change", () => buildCharts(filtered())) : mq.addListener(() => buildCharts(filtered())));
    new MutationObserver(() => buildCharts(filtered())).observe(document.documentElement, { attributes: true, attributeFilter: ["data-theme"] });
  }
  function closeFilters() { $("#sidebar").classList.remove("open"); $("#scrim").hidden = true; }

  if (window.innerWidth < 600) $("#pageSize").value = "10";
  if (META.diambil) {
    $("#footSrc").insertAdjacentHTML("beforeend", ` Diambil ${fmtDate(META.diambil)} (${nf.format(META.jumlah)} LSP).`);
    $("#srcBadge").textContent = `bnsp.go.id · ${fmtDate(META.diambil)}`;
  }
  initMap();
  bind();
  render();
})();
