<?php
/**
 * Mesin crawl data LSP dari bnsp.go.id (dijalankan lewat CLI: tools/cron.php).
 *
 * Alur:
 *  1. Ambil titik peta LSP (/lsp-map) dan SELURUH halaman daftar (/lsp?hal=N) -> tahu LSP baru & yang hilang.
 *  2. Ambil halaman detail untuk LSP dalam cakupan (nasional / provinsi / kab-kota) + semua LSP baru.
 *  3. Tentukan wilayah (pakai hasil lama bila alamat & titik tidak berubah; selain itu reverse geocoding /
 *     pencocokan alamat + titik pusat wilayah via Nominatim).
 *  4. Gabungkan ke data aktif dan tulis ulang file data (versi sebelumnya disimpan sebagai cadangan).
 * Logika parsing & penentuan wilayah setara dengan tools/fetch.py + tools/enrich.py.
 */
declare(strict_types=1);

require_once __DIR__ . '/crawl-state.php';
require_once __DIR__ . '/regions.php';

class CrawlCancelled extends \RuntimeException {}

final class Crawler
{
    const BASE = 'https://bnsp.go.id';
    const UA = 'Mozilla/5.0 (compatible; bnspmap/1.0; +https://bnspmap.semestateknologiutama.com)';
    const NOMINATIM_UA = 'bnspmap/1.0 (LSP mapping dashboard; https://bnspmap.semestateknologiutama.com)';
    const PLACEHOLDER = [-6.2091, 106.8592];   // titik bawaan BNSP bila LSP belum mengisi lokasi
    const CONCURRENCY = 4;
    const JENIS = ['pertama' => 'P1', 'kesatu' => 'P1', 'kedua' => 'P2', 'ketiga' => 'P3'];

    /** @var callable */ private $log;
    /** @var callable */ private $progress;
    /** @var callable */ private $isCancelled;
    /** @var Regions|null */ private $regions = null;
    private $geoCacheDir;
    private $lastGeo = 0.0;
    public $stats = [];

    public function __construct(callable $log, callable $progress, callable $isCancelled)
    {
        $this->log = $log;
        $this->progress = $progress;
        $this->isCancelled = $isCancelled;
        $this->geoCacheDir = crawler_dir('geo');
    }

    private function log(string $msg): void { ($this->log)($msg); }
    private function progress(string $phase, int $done, int $total): void { ($this->progress)($phase, $done, $total); }
    private function checkCancel(): void
    {
        if (($this->isCancelled)()) {
            throw new CrawlCancelled('Dibatalkan oleh pengguna.');
        }
    }

    /* ======================= HTTP ======================= */

    /**
     * Unduh banyak URL paralel (maks CONCURRENCY), coba ulang 3x.
     * @param array<string,string> $urls  kunci => url
     * @return array<string,?string>      kunci => isi (null bila gagal; 404 dicatat di $notFound)
     */
    private function fetchMany(array $urls, string $phase, array &$notFound = [], ?callable $valid = null): array
    {
        $out = [];
        $pending = [];
        foreach ($urls as $k => $u) {
            $pending[] = [(string) $k, $u, 0];
        }
        $total = count($pending);
        $done = 0;
        $mh = curl_multi_init();
        $active = [];
        $start = function (array $item) use ($mh, &$active) {
            $ch = curl_init($item[1]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => self::UA, CURLOPT_HTTPHEADER => ['Accept-Language: id,en;q=0.8'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[(int) $ch] = [$ch, $item];
        };
        while ($pending || $active) {
            while ($pending && count($active) < self::CONCURRENCY) {
                $start(array_shift($pending));
            }
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $item] = $active[(int) $ch];
                unset($active[(int) $ch]);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $body = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                [$k, $u, $try] = $item;
                $ok = $info['result'] === CURLE_OK && $code === 200 && $body !== '' && $body !== null;
                if ($ok && $valid && !$valid($body)) {   // 200 tetapi bukan halaman yang diharapkan (mis. halaman galat/pembatasan)
                    $ok = false;
                    $code = 'konten tidak valid';
                }
                if ($ok) {
                    $out[$k] = $body;
                } elseif ($code === 404) {
                    $out[$k] = null;
                    $notFound[] = $k;
                } elseif ($try < 3) {
                    usleep((int) (1500000 * ($try + 1)));
                    $pending[] = [$k, $u, $try + 1];
                    continue;
                } else {
                    $out[$k] = null;
                    $this->log("Gagal mengunduh $u (HTTP $code, curl " . $info['result'] . ')');
                }
                $done++;
                if ($done % 10 === 0 || $done === $total) {
                    $this->progress($phase, $done, $total);
                    $this->checkCancel();
                }
            }
            usleep(150000);   // santun terhadap server BNSP
        }
        curl_multi_close($mh);
        return $out;
    }

    /* ======================= Nominatim ======================= */

    private function nominatim(string $url): ?array
    {
        $file = $this->geoCacheDir . '/' . md5($url) . '.json';
        if (is_file($file)) {
            return json_decode((string) file_get_contents($file), true);
        }
        for ($try = 0; $try < 3; $try++) {
            $wait = 1.1 - (microtime(true) - $this->lastGeo);   // kebijakan Nominatim: maks 1 permintaan/detik
            if ($wait > 0) usleep((int) ($wait * 1e6));
            $this->lastGeo = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => self::NOMINATIM_UA,
                                    CURLOPT_HTTPHEADER => ['Accept-Language: id']]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code === 200 && is_string($body)) {
                file_put_contents($file, $body);
                return json_decode($body, true);
            }
            sleep(5 * ($try + 1));
        }
        $this->log("Nominatim gagal: $url");
        return null;
    }

    private function reverse(float $lat, float $lng): ?array
    {
        return $this->nominatim(sprintf('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=%.6f&lon=%.6f&zoom=14&addressdetails=1', $lat, $lng));
    }

    private function search(string $q): ?array
    {
        $r = $this->nominatim('https://nominatim.openstreetmap.org/search?' . http_build_query(
            ['format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 1, 'countrycodes' => 'id', 'q' => $q]));
        return $r && isset($r[0]['lat']) ? $r : null;
    }

    private function centroid(string $kec, string $kab, string $prov): ?array
    {
        $r = null;
        if ($kec !== '') {
            $r = $this->search("Kecamatan $kec, $kab, $prov") ?: $this->search("$kec, $kab, $prov");
        }
        if (!$r && $kab !== '') {
            $r = $this->search("$kab, $prov");
        }
        if (!$r) {
            $r = $this->search($prov);
        }
        return $r ? [round((float) $r[0]['lat'], 6), round((float) $r[0]['lon'], 6)] : null;
    }

    /* ======================= parsing ======================= */

    private static function clean(string $x): string
    {
        $x = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $x), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $x));
    }

    private static function textLines(string $html): array
    {
        $body = preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $html);
        $txt = html_entity_decode(preg_replace('/<[^>]+>/', "\n", $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        foreach (explode("\n", $txt) as $l) {
            $l = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $l);
            if ($l !== '' && $l !== null) $out[] = $l;
        }
        return $out;
    }

    public static function parseListPage(string $html): array
    {
        $cards = [];
        $blocks = explode('<div class="bg-white rounded-lg border border-gray-100', $html);
        array_shift($blocks);
        foreach ($blocks as $blk) {
            if (!preg_match('/<h3[^>]*>\s*<a href="https:\/\/bnsp\.go\.id\/lsp\/([^"]+)"[^>]*>(.*?)<\/a>/s', $blk, $m)) continue;
            $badge = preg_match('/fa-sparkles[^>]*><\/i>\s*([^<]+)</', $blk, $b) || preg_match('/rounded-md flex items-center gap-1.5">\s*<i[^>]*><\/i>\s*([^<]+)</', $blk, $b) ? self::clean($b[1]) : '';
            $info = preg_match('/Info Lisensi:<\/span>(.*?)<\/p>/s', $blk, $i) ? self::clean($i[1]) : '';
            $loc = preg_match('/fa-location-dot[^>]*><\/i>\s*([^<]+)</', $blk, $l) ? self::clean($l[1]) : '';
            $cards[] = ['slug' => $m[1], 'nama' => self::clean($m[2]), 'badge' => $badge, 'info' => $info, 'lokasi' => $loc];
        }
        return $cards;
    }

    public static function pageCount(string $html): int
    {
        $i = strpos($html, 'Halaman');
        return $i !== false && preg_match('/\/\s*(\d+)\s*<\/span>/', substr($html, $i, 600), $m) ? (int) $m[1] : 0;
    }

    public static function parseDetail(string $html): array
    {
        $L = self::textLines($html);
        $d = [];
        $i = array_search('Profile LSP', $L, true);
        if ($i === false) return $d;
        $d['nama'] = $L[$i - 1] ?? '';
        $keys = ['No. SK Lisensi' => 'sk', 'No Lisensi' => 'noLisensi', 'Jenis' => 'jenisRaw', 'No Telp' => 'telp', 'No Hp' => 'hp',
                 'Email' => 'email', 'Website' => 'web', 'Masa Berlaku Sertifikat' => 'berlaku', 'Status Lisensi' => 'status'];
        $win = array_slice($L, $i, 60);
        $find = function (string $k) use ($L, $i) {
            for ($j = $i, $n = count($L); $j < $n; $j++) if ($L[$j] === $k) return $j;
            return null;
        };
        foreach ($keys as $k => $v) {
            if (in_array($k, $win, true)) {
                $j = $find($k);
                if (($L[$j + 1] ?? '') === ':' && isset($L[$j + 2]) && !isset($keys[$L[$j + 2]])) {
                    $d[$v] = $L[$j + 2];
                }
            }
        }
        if (in_array('Alamat', $win, true)) {
            $j = $find('Alamat');
            $d['alamat'] = ($L[$j + 1] ?? '') !== 'DATA SKEMA' ? ($L[$j + 1] ?? '') : '';
        }
        $a = $find('Unit');
        if ($a !== null) {
            for ($b = $a; $b < count($L); $b++) {
                if ($L[$b] === 'Nomor') {
                    $d['jmlSkema'] = intdiv($b - $a - 1, 3);
                    break;
                }
            }
        }
        // email dilindungi Cloudflare: data-cfemail = kunci XOR + isi
        $e = strpos($html, 'Email');
        if ($e !== false && preg_match('/data-cfemail="([0-9a-f]+)"/', substr($html, $e, 2000), $m)) {
            $bytes = hex2bin($m[1]);
            $key = ord($bytes[0]);
            $mail = '';
            for ($x = 1; $x < strlen($bytes); $x++) $mail .= chr(ord($bytes[$x]) ^ $key);
            $d['emailDecoded'] = $mail;
        }
        return $d;
    }

    private static function parseLatLng(?string $lok): ?array
    {
        $s = trim((string) $lok);
        if (preg_match('/^\s*(-?\d+),(\d+)\s*,\s*(-?\d+),(\d+)\s*$/', $s, $m)) {
            $lat = (float) "$m[1].$m[2]";
            $lng = (float) "$m[3].$m[4]";
        } elseif (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $s, $m)) {
            $lat = (float) $m[1];
            $lng = (float) $m[2];
        } else {
            return null;
        }
        if (!(-11.5 < $lat && $lat < 6.5 && 94 < $lng && $lng < 141.5)) return null;
        if (abs($lat - self::PLACEHOLDER[0]) < 1e-3 && abs($lng - self::PLACEHOLDER[1]) < 1e-3) return null;
        return [round($lat, 6), round($lng, 6)];
    }

    private static function keyName(string $s): string
    {
        $s = Regions::norm($s);
        $s = preg_replace('/ lsp | p1 | p2 | p3 | lembaga sertifikasi profesi /', ' ', $s);
        $s = preg_replace('/ lsp | p1 | p2 | p3 /', ' ', $s);
        return preg_replace('/\s+/', '', $s);
    }
    private static function keyNameNp(string $s): string { return self::keyName(preg_replace('/\(.*?\)/', ' ', $s)); }
    private static function keyWeb(?string $s): string
    {
        $s = strtolower((string) $s);
        $s = strpos($s, '://') !== false ? explode('/', explode('://', $s, 2)[1])[0] : explode('/', $s)[0];
        $s = preg_replace('/^www\./', '', trim($s));
        return strpos($s, '.') !== false ? $s : '';
    }
    private static function keyHp(?string $s): string
    {
        $d = preg_replace('/\D/', '', (string) $s);
        return strlen($d) >= 9 ? substr($d, -9) : '';
    }

    /* ======================= proses utama ======================= */

    /**
     * @return array{rows: array, meta: array, stats: array}
     */
    public function run(array $scope, array $baseRows): array
    {
        $t0 = time();
        $this->stats = ['cakupan' => scope_label($scope), 'baru' => 0, 'diperbarui' => 0, 'tetap' => 0, 'dihapus' => 0,
                        'detailGagal' => 0, 'geocode' => 0];
        $base = [];
        foreach ($baseRows as $r) $base[$r['slug']] = $r;

        // 1. titik peta + halaman daftar
        $this->progress('Langkah 1/5 · Mengambil titik peta BNSP', 0, 1);
        $mapRaw = $this->fetchMany(['map' => self::BASE . '/lsp-map'], 'Langkah 1/5 · Mengambil titik peta BNSP')['map'] ?? null;
        $mapd = $mapRaw ? (json_decode($mapRaw, true)['data'] ?? []) : [];
        $this->log('Titik peta BNSP: ' . count($mapd));
        if (!$mapd) $this->log('PERINGATAN: data titik peta kosong, titik lama dipertahankan bila ada.');

        $first = $this->fetchMany(['1' => self::BASE . '/lsp?hal=1'], 'Langkah 2/5 · Membaca daftar LSP')['1'] ?? null;
        if (!$first) throw new \RuntimeException('Halaman daftar LSP bnsp.go.id tidak bisa diakses.');
        $pages = self::pageCount($first);
        if ($pages < 1) throw new \RuntimeException('Jumlah halaman daftar LSP tidak terbaca (struktur situs BNSP mungkin berubah).');
        $urls = [];
        for ($p = 2; $p <= $pages; $p++) $urls[(string) $p] = self::BASE . "/lsp?hal=$p";
        $nf = [];
        $htmls = ['1' => $first] + $this->fetchMany($urls, "Langkah 2/5 · Membaca daftar LSP ($pages halaman)", $nf,
            function (string $h): bool { return strpos($h, 'Halaman') !== false; });
        $cards = [];
        $failedPages = 0;
        foreach ($htmls as $p => $h) {
            if ($h === null) { $failedPages++; continue; }
            foreach (self::parseListPage($h) as $c) $cards[$c['slug']] = $c;
        }
        $this->log("Daftar LSP: $pages halaman, " . count($cards) . " LSP, $failedPages halaman gagal");
        if (!$cards) throw new \RuntimeException('Tidak ada LSP yang terbaca dari daftar (struktur situs BNSP mungkin berubah).');
        $listComplete = $failedPages === 0 && count($cards) >= 0.9 * max(1, count($base));
        if (!$listComplete) $this->log('PERINGATAN: daftar tidak lengkap; tidak ada LSP yang dihapus pada crawl ini.');

        // 2. tentukan target
        $inScope = function (array $r) use ($scope): bool {
            if ($scope['type'] === 'nasional') return true;
            if (($r['provinsi'] ?? '') !== $scope['provinsi']) return false;
            return $scope['type'] === 'provinsi' || ($r['kabkota'] ?? '') === $scope['kabkota'];
        };
        $targets = [];
        foreach ($cards as $slug => $c) {
            if (!isset($base[$slug]) || $inScope($base[$slug])) $targets[] = (string) $slug;
        }
        $targetSet = array_flip($targets);
        $this->log('Detail yang diambil: ' . count($targets) . ' LSP');
        $durls = [];
        foreach ($targets as $slug) $durls[$slug] = self::BASE . '/lsp/' . rawurlencode(html_entity_decode($slug, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $notFound = [];
        $details = $this->fetchMany($durls, 'Langkah 3/5 · Mengambil detail LSP', $notFound,
            function (string $h): bool { return strpos($h, 'Profile LSP') !== false; });
        $notFoundSet = array_flip(array_map('strval', $notFound));
        if ($notFound) $this->log('Halaman detail 404 di bnsp.go.id: ' . count($notFound));

        // 3. indeks peta
        $idx = [];
        foreach ($mapd as $m) {
            foreach (['n:' . self::keyName((string) $m['nama']), 'p:' . self::keyNameNp((string) $m['nama']), 'w:' . self::keyWeb($m['web'] ?? ''), 'h:' . self::keyHp($m['hp'] ?? '')] as $k) {
                if (strlen($k) > 4) $idx[$k][] = $m;
            }
        }

        // 4. bangun rekaman
        $today = date('Y-m-d');
        $rows = [];
        $needGeo = [];
        $n = 0;
        foreach ($cards as $slug => $c) {
            $slug = (string) $slug;
            $old = $base[$slug] ?? null;
            if (!isset($targetSet[$slug])) {   // di luar cakupan: biarkan apa adanya
                $rows[$slug] = $old;
                continue;
            }
            $n++;
            if ($n % 25 === 0) { $this->progress('Langkah 4/5 · Menentukan wilayah', $n, count($targets)); $this->checkCancel(); }
            $html = $details[$slug] ?? null;
            if ($html !== null && !isset(self::parseDetail($html)['nama'])) {
                $html = null;
            }
            if ($html === null && $old && !isset($notFoundSet[$slug])) {   // gagal sementara: pertahankan data lama
                $this->stats['detailGagal']++;
                $rows[$slug] = $old;
                continue;
            }
            $d = $html !== null ? self::parseDetail($html) : [];
            $rec = $this->buildRecord($c, $d, $html !== null, $idx, $old, $needGeo);
            $rec['diperbarui'] = $today;
            if ($old === null) $this->stats['baru']++;
            elseif (self::changed($old, $rec)) $this->stats['diperbarui']++;
            else $this->stats['tetap']++;
            $rows[$slug] = $rec;
        }

        // LSP yang tidak ada lagi di daftar BNSP
        foreach ($base as $slug => $old) {
            if (isset($cards[$slug])) continue;
            if ($listComplete && $inScope($old)) {
                $this->stats['dihapus']++;
                $this->log('Dihapus (tidak ada lagi di BNSP): ' . $old['nama']);
            } else {
                $rows[$slug] = $old;
            }
        }

        // 5. titik pusat wilayah untuk LSP tanpa koordinat
        if ($needGeo) {
            $uniq = [];
            foreach ($needGeo as $slug => $q) $uniq[implode('|', $q)] = $q;
            $this->log('Geocoding titik pusat wilayah: ' . count($uniq));
            $res = [];
            $k = 0;
            foreach ($uniq as $key => $q) {
                $res[$key] = $this->centroid(...$q);
                $k++;
                $this->progress('Langkah 5/5 · Mencari titik lokasi (Nominatim)', $k, count($uniq));
                $this->checkCancel();
            }
            $this->stats['geocode'] = count($uniq);
            foreach ($needGeo as $slug => $q) {
                $ll = $res[implode('|', $q)] ?? null;
                if ($ll) { $rows[$slug]['lat'] = $ll[0]; $rows[$slug]['lng'] = $ll[1]; }
            }
        }

        $rows = array_values(array_filter($rows));
        usort($rows, function ($a, $b) { return strcasecmp($a['nama'], $b['nama']); });
        foreach ($rows as $i => &$r) $r['id'] = $i + 1;
        unset($r);
        $this->stats['total'] = count($rows);
        $this->stats['durasiDetik'] = time() - $t0;
        return ['rows' => $rows, 'stats' => $this->stats];
    }

    private static function changed(array $a, array $b): bool
    {
        foreach (['nama', 'jenis', 'noLisensi', 'sk', 'tglBerakhir', 'statusResmi', 'alamat', 'telepon', 'email', 'web', 'jmlSkema',
                  'provinsi', 'kabkota', 'kecamatan', 'lat', 'lng'] as $k) {
            $x = $a[$k] ?? null;
            $y = $b[$k] ?? null;
            if (($k === 'lat' || $k === 'lng') && $x !== null && $y !== null) {
                if (abs((float) $x - (float) $y) > 5e-6) return true;   // abaikan selisih pembulatan
                continue;
            }
            if ($x != $y) return true;
        }
        return false;
    }

    private function regions(): Regions
    {
        if (!$this->regions) {
            $this->log('Memuat referensi wilayah Kemendagri…');
            $this->regions = new Regions(__DIR__ . '/data');
        }
        return $this->regions;
    }

    private function buildRecord(array $c, array $d, bool $hasDetail, array $idx, ?array $old, array &$needGeo): array
    {
        $nama = ($d['nama'] ?? '') !== '' ? $d['nama'] : $c['nama'];
        $jr = strtolower($d['jenisRaw'] ?? '');
        $jenis = '';
        foreach (self::JENIS as $k => $v) if (strpos($jr, $k) !== false) { $jenis = $v; break; }
        $perk = false;
        if ($jenis === '') {
            $perk = true;
            if (preg_match('/\bP([123])\b/', $nama, $m)) $jenis = "P$m[1]";
            elseif (preg_match('/\b(smk|smks|smkn|slb|universitas|politeknik|institut|akademi|sekolah tinggi|stikes|poltek)\b/i', $nama)) $jenis = 'P1';
            elseif (preg_match('/\b(kementerian|balai|badan|pusdiklat)\b/i', $nama)) $jenis = 'P2';
            else $jenis = 'P3';
        }
        $nol = $d['noLisensi'] ?? (preg_match('/BNSP-LSP-\S+/', $c['info'] ?? '', $m) ? $m[0] : '');
        $berlaku = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['berlaku'] ?? '') ? $d['berlaku'] : '';
        $web = $d['web'] ?? '';
        $rec = [
            'slug' => $c['slug'], 'nama' => $nama, 'jenis' => $jenis, 'jenisPerkiraan' => $perk, 'noLisensi' => $nol, 'sk' => $d['sk'] ?? '',
            'tglBerakhir' => $berlaku,
            'statusResmi' => ($d['status'] ?? '') !== '' ? $d['status'] : (['Lisensi Aktif' => 'Aktif'][$c['badge']] ?? $c['badge']),
            'alamat' => $d['alamat'] ?? '', 'telepon' => ($d['hp'] ?? '') !== '' ? $d['hp'] : ($d['telp'] ?? ''),
            'email' => $hasDetail ? ($d['emailDecoded'] ?? '') : '', 'web' => in_array($web, ['-', '0'], true) ? '' : $web,
            'jmlSkema' => $d['jmlSkema'] ?? null,
        ];

        // titik peta BNSP
        $mm = null;
        foreach (['n:' . self::keyName($nama), 'p:' . self::keyNameNp($nama), 'w:' . self::keyWeb($web), 'h:' . self::keyHp(($d['hp'] ?? '') !== '' ? $d['hp'] : ($d['telp'] ?? ''))] as $k) {
            if (strlen($k) > 4 && count($idx[$k] ?? []) === 1) { $mm = $idx[$k][0]; break; }
        }
        $ll = $mm ? self::parseLatLng($mm['lokasi'] ?? '') : null;

        // pakai wilayah lama bila alamat & titik tidak berubah (hemat geocoding)
        if ($old && ($old['alamat'] ?? '') === $rec['alamat'] && isset($old['akurasi'])) {
            $sameTitik = $ll && ($old['akurasi'] === 'titik') && abs(($old['lat'] ?? 0) - $ll[0]) < 5e-6 && abs(($old['lng'] ?? 0) - $ll[1]) < 5e-6;
            $sameNoTitik = !$ll && $old['akurasi'] !== 'titik';
            if ($sameTitik || $sameNoTitik) {
                foreach (['provinsi', 'pulau', 'kabkota', 'kecamatan', 'lat', 'lng', 'akurasi'] as $k) {
                    if (array_key_exists($k, $old)) $rec[$k] = $old[$k];
                }
                return $rec;
            }
        }

        $R = $this->regions();
        $hint = implode(' ', array_filter([$c['lokasi'] ?? '', $mm['lokasi_lsp'] ?? '', $nama]));
        $alamat = $rec['alamat'];
        $reg = $dist = null;
        $akurasi = '';
        if ($ll) {
            [$reg, $dist] = $R->resolveReverse($this->reverse($ll[0], $ll[1]));
            $akurasi = 'titik';
            if ($reg && $reg[0] !== 'P' && !$dist) {
                [$r2, $d2] = $R->resolveText($alamat, $hint);
                if ($r2 === $reg && $d2) $dist = $d2;
            }
        }
        if (!$reg || $reg[0] === 'P') {
            [$r2, $d2] = $R->resolveText($alamat, $hint);
            if ($r2 && $r2[0] !== 'P') {
                if (!$reg || $R->reg[$r2]['province_code'] === substr($reg, 1)) { $reg = $r2; $dist = $d2; }
            } elseif ($r2 && !$reg) {
                $reg = $r2;
            }
        }
        if ($reg && $reg[0] !== 'P') {
            $pc = $R->reg[$reg]['province_code'];
            $rec += ['provinsi' => $R->provDisplay($pc), 'pulau' => $R->pulauOf($pc), 'kabkota' => $R->regDisplay($reg),
                     'kecamatan' => $dist ? $R->dist[$dist]['name'] : ''];
        } elseif ($reg) {
            $pc = substr($reg, 1);
            $rec += ['provinsi' => $R->provDisplay($pc), 'pulau' => $R->pulauOf($pc), 'kabkota' => '', 'kecamatan' => ''];
        } else {
            $rec += ['provinsi' => '', 'pulau' => '', 'kabkota' => '', 'kecamatan' => ''];
        }
        if ($ll) {
            $rec['lat'] = $ll[0];
            $rec['lng'] = $ll[1];
        } elseif ($rec['kabkota'] !== '') {
            $akurasi = $rec['kecamatan'] !== '' ? 'kecamatan' : 'kabkota';
            $needGeo[$c['slug']] = [$rec['kecamatan'], $rec['kabkota'], $R->prov[$R->reg[$reg]['province_code']]];
        } elseif ($rec['provinsi'] !== '') {
            $akurasi = 'provinsi';
            $needGeo[$c['slug']] = ['', '', $R->prov[substr($reg, 1)]];
        }
        $rec['akurasi'] = $akurasi !== '' ? $akurasi : 'tidak ada';
        return $rec;
    }
}

/** Tulis data hasil crawl sebagai file JS yang dibaca dashboard (atomik, dengan cadangan versi sebelumnya). */
function write_dataset(array $rows, array $meta): string
{
    $dir = crawler_dir('data');
    $file = "$dir/lsp-data.js";
    $js = "/* Data LSP dari bnsp.go.id (daftar & detail LSP + koordinat peta LSP BNSP). Dibuat otomatis oleh crawler; jangan diedit manual. */\n"
        . 'window.LSP_META=' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
        . "window.LSP_DATA=[\n"
        . implode(",\n", array_map(function ($r) { return json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }, $rows))
        . "\n];\n";
    $tmp = "$file.tmp";
    file_put_contents($tmp, $js);
    if (is_file($file)) {
        copy($file, "$dir/lsp-data.prev.js");
    }
    rename($tmp, $file);
    return $file;
}
