/* =========================================================================
 * Data wilayah + generator data CONTOH LSP.
 * Purwarupa ini memakai data sintetis (bukan data resmi BNSP) dengan struktur
 * yang sama seperti daftar LSP di bnsp.go.id. Untuk memakai data asli, ganti
 * isi window.LSP_DATA dengan array objek bertipe sama (lihat README.md).
 * ========================================================================= */
(function () {
  // [pulau, provinsi, jumlahLSP, [[kab/kota, lat, lng, [kecamatan...]], ...]]
  const REGIONS = [
    ["Sumatera", "Aceh", 18, [["Kota Banda Aceh", 5.5483, 95.3238, ["Baiturrahman", "Syiah Kuala", "Kuta Alam"]], ["Kota Lhokseumawe", 5.1801, 97.1507, ["Banda Sakti", "Muara Dua"]]]],
    ["Sumatera", "Sumatera Utara", 70, [["Kota Medan", 3.5952, 98.6722, ["Medan Baru", "Medan Petisah", "Medan Kota", "Medan Johor"]], ["Kabupaten Deli Serdang", 3.53, 98.87, ["Lubuk Pakam", "Percut Sei Tuan"]], ["Kota Pematangsiantar", 2.9595, 99.0687, ["Siantar Barat", "Siantar Timur"]], ["Kota Binjai", 3.6001, 98.4854, ["Binjai Kota", "Binjai Utara"]]]],
    ["Sumatera", "Sumatera Barat", 35, [["Kota Padang", -0.9471, 100.4172, ["Padang Barat", "Padang Utara", "Kuranji"]], ["Kota Bukittinggi", -0.3055, 100.3692, ["Guguk Panjang", "Mandiangin Koto Selayan"]]]],
    ["Sumatera", "Riau", 30, [["Kota Pekanbaru", 0.5071, 101.4478, ["Sukajadi", "Marpoyan Damai", "Tampan"]], ["Kota Dumai", 1.6655, 101.4476, ["Dumai Kota", "Dumai Timur"]]]],
    ["Sumatera", "Kepulauan Riau", 20, [["Kota Batam", 1.0456, 104.0305, ["Batam Kota", "Lubuk Baja", "Batu Aji", "Sekupang"]], ["Kota Tanjungpinang", 0.9186, 104.4554, ["Tanjungpinang Kota", "Bukit Bestari"]]]],
    ["Sumatera", "Jambi", 12, [["Kota Jambi", -1.6101, 103.6131, ["Telanaipura", "Jambi Selatan", "Kota Baru"]]]],
    ["Sumatera", "Sumatera Selatan", 35, [["Kota Palembang", -2.9761, 104.7754, ["Ilir Barat I", "Ilir Timur I", "Seberang Ulu I", "Bukit Kecil"]], ["Kota Prabumulih", -3.432, 104.235, ["Prabumulih Timur", "Prabumulih Barat"]]]],
    ["Sumatera", "Kepulauan Bangka Belitung", 7, [["Kota Pangkalpinang", -2.1316, 106.1169, ["Taman Sari", "Gerunggang"]], ["Kabupaten Belitung", -2.7469, 107.651, ["Tanjung Pandan"]]]],
    ["Sumatera", "Bengkulu", 8, [["Kota Bengkulu", -3.8004, 102.2655, ["Ratu Agung", "Gading Cempaka", "Teluk Segara"]]]],
    ["Sumatera", "Lampung", 25, [["Kota Bandar Lampung", -5.3971, 105.2668, ["Tanjung Karang Pusat", "Kedaton", "Rajabasa", "Way Halim"]], ["Kota Metro", -5.1131, 105.3067, ["Metro Pusat", "Metro Timur"]]]],
    ["Jawa", "DKI Jakarta", 310, [["Kota Jakarta Selatan", -6.2615, 106.8106, ["Kebayoran Baru", "Setiabudi", "Pancoran", "Cilandak", "Mampang Prapatan"]], ["Kota Jakarta Pusat", -6.1865, 106.8341, ["Gambir", "Menteng", "Tanah Abang", "Senen"]], ["Kota Jakarta Timur", -6.225, 106.9004, ["Matraman", "Jatinegara", "Cakung", "Duren Sawit"]], ["Kota Jakarta Barat", -6.1674, 106.7637, ["Grogol Petamburan", "Kebon Jeruk", "Palmerah", "Cengkareng"]], ["Kota Jakarta Utara", -6.1381, 106.8636, ["Kelapa Gading", "Tanjung Priok", "Penjaringan", "Koja"]]]],
    ["Jawa", "Jawa Barat", 317, [["Kota Bandung", -6.9175, 107.6191, ["Coblong", "Sukajadi", "Lengkong", "Bandung Wetan"]], ["Kota Bekasi", -6.2383, 106.9756, ["Bekasi Barat", "Bekasi Selatan", "Medan Satria"]], ["Kabupaten Bekasi", -6.2974, 107.1485, ["Cikarang Utara", "Cikarang Selatan", "Tambun Selatan"]], ["Kota Bogor", -6.595, 106.8166, ["Bogor Tengah", "Bogor Barat", "Tanah Sareal"]], ["Kota Depok", -6.4025, 106.7942, ["Beji", "Pancoran Mas", "Sukmajaya"]], ["Kabupaten Karawang", -6.3227, 107.3376, ["Karawang Barat", "Telukjambe Timur"]], ["Kota Cirebon", -6.7063, 108.557, ["Kejaksan", "Kesambi"]], ["Kota Tasikmalaya", -7.3274, 108.2207, ["Cihideung", "Tawang"]], ["Kabupaten Purwakarta", -6.5569, 107.4431, ["Purwakarta", "Jatiluhur"]]]],
    ["Jawa", "Banten", 120, [["Kota Tangerang", -6.1783, 106.6319, ["Tangerang", "Cipondoh", "Karawaci"]], ["Kota Tangerang Selatan", -6.2886, 106.7179, ["Serpong", "Ciputat", "Pondok Aren"]], ["Kota Serang", -6.12, 106.1503, ["Serang", "Cipocok Jaya"]], ["Kota Cilegon", -6.0025, 106.011, ["Cilegon", "Jombang"]]]],
    ["Jawa", "Jawa Tengah", 231, [["Kota Semarang", -6.9667, 110.4167, ["Semarang Tengah", "Tembalang", "Banyumanik", "Gajahmungkur"]], ["Kota Surakarta", -7.5755, 110.8243, ["Laweyan", "Banjarsari", "Jebres"]], ["Kabupaten Banyumas", -7.4245, 109.2302, ["Purwokerto Utara", "Purwokerto Selatan"]], ["Kota Magelang", -7.4797, 110.2177, ["Magelang Tengah", "Magelang Utara"]], ["Kabupaten Kudus", -6.8048, 110.8405, ["Kota Kudus", "Jati"]], ["Kota Pekalongan", -6.8898, 109.6746, ["Pekalongan Barat", "Pekalongan Timur"]], ["Kota Tegal", -6.8694, 109.1402, ["Tegal Barat", "Tegal Timur"]]]],
    ["Jawa", "DI Yogyakarta", 80, [["Kabupaten Sleman", -7.716, 110.3556, ["Depok", "Mlati", "Ngaglik"]], ["Kota Yogyakarta", -7.7956, 110.3695, ["Gondokusuman", "Umbulharjo", "Jetis"]], ["Kabupaten Bantul", -7.8881, 110.3289, ["Bantul", "Sewon", "Kasihan"]]]],
    ["Jawa", "Jawa Timur", 332, [["Kota Surabaya", -7.2575, 112.7521, ["Gubeng", "Tegalsari", "Sukolilo", "Wonokromo", "Rungkut"]], ["Kota Malang", -7.9666, 112.6326, ["Lowokwaru", "Klojen", "Blimbing"]], ["Kabupaten Sidoarjo", -7.4478, 112.7183, ["Sidoarjo", "Waru", "Buduran"]], ["Kabupaten Gresik", -7.1539, 112.6561, ["Gresik", "Kebomas"]], ["Kota Kediri", -7.848, 112.0178, ["Kota", "Mojoroto"]], ["Kabupaten Jember", -8.1724, 113.7004, ["Kaliwates", "Sumbersari", "Patrang"]], ["Kota Madiun", -7.6298, 111.5239, ["Taman", "Kartoharjo"]], ["Kabupaten Banyuwangi", -8.2192, 114.3691, ["Banyuwangi", "Giri"]], ["Kabupaten Pasuruan", -7.6453, 112.9075, ["Bangil", "Pandaan"]]]],
    ["Bali & Nusa Tenggara", "Bali", 45, [["Kota Denpasar", -8.6705, 115.2126, ["Denpasar Barat", "Denpasar Timur", "Denpasar Selatan"]], ["Kabupaten Badung", -8.5819, 115.1771, ["Kuta", "Kuta Selatan", "Mengwi"]], ["Kabupaten Gianyar", -8.5442, 115.3256, ["Gianyar", "Ubud"]], ["Kabupaten Buleleng", -8.112, 115.0882, ["Buleleng", "Sukasada"]]]],
    ["Bali & Nusa Tenggara", "Nusa Tenggara Barat", 15, [["Kota Mataram", -8.5833, 116.1167, ["Mataram", "Cakranegara", "Selaparang"]], ["Kabupaten Lombok Tengah", -8.7, 116.27, ["Praya", "Pujut"]], ["Kabupaten Sumbawa", -8.493, 117.42, ["Sumbawa", "Labuhan Badas"]]]],
    ["Bali & Nusa Tenggara", "Nusa Tenggara Timur", 12, [["Kota Kupang", -10.1772, 123.607, ["Kelapa Lima", "Oebobo", "Maulafa"]], ["Kabupaten Manggarai Barat", -8.4964, 119.8877, ["Komodo"]], ["Kabupaten Sikka", -8.62, 122.21, ["Alok"]]]],
    ["Kalimantan", "Kalimantan Barat", 15, [["Kota Pontianak", -0.0263, 109.3425, ["Pontianak Kota", "Pontianak Selatan", "Pontianak Barat"]], ["Kota Singkawang", 0.906, 108.9872, ["Singkawang Barat", "Singkawang Tengah"]]]],
    ["Kalimantan", "Kalimantan Tengah", 10, [["Kota Palangka Raya", -2.2161, 113.9135, ["Pahandut", "Jekan Raya"]], ["Kabupaten Kotawaringin Timur", -2.539, 112.95, ["Mentawa Baru Ketapang"]]]],
    ["Kalimantan", "Kalimantan Selatan", 20, [["Kota Banjarmasin", -3.3186, 114.5944, ["Banjarmasin Tengah", "Banjarmasin Utara", "Banjarmasin Selatan"]], ["Kota Banjarbaru", -3.4572, 114.8103, ["Landasan Ulin", "Banjarbaru Selatan"]]]],
    ["Kalimantan", "Kalimantan Timur", 30, [["Kota Samarinda", -0.5022, 117.1536, ["Samarinda Ulu", "Sungai Kunjang", "Samarinda Ilir"]], ["Kota Balikpapan", -1.2379, 116.8529, ["Balikpapan Kota", "Balikpapan Selatan", "Balikpapan Utara"]], ["Kota Bontang", 0.1333, 117.5, ["Bontang Utara", "Bontang Selatan"]]]],
    ["Kalimantan", "Kalimantan Utara", 3, [["Kota Tarakan", 3.3, 117.6333, ["Tarakan Tengah", "Tarakan Barat"]], ["Kabupaten Bulungan", 2.84, 117.37, ["Tanjung Selor"]]]],
    ["Sulawesi", "Sulawesi Utara", 12, [["Kota Manado", 1.4748, 124.8421, ["Wenang", "Malalayang", "Sario"]], ["Kota Bitung", 1.4404, 125.1217, ["Maesa", "Madidir"]]]],
    ["Sulawesi", "Gorontalo", 5, [["Kota Gorontalo", 0.5435, 123.0568, ["Kota Tengah", "Kota Selatan", "Dungingi"]]]],
    ["Sulawesi", "Sulawesi Tengah", 8, [["Kota Palu", -0.8917, 119.8707, ["Palu Barat", "Palu Timur", "Mantikulore"]], ["Kabupaten Morowali", -2.83, 121.95, ["Bahodopi", "Bungku Tengah"]]]],
    ["Sulawesi", "Sulawesi Barat", 3, [["Kabupaten Mamuju", -2.6748, 118.8886, ["Mamuju", "Simboro"]]]],
    ["Sulawesi", "Sulawesi Selatan", 60, [["Kota Makassar", -5.1477, 119.4327, ["Panakkukang", "Tamalanrea", "Rappocini", "Ujung Pandang"]], ["Kabupaten Gowa", -5.3103, 119.7426, ["Somba Opu", "Pallangga"]], ["Kota Parepare", -4.0135, 119.6255, ["Ujung", "Soreang"]], ["Kota Palopo", -2.9925, 120.1969, ["Wara", "Wara Utara"]]]],
    ["Sulawesi", "Sulawesi Tenggara", 8, [["Kota Kendari", -3.9985, 122.513, ["Kadia", "Mandonga", "Kambu"]], ["Kota Baubau", -5.47, 122.61, ["Wolio", "Murhum"]]]],
    ["Maluku", "Maluku", 6, [["Kota Ambon", -3.6954, 128.1814, ["Sirimau", "Nusaniwe", "Teluk Ambon"]], ["Kota Tual", -5.64, 132.74, ["Dullah Selatan"]]]],
    ["Maluku", "Maluku Utara", 4, [["Kota Ternate", 0.79, 127.38, ["Ternate Tengah", "Ternate Selatan"]], ["Kota Tidore Kepulauan", 0.68, 127.4, ["Tidore"]]]],
    ["Papua", "Papua", 6, [["Kota Jayapura", -2.5337, 140.7181, ["Jayapura Utara", "Abepura", "Heram"]], ["Kabupaten Jayapura", -2.57, 140.51, ["Sentani"]]]],
    ["Papua", "Papua Barat", 3, [["Kabupaten Manokwari", -0.8615, 134.062, ["Manokwari Barat", "Manokwari Timur"]]]],
    ["Papua", "Papua Barat Daya", 3, [["Kota Sorong", -0.8762, 131.2558, ["Sorong", "Sorong Manoi", "Sorong Timur"]]]],
    ["Papua", "Papua Tengah", 2, [["Kabupaten Mimika", -4.546, 136.888, ["Mimika Baru", "Kuala Kencana"]], ["Kabupaten Nabire", -3.37, 135.5, ["Nabire"]]]],
    ["Papua", "Papua Pegunungan", 1, [["Kabupaten Jayawijaya", -4.096, 138.95, ["Wamena"]]]],
    ["Papua", "Papua Selatan", 1, [["Kabupaten Merauke", -8.4932, 140.4018, ["Merauke"]]]],
  ];

  const PULAU = ["Sumatera", "Jawa", "Bali & Nusa Tenggara", "Kalimantan", "Sulawesi", "Maluku", "Papua"];

  // PRNG deterministik supaya data contoh selalu sama di setiap muat ulang
  let seed = 20261124;
  const rnd = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };
  const pick = (arr) => arr[Math.floor(rnd() * arr.length)];
  const weighted = (arr) => { // bobot menurun: kab/kota pertama paling banyak
    const w = arr.map((_, i) => 1 / Math.pow(i + 1, 0.9));
    let r = rnd() * w.reduce((a, b) => a + b, 0);
    for (let i = 0; i < arr.length; i++) { r -= w[i]; if (r <= 0) return arr[i]; }
    return arr[arr.length - 1];
  };

  const SEKTOR = ["Pariwisata", "Teknologi Informasi", "Konstruksi", "Manufaktur", "Energi & Pertambangan", "Keuangan & Perbankan", "Kesehatan", "Pertanian", "Logistik", "Kelautan & Perikanan", "Otomotif", "Ketenagalistrikan", "Administrasi Perkantoran", "Ekonomi Kreatif", "K3", "Pendidikan & Pelatihan"];
  const P3_SUFFIX = ["Indonesia", "Nusantara", "Mandiri", "Profesional", "Kompeten", "Utama", "Prima", "Sejahtera"];
  const P3_PREFIX = { "Pariwisata": ["Pariwisata", "Perhotelan", "Kuliner", "MICE"], "Teknologi Informasi": ["Teknologi Digital", "Informatika", "Telematika", "Keamanan Siber"], "Konstruksi": ["Konstruksi", "Jasa Konstruksi", "Tenaga Teknik"], "Manufaktur": ["Industri Manufaktur", "Logam & Mesin", "Tekstil"], "Energi & Pertambangan": ["Energi", "Pertambangan", "Migas"], "Keuangan & Perbankan": ["Perbankan", "Keuangan", "Pasar Modal", "Akuntansi"], "Kesehatan": ["Kesehatan", "Farmasi", "Perawat"], "Pertanian": ["Pertanian", "Perkebunan", "Agribisnis"], "Logistik": ["Logistik", "Rantai Pasok", "Kepelabuhanan"], "Kelautan & Perikanan": ["Kelautan", "Perikanan", "Pelayaran"], "Otomotif": ["Otomotif", "Alat Berat"], "Ketenagalistrikan": ["Ketenagalistrikan", "Instalasi Listrik"], "Administrasi Perkantoran": ["Manajemen Perkantoran", "Sekretaris", "SDM"], "Ekonomi Kreatif": ["Desain Grafis", "Animasi", "Film & Penyiaran"], "K3": ["K3 Umum", "Keselamatan Kerja"], "Pendidikan & Pelatihan": ["Pelatihan Kerja", "Instruktur"] };
  const UNIV = ["Universitas Negeri", "Universitas Muhammadiyah", "Universitas Islam", "Universitas Teknologi", "Institut Teknologi", "Universitas Kristen"];
  const PT = ["PT Semen", "PT Pelabuhan", "PT Energi", "PT Telekomunikasi", "PT Perkebunan", "PT Bank", "PT Kereta", "PT Konstruksi"];
  const P2_INSTANSI = ["BLK", "Pusdiklat Kementerian Ketenagakerjaan", "Balai Diklat Industri", "Politeknik Pelayaran", "Pusdiklat Kementerian PUPR", "Balai Diklat Pertanian", "Poltekkes"];

  const pad = (n, l) => String(n).padStart(l, "0");
  const short = (kab) => kab.replace(/^Kota |^Kabupaten /, "");
  const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1, 2)}-${pad(d.getDate(), 2)}`;

  const used = new Set();
  const data = [];
  let seq = 1;

  REGIONS.forEach(([pulau, prov, count, kabs]) => {
    for (let i = 0; i < count; i++) {
      const [kab, lat, lng, kecs] = weighted(kabs);
      const kec = pick(kecs);
      const city = short(kab);
      const r = rnd();
      const jenis = r < 0.56 ? "P1" : r < 0.72 ? "P2" : "P3";
      const sektor = pick(SEKTOR);
      let nama;
      if (jenis === "P1") {
        const t = rnd();
        if (t < 0.45) nama = `LSP P1 SMK ${rnd() < 0.6 ? "Negeri " + (1 + Math.floor(rnd() * 12)) : pick(["Telkom", "Muhammadiyah 1", "PGRI 2", "Bina Nusantara", "Taruna Bhakti"])} ${city}`;
        else if (t < 0.65) nama = `LSP P1 Politeknik ${pick(["Negeri", "Pariwisata", "Kesehatan", "Manufaktur", "Perkapalan"])} ${city}`;
        else if (t < 0.88) nama = `LSP P1 ${pick(UNIV)} ${city}`;
        else nama = `LSP P1 ${pick(PT)} ${pick(["Persero", "Nusantara", "Indonesia", "Tbk"])}`;
      } else if (jenis === "P2") {
        nama = `LSP P2 ${pick(P2_INSTANSI)} ${city}`;
      } else {
        nama = `LSP ${pick(P3_PREFIX[sektor])} ${pick(P3_SUFFIX)}`;
      }
      let base = nama, k = 2;
      while (used.has(nama)) nama = `${base} ${k++}`;
      used.add(nama);

      // Lisensi berlaku 5 tahun; tanggal terbit 2020-06 s.d. 2026-10
      const start = new Date(2020, 5, 1).getTime(), end = new Date(2026, 9, 31).getTime();
      const terbit = new Date(start + rnd() * (end - start));
      const berakhir = new Date(terbit); berakhir.setFullYear(berakhir.getFullYear() + 5);

      const slug = nama.toLowerCase().replace(/^lsp /, "").replace(/[^a-z0-9]+/g, "").slice(0, 22);
      data.push({
        id: seq,
        noLisensi: `BNSP-LSP-${pad(seq * 7 % 9973, 4)}-ID`,
        nama, jenis, sektor,
        pulau, provinsi: prov, kabkota: kab, kecamatan: kec,
        alamat: `Jl. ${pick(["Merdeka", "Sudirman", "Diponegoro", "Gatot Subroto", "Ahmad Yani", "Pahlawan", "Veteran", "Pemuda"])} No. ${1 + Math.floor(rnd() * 180)}, ${kec}`,
        lat: +(lat + (rnd() - 0.5) * 0.09).toFixed(5),
        lng: +(lng + (rnd() - 0.5) * 0.09).toFixed(5),
        tglLisensi: fmt(terbit),
        tglBerakhir: fmt(berakhir),
        jmlSkema: jenis === "P3" ? 8 + Math.floor(rnd() * 60) : 3 + Math.floor(rnd() * 20),
        jmlAsesor: 10 + Math.floor(rnd() * 140),
        jmlTUK: 1 + Math.floor(rnd() * (jenis === "P3" ? 30 : 6)),
        telepon: `(0${20 + Math.floor(rnd() * 70)}) ${pad(Math.floor(rnd() * 9999999), 7)}`,
        email: `info@lsp${slug}.id`,
      });
      seq++;
    }
  });

  window.LSP_PULAU = PULAU;
  window.LSP_REGIONS = REGIONS;
  window.LSP_DATA = data;
})();
