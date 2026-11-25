<?php
/**
 * Penentu wilayah (provinsi / kab-kota / kecamatan) untuk alamat & hasil reverse geocoding.
 * Referensi: data wilayah Kemendagri dari paket idn-area-data (lib/data/*.csv).
 * Logika ini port dari tools/enrich.py; ubah keduanya bersamaan.
 */
declare(strict_types=1);

final class Regions
{
    const STREET = ['jl', 'jln', 'jalan', 'gg', 'gang', 'komplek', 'kompleks', 'perum', 'perumahan', 'blok', 'ruko'];
    const GENERIC = ['raya', 'kota', 'baru', 'tengah', 'utara', 'selatan', 'timur', 'barat', 'pusat', 'jaya', 'indah', 'kali', 'gunung',
        'pasar', 'sungai', 'batu', 'bukit', 'tanjung', 'lima', 'dua', 'tiga', 'empat', 'kampung', 'pantai', 'danau', 'laut',
        'teluk', 'pulau', 'air', 'bumi', 'mulia', 'sari', 'makmur', 'permai', 'harapan', 'merdeka', 'pahlawan', 'sentosa'];
    const EXTRA_ALIAS = [
        '31' => ['dki jakarta', 'dki', 'jakarta'], '34' => ['diy', 'yogyakarta', 'jogja', 'jogjakarta', 'di yogyakarta'],
        '32' => ['jabar'], '33' => ['jateng'], '35' => ['jatim'], '52' => ['ntb'], '53' => ['ntt'], '12' => ['sumut'], '13' => ['sumbar'],
        '16' => ['sumsel'], '64' => ['kaltim'], '63' => ['kalsel'], '61' => ['kalbar'], '62' => ['kalteng'], '65' => ['kaltara'],
        '73' => ['sulsel'], '71' => ['sulut'], '72' => ['sulteng'], '74' => ['sultra'], '76' => ['sulbar'], '19' => ['babel', 'bangka belitung'],
        '21' => ['kepri'],
    ];

    /** @var array<string,string> */ public $prov = [];
    /** @var array<string,array> */  public $reg = [];
    /** @var array<string,array> */  public $dist = [];
    public $distByReg = [], $regCore = [], $regIsKota = [], $distCore = [], $distCount = [], $provAlias = [];
    public $vilByReg = [], $jktVillages = [];

    public function __construct(string $dir)
    {
        foreach ($this->csv("$dir/provinces.csv") as $r) {
            $this->prov[$r['code']] = $r['name'];
        }
        foreach ($this->csv("$dir/regencies.csv") as $r) {
            $this->reg[$r['code']] = $r;
            $this->regCore[$r['code']] = preg_replace('/^(kabupaten administrasi|kota administrasi|kabupaten|kota) /', '', trim(self::norm($r['name'])));
            $this->regIsKota[$r['code']] = strpos($r['name'], 'Kota') === 0;
        }
        foreach ($this->csv("$dir/districts.csv") as $r) {
            $this->dist[$r['code']] = $r;
            $this->distByReg[$r['regency_code']][] = $r['code'];
            $core = trim(self::norm($r['name']));
            $this->distCore[$r['code']] = $core;
            $this->distCount[$core] = ($this->distCount[$core] ?? 0) + 1;
        }
        foreach ($this->prov as $c => $n) {
            $this->provAlias[$c] = [trim(self::norm($n))];
        }
        foreach (self::EXTRA_ALIAS as $c => $al) {
            $this->provAlias[$c] = array_values(array_unique(array_merge($this->provAlias[$c] ?? [], $al)));
        }
        foreach ($this->csv("$dir/villages.csv") as $r) {
            $n = trim(self::norm($r['name']));
            $this->vilByReg[substr($r['district_code'], 0, 5)][$n][] = $r['district_code'];
            if (strpos($r['code'], '31.') === 0 && strlen($n) >= 5) {
                $this->jktVillages[] = [$n, $r['district_code']];
            }
        }
    }

    private function csv(string $file): \Generator
    {
        $fh = fopen($file, 'r');
        if (!$fh) {
            throw new \RuntimeException("Tidak bisa membaca $file");
        }
        $head = fgetcsv($fh, 0, ',', '"', '');
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($row) === count($head)) {
                yield array_combine($head, $row);
            }
        }
        fclose($fh);
    }

    public static function norm(?string $s): string
    {
        $s = strtolower(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $s = preg_replace('/\bkab\b\.?/', ' kabupaten ', $s);
        $s = preg_replace('/\bkec\b\.?/', ' kecamatan ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return ' ' . trim(preg_replace('/\s+/', ' ', $s)) . ' ';
    }

    public static function has(string $t, string $w): bool
    {
        if (strpos($t, " $w ") !== false) {
            return true;
        }
        $w2 = str_replace(' ', '', $w);
        return $w2 !== $w && strpos($t, " $w2 ") !== false;
    }

    /** Seperti has(), tetapi abaikan kemunculan sebagai nama jalan/kompleks (mis. "Jl. Raya", "Jl. Riung Mulia"). */
    public static function hasPlace(string $t, string $w): bool
    {
        foreach (array_unique([$w, str_replace(' ', '', $w)]) as $v) {
            $needle = " $v ";
            $off = 0;
            while (($pos = strpos($t, $needle, $off)) !== false) {
                $before = preg_split('/\s+/', trim(substr($t, 0, $pos)));
                $last = $before ? end($before) : '';
                if (!in_array($last, self::STREET, true)) {
                    return true;
                }
                $off = $pos + strlen($needle);
            }
        }
        return false;
    }

    public function pulauOf(string $pc): string
    {
        $n = (int) $pc;
        if ($n < 30) return 'Sumatera';
        if ($n < 50) return 'Jawa';
        if ($n < 60) return 'Bali & Nusa Tenggara';
        if ($n < 70) return 'Kalimantan';
        if ($n < 80) return 'Sulawesi';
        if ($n < 90) return 'Maluku';
        return 'Papua';
    }

    public function provDisplay(string $pc): string
    {
        return ['31' => 'DKI Jakarta', '34' => 'DI Yogyakarta'][$pc] ?? $this->prov[$pc];
    }

    public function regDisplay(string $code): string
    {
        return str_replace(' Administrasi', '', $this->reg[$code]['name']);
    }

    /**
     * Tebak kab/kota & kecamatan dari teks alamat ($main) dan teks petunjuk ($hint, bobot setengah).
     * Hasil: [kode_kabkota|null|"P<kode_prov>", kode_kecamatan|null]
     */
    public function resolveText(string $main, string $hint = ''): array
    {
        $t = self::norm($main);
        $h = self::norm($hint);
        $pmatch = [];
        foreach ($this->provAlias as $c => $al) {
            foreach ($al as $a) {
                if (self::has($t, $a) || self::has($h, $a)) {
                    $pmatch[(string) $c] = true;
                    break;
                }
            }
        }
        $best = null;
        foreach ($this->regCore as $c => $core) {
            $c = (string) $c;
            $r = 0.0;
            foreach ([[$t, 1.0], [$h, 0.5]] as [$txt, $wt]) {
                $kind = $this->regIsKota[$c] ? 'kota' : 'kabupaten';
                $other = $this->regIsKota[$c] ? 'kabupaten' : 'kota';
                $cc = str_replace(' ', '', $core);
                if (self::has($txt, "$kind $core") || self::has($txt, "$kind $cc")) {
                    $r = max($r, 3 * $wt);
                } elseif (self::hasPlace($txt, $core) && !(self::has($txt, "$other $core") || self::has($txt, "$other $cc"))) {
                    $r = max($r, (strlen($core) > 4 ? 1.5 : 0.5) * $wt);
                }
            }
            $d = 0.0;
            $dbest = null;
            foreach ($this->distByReg[$c] ?? [] as $dc) {
                $dn = $this->distCore[$dc];
                if (strlen($dn) < 4) continue;
                if (in_array($dn, self::GENERIC, true) && !self::has($t, "kecamatan $dn")) continue;
                if (self::has($t, "kecamatan $dn")) $v = 2.0;
                elseif (self::hasPlace($t, $dn)) $v = 1.0;
                elseif (self::hasPlace($h, $dn)) $v = 0.4;
                else continue;
                if ($dn === $core) $v -= 0.1;   // kecamatan bernama sama dgn kab/kota: kalah dari kecamatan lain yang disebut
                if ($v > $d) {
                    $d = $v;
                    $dbest = $dc;
                }
            }
            $p = isset($pmatch[$this->reg[$c]['province_code']]) ? 1.5 : 0.0;
            if ($r == 0 && $d == 0) continue;
            $uniqD = $dbest && $this->distCount[$this->distCore[$dbest]] === 1 && strlen($this->distCore[$dbest]) >= 6;
            $ok = $r >= 0.75 || $d >= 2 || ($d >= 1 && $p) || ($d >= 1 && $uniqD) || ($r > 0 && $p);
            if (!$ok) continue;
            $sc = $r + $d + $p + ($this->regIsKota[$c] ? 0.1 : 0);
            if ($best === null || $sc > $best[0]) {
                $best = [$sc, $c, $dbest];
            }
        }
        if ($best) {
            return [$best[1], $best[2]];
        }
        if (!$pmatch || isset($pmatch['31'])) {   // alamat Jakarta sering hanya mencantumkan kelurahan
            foreach ($this->jktVillages as [$vn, $dc]) {
                if (self::hasPlace($t, $vn)) {
                    return [$this->dist[$dc]['regency_code'], $dc];
                }
            }
        }
        if (count($pmatch) === 1) {
            return ['P' . array_key_first($pmatch), null];
        }
        return [null, null];
    }

    /** Wilayah dari hasil reverse geocoding Nominatim. */
    public function resolveReverse(?array $rv): array
    {
        if (!$rv || !isset($rv['address'])) {
            return [null, null];
        }
        $a = $rv['address'];
        $st = trim(self::norm($a['state'] ?? ''));
        $pc = null;
        foreach ($this->provAlias as $c => $al) {
            if (in_array($st, $al, true) || trim(self::norm($this->prov[$c])) === $st) {
                $pc = (string) $c;
                break;
            }
        }
        if (!$pc) {
            return [null, null];
        }
        $regs = array_values(array_filter(array_map('strval', array_keys($this->reg)), function ($c) use ($pc) {
            return $this->reg[$c]['province_code'] === $pc;
        }));
        $reg = null;
        foreach (['city', 'county', 'municipality', 'town'] as $key) {
            $v = preg_replace('/^(kabupaten|kota) /', '', trim(self::norm($a[$key] ?? '')));
            if ($v === '') continue;
            $m = array_values(array_filter($regs, function ($c) use ($v) { return $this->regCore[$c] === $v; }));
            if ($m) {
                // untuk "city" utamakan Kota, selain itu utamakan Kabupaten (urutan stabil seperti versi Python)
                usort($m, function ($x, $y) use ($key) {
                    $kx = $key === 'city' ? !$this->regIsKota[$x] : $this->regIsKota[$x];
                    $ky = $key === 'city' ? !$this->regIsKota[$y] : $this->regIsKota[$y];
                    return ((int) $kx) <=> ((int) $ky);
                });
                $reg = $m[0];
                break;
            }
        }
        if (!$reg) {
            return ['P' . $pc, null];
        }
        $dist = null;
        foreach (['city_district', 'district', 'municipality', 'town', 'suburb', 'village', 'quarter', 'hamlet'] as $key) {
            $v = preg_replace('/^kecamatan /', '', trim(self::norm($a[$key] ?? '')));
            foreach ($this->distByReg[$reg] ?? [] as $dc) {
                if ($this->distCore[$dc] === $v) {
                    $dist = $dc;
                    break 2;
                }
            }
        }
        if (!$dist) {   // Nominatim sering hanya memberi nama desa/kelurahan -> cari kecamatannya
            foreach (['village', 'suburb', 'neighbourhood', 'quarter', 'hamlet', 'city_block'] as $key) {
                $v = preg_replace('/^(desa|kelurahan) /', '', trim(self::norm($a[$key] ?? '')));
                $cands = $v !== '' ? array_values(array_unique($this->vilByReg[$reg][$v] ?? [])) : [];
                sort($cands);
                if (count($cands) === 1) {
                    $dist = $cands[0];
                    break;
                }
                if (count($cands) > 1) {
                    $hints = [];
                    foreach (['city', 'town', 'municipality', 'city_district', 'district'] as $k) {
                        $hints[] = preg_replace('/^(kabupaten|kota|kecamatan) /', '', trim(self::norm($a[$k] ?? '')));
                    }
                    $m = array_values(array_filter($cands, function ($dc) use ($hints) { return in_array($this->distCore[$dc], $hints, true); }));
                    if (count($m) === 1) {
                        $dist = $m[0];
                        break;
                    }
                }
            }
        }
        return [$reg, $dist];
    }
}
