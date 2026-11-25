/* Halaman Pembaruan Data: pilih cakupan, antrekan crawl, kelola jadwal, pantau status & riwayat. */
(function () {
  "use strict";
  const $ = (s, el = document) => el.querySelector(s);
  const CSRF = document.body.dataset.csrf;
  const nf = new Intl.NumberFormat("id-ID");
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const UNKNOWN = "Belum diketahui";
  let state = null, timer = null;

  const fmtTime = (ts) => ts ? new Date(ts * 1000).toLocaleString("id-ID", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Asia/Jakarta" }) : "–";
  const fmtDur = (s) => s == null ? "–" : s < 60 ? `${s} dtk` : s < 3600 ? `${Math.floor(s / 60)} mnt ${s % 60} dtk` : `${Math.floor(s / 3600)} jam ${Math.floor((s % 3600) / 60)} mnt`;
  const ago = (ts) => { if (!ts) return "belum pernah"; const m = Math.round((Date.now() / 1000 - ts) / 60); return m < 1 ? "baru saja" : m < 60 ? `${m} menit lalu` : m < 1440 ? `${Math.round(m / 60)} jam lalu` : `${Math.round(m / 1440)} hari lalu`; };

  let toastT;
  function toast(msg) { const t = $("#toast"); t.textContent = msg; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => (t.hidden = true), 3500); }

  async function api(action, data) {
    const opt = data ? { method: "POST", body: new URLSearchParams({ action, csrf: CSRF, ...data }) } : {};
    const r = await fetch("crawl-api.php" + (data ? "" : "?action=" + action), { credentials: "same-origin", ...opt });
    if (r.status === 401) { location.href = "login.php"; throw new Error("Sesi berakhir"); }
    const j = await r.json().catch(() => ({ error: "Respons server tidak valid." }));
    if (!r.ok || j.error) throw new Error(j.error || "Permintaan gagal.");
    return j;
  }

  /* ---------- pemilih cakupan ---------- */
  const pickers = {};
  function makePicker(el) {
    const n = el.dataset.name;
    el.innerHTML = `
      <fieldset class="scope-opts">
        <legend class="flabel">Cakupan</legend>
        <label class="scope-opt"><input type="radio" name="type" value="nasional" checked><span><b>Nasional</b><small>Semua LSP di Indonesia</small></span></label>
        <label class="scope-opt"><input type="radio" name="type" value="provinsi"><span><b>Per provinsi</b><small>LSP di satu provinsi</small></span></label>
        <label class="scope-opt"><input type="radio" name="type" value="kabkota"><span><b>Sampai kabupaten/kota</b><small>LSP di satu kab/kota</small></span></label>
      </fieldset>
      <div class="scope-selects" hidden>
        <div><label class="fsub" for="${n}Prov">Provinsi</label><select id="${n}Prov" name="provinsi"></select></div>
        <div class="kab-wrap" hidden><label class="fsub" for="${n}Kab">Kabupaten/kota</label><select id="${n}Kab" name="kabkota"></select></div>
      </div>`;
    const p = { el, prov: $(`#${n}Prov`, el), kab: $(`#${n}Kab`, el) };
    p.type = () => $("input[name=type]:checked", el).value;
    p.sync = () => {
      const t = p.type();
      $(".scope-selects", el).hidden = t === "nasional";
      $(".kab-wrap", el).hidden = t !== "kabkota";
      const regs = state ? state.regions : {};
      const provs = Object.keys(regs).filter((x) => x !== UNKNOWN);
      if (p.prov.options.length !== provs.length + 1) {
        const cur = p.prov.value;
        p.prov.innerHTML = `<option value="">Pilih provinsi</option>` + provs.map((x) => `<option${x === cur ? " selected" : ""}>${esc(x)}</option>`).join("");
      }
      const kabs = p.prov.value ? Object.keys(regs[p.prov.value] || {}).filter((x) => x !== UNKNOWN) : [];
      const kcur = p.kab.value;
      p.kab.innerHTML = `<option value="">${p.prov.value ? "Pilih kab/kota" : "Pilih provinsi dulu"}</option>` + kabs.map((x) => `<option${x === kcur ? " selected" : ""}>${esc(x)}</option>`).join("");
      p.kab.disabled = !p.prov.value;
      if (p.onChange) p.onChange();
    };
    p.value = () => ({ type: p.type(), provinsi: p.prov.value, kabkota: p.kab.value });
    p.count = () => {
      if (!state) return null;
      const v = p.value(), regs = state.regions;
      if (v.type === "nasional") return state.data.total;
      if (!v.provinsi) return null;
      const ks = regs[v.provinsi] || {};
      if (v.type === "provinsi") return Object.values(ks).reduce((a, b) => a + b, 0);
      return v.kabkota ? ks[v.kabkota] || 0 : null;
    };
    el.addEventListener("change", p.sync);
    pickers[n] = p;
    return p;
  }
  document.querySelectorAll(".scope-picker").forEach(makePicker);

  // perkiraan durasi: ±1,5 menit membaca seluruh daftar + ±2,5 detail/detik
  function estimate(n, type) {
    if (n == null) return "Pilih wilayah untuk melihat perkiraan.";
    const min = Math.max(2, Math.round(1.5 + n / 150));
    const scopeNote = type === "nasional" ? "" : " Daftar seluruh LSP tetap dibaca untuk menemukan LSP baru.";
    return `≈ ${nf.format(n)} LSP akan diperiksa · perkiraan ${min} menit.${scopeNote}`;
  }
  pickers.now.onChange = () => { $("#estNow").textContent = estimate(pickers.now.count(), pickers.now.type()); };

  /* ---------- form crawl sekarang ---------- */
  $("#formNow").addEventListener("submit", async (e) => {
    e.preventDefault();
    const v = pickers.now.value();
    if (v.type !== "nasional" && !v.provinsi) return toast("Pilih provinsi terlebih dulu.");
    if (v.type === "kabkota" && !v.kabkota) return toast("Pilih kabupaten/kota terlebih dulu.");
    $("#btnNow").disabled = true;
    try {
      const r = await api("enqueue", v);
      toast(r.job.duplicate ? "Cakupan ini sudah ada di antrean." : "Crawl masuk antrean. Penjadwal server akan memulainya dalam ≤ 5 menit.");
      await load();
    } catch (err) { toast(err.message); }
    $("#btnNow").disabled = false;
  });

  /* ---------- jadwal ---------- */
  $("#schMonthday").innerHTML = Array.from({ length: 28 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join("");
  const syncFreq = () => { const f = $("#schFreq").value; $("#wrapWeekday").hidden = f !== "weekly"; $("#wrapMonthday").hidden = f !== "monthly"; };
  $("#schFreq").addEventListener("change", syncFreq); syncFreq();
  $("#formSched").addEventListener("submit", async (e) => {
    e.preventDefault();
    const v = pickers.sch.value();
    if (v.type !== "nasional" && !v.provinsi) return toast("Pilih provinsi untuk jadwal.");
    if (v.type === "kabkota" && !v.kabkota) return toast("Pilih kabupaten/kota untuk jadwal.");
    try {
      await api("schedule_save", { ...v, freq: $("#schFreq").value, weekday: $("#schWeekday").value, monthday: $("#schMonthday").value, time: $("#schTime").value });
      toast("Jadwal disimpan.");
      $("#schedAdd").open = false;
      await load();
    } catch (err) { toast(err.message); }
  });
  $("#schedList").addEventListener("click", async (e) => {
    const b = e.target.closest("button[data-act]"); if (!b) return;
    if (b.dataset.act === "schedule_delete" && !confirm("Hapus jadwal ini?")) return;
    try { await api(b.dataset.act, { id: b.dataset.id }); await load(); } catch (err) { toast(err.message); }
  });

  /* ---------- batal ---------- */
  $("#statusBody").addEventListener("click", async (e) => {
    const b = e.target.closest("button[data-cancel]"); if (!b) return;
    if (!confirm("Batalkan crawl ini? Data tidak akan diubah.")) return;
    try { const r = await api("cancel", { id: b.dataset.cancel }); toast(r.message); await load(); } catch (err) { toast(err.message); }
  });

  /* ---------- render ---------- */
  function renderStatus() {
    const s = state.status, q = state.queue, d = state.data;
    $("#dataInfo").textContent = `Data aktif: ${nf.format(d.total)} LSP · diperbarui ${d.meta.diambil ? new Date(d.meta.diambil + "T00:00:00").toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" }) : "–"}` +
      (d.meta.crawlTerakhir ? ` (${d.meta.crawlTerakhir.cakupan})` : d.crawled ? "" : " · data awal");
    let h = "";
    if (s.state === "running") {
      const pct = s.total ? Math.round((s.done / s.total) * 100) : 0;
      h += `<div class="run-box">
        <div class="run-top"><span class="pulse" aria-hidden="true"></span><b>Sedang crawl: ${esc(s.label)}</b>
          <button class="link-btn" data-cancel="${esc(s.job.id)}">Batalkan</button></div>
        <div class="run-phase">${esc(s.phase)}${s.total ? ` · ${nf.format(s.done)}/${nf.format(s.total)}` : ""}</div>
        <div class="bar-lg" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><i style="width:${pct}%"></i></div>
        <div class="run-meta">Mulai ${fmtTime(s.startedAt)} · berjalan ${fmtDur(state.now - s.startedAt)} · oleh ${esc(s.job.by)}</div>
      </div>`;
    }
    if (q.length) {
      h += `<div class="queue"><div class="flabel">Antrean (${q.length})</div><ul>` + q.map((j) =>
        `<li><span><b>${esc(scopeLabel(j.scope))}</b><small>${j.trigger === "jadwal" ? "Jadwal" : "Manual"} · ${esc(j.by)} · masuk ${ago(j.queuedAt)}</small></span>
         <button class="link-btn" data-cancel="${esc(j.id)}">Batalkan</button></li>`).join("") + `</ul>
         <p class="hint">Menunggu penjadwal server (berjalan tiap 5 menit).</p></div>`;
    }
    if (!h) h = `<p class="idle">Tidak ada crawl yang berjalan.</p>`;
    const seen = state.runnerSeen;
    const late = !seen || state.now - seen > 15 * 60;
    h += `<p class="runner ${late ? "warn" : ""}">${late ? "⚠ " : ""}Penjadwal server terakhir aktif: ${ago(seen)}${late ? " — periksa cron job di hosting." : ""}</p>`;
    $("#statusBody").innerHTML = h;
  }
  const scopeLabel = (sc) => sc.type === "nasional" ? "Nasional" : sc.type === "provinsi" ? `Provinsi ${sc.provinsi}` : `${sc.kabkota}, ${sc.provinsi}`;

  function renderSchedules() {
    const l = state.schedules;
    $("#schedList").innerHTML = l.length ? l.map((s) => `
      <li class="sched ${s.enabled ? "" : "off"}">
        <span class="sched-main"><b>${esc(s.scopeLabel)}</b><small>${esc(s.label)}${s.enabled ? ` · berikutnya ${fmtTime(s.nextRun)}` : " · nonaktif"}</small></span>
        <span class="sched-act">
          <button class="chip-btn" data-act="schedule_toggle" data-id="${esc(s.id)}" aria-pressed="${!!s.enabled}">${s.enabled ? "Aktif" : "Nonaktif"}</button>
          <button class="icon-btn" data-act="schedule_delete" data-id="${esc(s.id)}" aria-label="Hapus jadwal ${esc(s.scopeLabel)}">✕</button>
        </span>
      </li>`).join("") : `<li class="empty-sched">Belum ada jadwal. Contoh: crawl nasional setiap Minggu pukul 02.00.</li>`;
  }

  function renderHistory() {
    const h = state.history;
    const pill = (r) => ({ berhasil: '<span class="status aktif">Berhasil</span>', gagal: '<span class="status habis">Gagal</span>', dibatalkan: '<span class="status segera">Dibatalkan</span>' }[r] || esc(r));
    $("#historyBody").innerHTML = h.length ? h.map((x) => {
      const st = x.stats;
      const ch = st ? `<span class="chg"><b>${nf.format(st.baru)}</b> baru · <b>${nf.format(st.diperbarui)}</b> diperbarui · <b>${nf.format(st.dihapus)}</b> dihapus</span>` : `<span class="muted">${esc(x.message)}</span>`;
      return `<tr>
        <td class="mono">${fmtTime(x.startedAt)}</td>
        <td><b>${esc(x.label)}</b></td>
        <td>${x.trigger === "jadwal" ? "Jadwal" : "Manual"}<small class="by">${esc(x.by)}</small></td>
        <td class="mono">${fmtDur((x.finishedAt || 0) - (x.startedAt || 0))}</td>
        <td>${pill(x.result)}</td>
        <td>${ch}</td>
        <td><a class="link-btn" href="crawl-api.php?action=log&id=${encodeURIComponent(x.id)}" target="_blank" rel="noopener">Log</a></td>
      </tr>`;
    }).join("") : `<tr><td colspan="7" class="empty">Belum ada crawl.</td></tr>`;
  }

  async function load() {
    try {
      state = await api("state");
      renderStatus(); renderSchedules(); renderHistory();
      Object.values(pickers).forEach((p) => p.sync());
    } catch (err) { $("#statusBody").innerHTML = `<p class="runner warn">${esc(err.message)}</p>`; }
    clearTimeout(timer);
    const busy = state && (state.status.state === "running" || state.queue.length);
    timer = setTimeout(load, busy ? 4000 : 30000);
  }
  document.addEventListener("visibilitychange", () => { if (!document.hidden) load(); });
  load();
})();
