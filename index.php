<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
$user = require_login();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');   // tile OSM mewajibkan Referer
$csrf = csrf_token();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Peta LSP BNSP</title>
<meta name="robots" content="noindex">
<meta name="description" content="Purwarupa dashboard pemetaan Lembaga Sertifikasi Profesi (LSP) P1, P2, P3 berlisensi BNSP">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="vendor/leaflet.css">
<link rel="stylesheet" href="vendor/MarkerCluster.css">
<link rel="stylesheet" href="css/style.css?v=20261125">
</head>
<body>
<header class="topbar">
  <div class="topbar-in">
    <button class="icon-btn only-mobile" id="btnFilterOpen" aria-label="Buka filter">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
    </button>
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="32" height="32"><rect width="32" height="32" rx="8" fill="var(--brand)"/><path d="M16 6c-4 0-7 3-7 7 0 5 7 13 7 13s7-8 7-13c0-4-3-7-7-7zm0 10a3 3 0 110-6 3 3 0 010 6z" fill="#fff"/></svg>
      </span>
      <div>
        <h1>Peta LSP Terlisensi BNSP</h1>
        <p class="sub">Sebaran Lembaga Sertifikasi Profesi P1 · P2 · P3 di Indonesia</p>
      </div>
    </div>
    <span class="demo-badge" id="srcBadge" title="Data diambil dari bnsp.go.id/lsp">Sumber: bnsp.go.id</span>
    <div class="actions">
      <button class="btn btn-ghost" id="btnXlsx">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M14 3v6h6M8.5 12.5l4 5M12.5 12.5l-4 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        <span>Export Excel</span>
      </button>
      <button class="btn btn-primary" id="btnPdf">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 20h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Export PDF</span>
      </button>
      <form class="usermenu" method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <span class="who" title="Masuk sebagai <?= e($user['username']) ?>">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          <span class="who-name"><?= e($user['name']) ?></span>
        </span>
        <button class="btn btn-ghost btn-logout" type="submit" aria-label="Keluar">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 16l-4-4 4-4M6 12h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Keluar</span>
        </button>
      </form>
    </div>
  </div>
</header>

<nav class="pulau-bar" aria-label="Filter pulau">
  <div class="pulau-in" id="pulauTabs" role="tablist"></div>
</nav>

<div class="layout">
  <!-- ============ SIDEBAR FILTER ============ -->
  <aside class="sidebar" id="sidebar" aria-label="Filter data">
    <div class="sidebar-head">
      <h2>Filter</h2>
      <button class="link-btn" id="btnReset">Atur ulang</button>
      <button class="icon-btn only-mobile" id="btnFilterClose" aria-label="Tutup filter">✕</button>
    </div>

    <div class="fgroup">
      <label class="flabel" for="fSearch">Cari LSP</label>
      <div class="search">
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <input id="fSearch" type="search" placeholder="Nama, no. lisensi, alamat…" autocomplete="off">
      </div>
    </div>

    <div class="fgroup">
      <span class="flabel">Wilayah</span>
      <label class="fsub" for="fProv">Provinsi</label>
      <select id="fProv"></select>
      <label class="fsub" for="fKab">Kabupaten / Kota</label>
      <select id="fKab"></select>
      <label class="fsub" for="fKec">Kecamatan</label>
      <select id="fKec"></select>
    </div>

    <div class="fgroup">
      <span class="flabel">Jenis LSP</span>
      <div class="checks" id="fJenis"></div>
      <p class="hint">P1 = pihak pertama (lembaga diklat/industri) · P2 = pihak kedua (instansi/rantai pasok) · P3 = pihak ketiga (asosiasi/umum)</p>
    </div>

    <div class="fgroup">
      <span class="flabel">Masa berlaku lisensi</span>
      <div class="checks" id="fStatus"></div>
      <div class="presets" id="fPresets"></div>
      <div class="daterow">
        <div><label class="fsub" for="fFrom">Berakhir dari</label><input id="fFrom" type="date"></div>
        <div><label class="fsub" for="fTo">sampai</label><input id="fTo" type="date"></div>
      </div>
    </div>

  </aside>
  <div class="scrim only-mobile" id="scrim" hidden></div>

  <!-- ============ KONTEN ============ -->
  <main class="content">
    <div class="chips" id="activeChips" aria-live="polite"></div>

    <section class="kpis" id="kpis" aria-label="Ringkasan"></section>

    <section class="maprow">
      <div class="card mapcard">
        <div class="card-head">
          <div>
            <h3>Peta sebaran LSP</h3>
            <p class="card-sub" id="mapSub"></p>
          </div>
          <div class="legend" id="mapLegend"></div>
        </div>
        <div id="map" role="region" aria-label="Peta sebaran LSP"></div>
      </div>
      <div class="card provcard">
        <div class="card-head">
          <div>
            <h3>Daftar provinsi</h3>
            <p class="card-sub">Klik untuk memfilter</p>
          </div>
        </div>
        <ol class="provlist" id="provList"></ol>
      </div>
    </section>

    <section class="chartrow">
      <div class="card">
        <div class="card-head"><div><h3>LSP per pulau</h3><p class="card-sub">Menurut jenis · klik batang untuk memfilter</p></div></div>
        <div class="chartbox"><canvas id="chPulau" aria-label="Grafik LSP per pulau"></canvas></div>
      </div>
      <div class="card">
        <div class="card-head"><div><h3>Lisensi berakhir per tahun</h3><p class="card-sub">Menurut status saat ini</p></div></div>
        <div class="chartbox"><canvas id="chExpiry" aria-label="Grafik lisensi berakhir per tahun"></canvas></div>
      </div>
      <div class="card">
        <div class="card-head"><div><h3>Kab/kota terbanyak</h3><p class="card-sub">8 teratas · klik untuk memfilter</p></div></div>
        <div class="chartbox"><canvas id="chKab" aria-label="Grafik kabupaten/kota dengan LSP terbanyak"></canvas></div>
      </div>
    </section>

    <section class="card tablecard">
      <div class="card-head">
        <div><h3>Daftar LSP</h3><p class="card-sub" id="tableSub"></p></div>
        <div class="tabletools">
          <label for="pageSize" class="fsub inline">Baris</label>
          <select id="pageSize"><option>10</option><option selected>25</option><option>50</option><option>100</option></select>
        </div>
      </div>
      <div class="tablewrap">
        <table class="dtable" id="table">
          <thead><tr>
            <th data-k="noLisensi">No. Lisensi</th>
            <th data-k="nama">Nama LSP</th>
            <th data-k="jenis">Jenis</th>
            <th data-k="jmlSkema">Skema</th>
            <th data-k="kabkota">Kab/Kota</th>
            <th data-k="provinsi">Provinsi</th>
            <th data-k="tglBerakhir">Berlaku s.d.</th>
            <th data-k="sisa">Status</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="pager" id="pager"></div>
    </section>

    <footer class="foot">
      <span id="footSrc">Sumber data: <a href="https://bnsp.go.id/lsp" target="_blank" rel="noopener">bnsp.go.id/lsp</a>.</span>
      Wilayah dan titik lokasi ditentukan dari koordinat peta LSP BNSP; bila tidak tersedia, diperkirakan dari alamat (pusat kecamatan/kab-kota).
      Untuk keperluan resmi, rujuk halaman detail LSP di bnsp.go.id.
    </footer>
  </main>
</div>

<!-- Panel detail -->
<aside class="drawer" id="drawer" aria-hidden="true" aria-label="Detail LSP">
  <div class="drawer-head">
    <span class="jbadge" id="dJenis"></span>
    <button class="icon-btn" id="btnDrawerClose" aria-label="Tutup detail">✕</button>
  </div>
  <div class="drawer-body" id="drawerBody"></div>
</aside>

<div class="toast" id="toast" role="status" hidden></div>

<script src="vendor/leaflet.js"></script>
<script src="vendor/leaflet.markercluster.js"></script>
<script src="vendor/chart.umd.js"></script>
<script src="vendor/xlsx.full.min.js"></script>
<script src="vendor/jspdf.umd.min.js"></script>
<script src="vendor/jspdf.plugin.autotable.min.js"></script>
<script src="data.php?v=<?= filemtime(__DIR__ . '/js/lsp-data.js') ?>"></script>
<script src="js/app.js?v=20261125b"></script>
</body>
</html>
