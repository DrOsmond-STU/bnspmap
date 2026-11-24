# Peta LSP BNSP — Purwarupa Dashboard

Purwarupa UI/UX untuk memetakan Lembaga Sertifikasi Profesi (LSP) P1, P2, dan P3 yang berlisensi BNSP.

![Dashboard](docs/dashboard.png)

Data diambil dari daftar LSP resmi di <https://bnsp.go.id/lsp> (2.700 LSP: aktif, kedaluwarsa, dan tidak aktif),
lengkap dengan jenis (P1/P2/P3), nomor lisensi, SK, masa berlaku, alamat, dan kontak dari halaman detail tiap LSP.

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
js/lsp-data.js    data LSP hasil unduhan (dibuat otomatis)
js/app.js         filter, peta, grafik, tabel, export
tools/            skrip pengambil & pengolah data
vendor/           Leaflet 1.9.4, MarkerCluster 1.5.3, Chart.js 4.4.1, SheetJS 0.18.5, jsPDF 2.5.1, jsPDF-AutoTable 3.8.2
```

## Sumber data dan cara lokasi ditentukan

| Data | Sumber |
|------|--------|
| Nama, no. lisensi, SK, jenis, masa berlaku, status, alamat, kontak, jumlah skema | Halaman daftar & detail LSP di bnsp.go.id |
| Titik koordinat (sekitar 1.100 LSP) | Peta LSP di beranda bnsp.go.id (`/lsp-map`) |
| Provinsi, kab/kota, kecamatan | Dari titik koordinat (reverse geocoding OpenStreetMap Nominatim); bila tidak ada titik, dicocokkan dari teks alamat dengan referensi wilayah Kemendagri ([idn-area-data](https://www.npmjs.com/package/idn-area-data)) |
| Titik untuk LSP tanpa koordinat | Pusat kecamatan atau kab/kota hasil geocoding, ditandai "perkiraan" di panel detail |

Catatan:
- Status **Aktif / Segera berakhir (≤ 6 bulan) / Kedaluwarsa** dihitung dari tanggal masa berlaku terhadap hari ini. Status asli di BNSP tetap ditampilkan di panel detail dan ikut diekspor.
- Sebagian alamat di BNSP tidak lengkap, sehingga beberapa LSP masuk kelompok "Lokasi belum diketahui".
- Beberapa halaman detail di bnsp.go.id tidak bisa dibuka (404). Untuk LSP tersebut, jenis LSP diperkirakan dari namanya dan diberi tanda "perkiraan".

## Memperbarui data

```bash
tools/update-data.sh
```

Skrip ini mengunduh ulang daftar & detail LSP, menentukan wilayah, lalu menulis ulang `js/lsp-data.js`.
Butuh akses ke bnsp.go.id, nominatim.openstreetmap.org, dan registry npm. Prosesnya sekitar 30–60 menit.
Setelah selesai, commit `js/lsp-data.js` lalu deploy ulang.

## Format data

`js/lsp-data.js` berisi `window.LSP_META` dan `window.LSP_DATA`, yaitu array objek seperti:

```js
{
  id: 1, slug: "lsp-smk-pelayaran-kristen-tuban",   // slug = alamat halaman detail di bnsp.go.id/lsp/<slug>
  nama: "LSP SMK Pelayaran Kristen Tuban",
  jenis: "P1", jenisPerkiraan: false,
  noLisensi: "BNSP-LSP-1305-ID", sk: "KEP.2482/BNSP/XI/2021",
  tglBerakhir: "2026-11-29", statusResmi: "Aktif",
  alamat: "...", telepon: "...", email: "...", web: "...", jmlSkema: 1,
  pulau: "Jawa", provinsi: "Jawa Timur", kabkota: "Kabupaten Tuban", kecamatan: "...",
  lat: -6.9, lng: 112.05,
  akurasi: "titik"   // titik | kecamatan | kabkota | provinsi | tidak ada
}
```
