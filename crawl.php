<?php
declare(strict_types=1);
require __DIR__ . '/lib/crawl-state.php';
$user = require_login();
if (!is_admin($user)) {
    http_response_code(403);
    exit('Halaman ini hanya untuk administrator.');
}
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
$csrf = csrf_token();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Pembaruan Data · Peta LSP BNSP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="css/style.css?v=20261125c">
</head>
<body data-csrf="<?= e($csrf) ?>">
<header class="topbar">
  <div class="topbar-in">
    <a class="brand brand-link" href="./">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 32 32" width="32" height="32"><rect width="32" height="32" rx="8" fill="var(--brand)"/><path d="M16 6c-4 0-7 3-7 7 0 5 7 13 7 13s7-8 7-13c0-4-3-7-7-7zm0 10a3 3 0 110-6 3 3 0 010 6z" fill="#fff"/></svg>
      </span>
      <div>
        <h1>Peta LSP Terlisensi BNSP</h1>
        <p class="sub">Pembaruan data dari bnsp.go.id</p>
      </div>
    </a>
    <div class="actions">
      <a class="btn btn-ghost" href="./">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Kembali ke dashboard</span>
      </a>
      <form class="usermenu" method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <span class="who"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span class="who-name"><?= e($user['name']) ?></span></span>
        <button class="btn btn-ghost btn-logout" type="submit" aria-label="Keluar"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3M10 16l-4-4 4-4M6 12h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Keluar</span></button>
      </form>
    </div>
  </div>
</header>

<main class="crawl-page">
  <div class="crawl-head">
    <h2>Pembaruan data LSP</h2>
    <p>bnsp.go.id tidak menyediakan API, jadi data diambil ulang dengan membaca halaman daftar dan detail LSP (crawl).
       Crawl berjalan di server; halaman ini boleh ditutup.</p>
  </div>

  <div class="crawl-grid">
    <!-- STATUS -->
    <section class="card crawl-status" aria-labelledby="stTitle">
      <div class="card-head"><div><h3 id="stTitle">Status</h3><p class="card-sub" id="dataInfo">Memuat…</p></div></div>
      <div class="crawl-body" id="statusBody"><p class="muted">Memuat…</p></div>
    </section>

    <!-- CRAWL SEKARANG -->
    <section class="card" aria-labelledby="nowTitle">
      <div class="card-head"><div><h3 id="nowTitle">Crawl sekarang</h3><p class="card-sub">Pilih cakupan data yang diambil ulang</p></div></div>
      <form class="crawl-body" id="formNow">
        <div class="scope-picker" data-name="now"></div>
        <p class="estimate" id="estNow"></p>
        <button class="btn btn-primary" type="submit" id="btnNow">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M20 12a8 8 0 11-2.34-5.66M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Mulai crawl
        </button>
        <p class="hint">Crawl dimulai oleh penjadwal server dalam ≤ 5 menit setelah tombol ditekan.</p>
      </form>
    </section>

    <!-- JADWAL -->
    <section class="card crawl-sched" aria-labelledby="schTitle">
      <div class="card-head"><div><h3 id="schTitle">Jadwal otomatis</h3><p class="card-sub">Crawl berkala sesuai waktu yang ditentukan (WIB)</p></div></div>
      <div class="crawl-body">
        <ul class="sched-list" id="schedList"></ul>
        <details class="sched-add" id="schedAdd">
          <summary>+ Tambah jadwal</summary>
          <form id="formSched" class="sched-form">
            <div class="scope-picker" data-name="sch"></div>
            <div class="sched-row">
              <div><label class="fsub" for="schFreq">Frekuensi</label>
                <select id="schFreq" name="freq">
                  <option value="daily">Harian</option>
                  <option value="weekly" selected>Mingguan</option>
                  <option value="monthly">Bulanan</option>
                </select></div>
              <div id="wrapWeekday"><label class="fsub" for="schWeekday">Hari</label>
                <select id="schWeekday" name="weekday">
                  <option value="1">Senin</option><option value="2">Selasa</option><option value="3">Rabu</option><option value="4">Kamis</option>
                  <option value="5">Jumat</option><option value="6">Sabtu</option><option value="7" selected>Minggu</option>
                </select></div>
              <div id="wrapMonthday" hidden><label class="fsub" for="schMonthday">Tanggal</label>
                <select id="schMonthday" name="monthday"></select></div>
              <div><label class="fsub" for="schTime">Jam (WIB)</label><input id="schTime" name="time" type="time" value="02:00" required></div>
            </div>
            <p class="hint">Disarankan malam/dini hari agar tidak membebani situs BNSP. Tanggal dibatasi 1–28 supaya ada di setiap bulan.</p>
            <button class="btn btn-ghost" type="submit">Simpan jadwal</button>
          </form>
        </details>
      </div>
    </section>
  </div>

  <section class="card crawl-history" aria-labelledby="hisTitle">
    <div class="card-head"><div><h3 id="hisTitle">Riwayat crawl</h3><p class="card-sub">20 crawl terakhir</p></div></div>
    <div class="tablewrap">
      <table class="dtable history-table">
        <thead><tr><th>Mulai</th><th>Cakupan</th><th>Pemicu</th><th>Durasi</th><th>Hasil</th><th>Perubahan</th><th></th></tr></thead>
        <tbody id="historyBody"><tr><td colspan="7" class="empty">Memuat…</td></tr></tbody>
      </table>
    </div>
  </section>
</main>
<div class="toast" id="toast" role="status" hidden></div>
<script src="js/crawl.js?v=20261125c"></script>
</body>
</html>
