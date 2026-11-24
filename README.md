# Peta LSP BNSP — Purwarupa Dashboard

Purwarupa UI/UX untuk memetakan Lembaga Sertifikasi Profesi (LSP) P1, P2, dan P3 yang berlisensi BNSP.

![Dashboard](docs/dashboard.png)

> **Penting:** data di purwarupa ini adalah **data contoh sintetis** (1.922 LSP) yang meniru struktur
> daftar LSP di <https://bnsp.go.id/>. Angkanya bukan data resmi BNSP. Lihat bagian *Mengganti dengan data asli*.

## Cara menjalankan

Tidak perlu build. Semua pustaka sudah disertakan di folder `vendor/`.

```bash
python3 -m http.server 8000
```

Lalu buka <http://localhost:8000>. File `index.html` juga bisa dibuka langsung di browser.
Peta dasar (OpenStreetMap) dan font memerlukan koneksi internet.

## Fitur sesuai kebutuhan

| # | Kebutuhan | Implementasi |
|---|-----------|--------------|
| 1 | Tampilkan LSP di peta geografis | Peta Leaflet + OpenStreetMap, titik dikelompokkan (cluster) dengan angka jumlah LSP, warna titik menurut jenis (P1/P2/P3). Klik titik untuk popup dan detail. |
| 2 | Filter per pulau | Tab pulau di bawah header (Sumatera, Jawa, Bali & Nusa Tenggara, Kalimantan, Sulawesi, Maluku, Papua) lengkap dengan jumlah. Klik batang grafik "LSP per pulau" juga memfilter. |
| 3 | Filter provinsi, kabupaten/kota, kecamatan | Tiga dropdown bertingkat di panel filter. Klik kartu di "Daftar provinsi" juga memfilter. Peta otomatis zoom ke wilayah terpilih. |
| 4 | Filter P1, P2, P3 | Kotak centang jenis LSP, dengan jumlah per jenis. |
| 5 | Filter masa berlaku | Status lisensi (Aktif > 6 bulan, Segera berakhir ≤ 6 bulan, Kedaluwarsa), pintasan (≤ 3/6/12 bulan, tahun tertentu), dan rentang tanggal berakhir. Klik batang grafik "Lisensi berakhir per tahun" juga memfilter. |
| 6 | Dashboard + export XLS/PDF | KPI, peta, daftar provinsi, 3 grafik, tabel bisa diurutkan dan dipaginasi, panel detail LSP. **Export Excel** (.xlsx: sheet *Data LSP* + *Ringkasan*) dan **Export PDF** (A4 lanskap: ringkasan, grafik, rekap per provinsi, daftar LSP). Keduanya mengikuti filter yang aktif. |

Tambahan: pencarian nama/no. lisensi, filter sektor, chip filter aktif yang bisa dihapus satu per satu,
tampilan mobile (panel filter jadi laci), dan mode gelap mengikuti pengaturan sistem.

## Struktur

```
index.html        halaman dashboard
css/style.css     gaya (token warna terang/gelap, responsif)
js/data.js        data wilayah + generator data contoh LSP
js/app.js         filter, peta, grafik, tabel, export
vendor/           Leaflet 1.9.4, MarkerCluster 1.5.3, Chart.js 4.4.1, SheetJS 0.18.5, jsPDF 2.5.1, jsPDF-AutoTable 3.8.2
```

## Mengganti dengan data asli

Ganti isi `window.LSP_DATA` di `js/data.js` (atau muat dari API/JSON) dengan array objek berformat:

```js
{
  id: 1,
  noLisensi: "BNSP-LSP-0001-ID",
  nama: "LSP ...",
  jenis: "P1",             // "P1" | "P2" | "P3"
  sektor: "Pariwisata",
  pulau: "Jawa",           // salah satu dari window.LSP_PULAU
  provinsi: "Jawa Timur",
  kabkota: "Kota Surabaya",
  kecamatan: "Gubeng",
  alamat: "Jl. ...",
  lat: -7.2575, lng: 112.7521,
  tglLisensi: "2022-02-16", // YYYY-MM-DD
  tglBerakhir: "2027-02-16",
  jmlSkema: 12, jmlAsesor: 16, jmlTUK: 4,
  telepon: "...", email: "..."
}
```

`window.LSP_REGIONS` menentukan pilihan dropdown provinsi → kab/kota → kecamatan; sesuaikan bila memakai data asli.
Status dan sisa masa berlaku dihitung otomatis dari `tglBerakhir` terhadap tanggal hari ini.
Data kecamatan dan koordinat biasanya belum tersedia di daftar BNSP, sehingga perlu geocoding alamat LSP.
