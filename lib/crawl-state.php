<?php
/**
 * Status, antrean, jadwal, dan riwayat crawl. Semua disimpan sebagai JSON di luar folder web:
 *   <config_dir>/crawler/{queue,schedules,status,history}.json, logs/, data/, geo/
 * Dipakai bersama oleh crawl-api.php (web) dan tools/cron.php (CLI).
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

const CRAWL_TZ = 'Asia/Jakarta';
date_default_timezone_set(CRAWL_TZ);

function crawler_dir(string $sub = ''): string
{
    $d = config_dir() . '/crawler' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

/** File data yang sedang dipakai dashboard: hasil crawl terakhir, atau data bawaan repo bila belum pernah crawl. */
function live_data_file(): string
{
    $live = config_dir() . '/crawler/data/lsp-data.js';
    return is_file($live) ? $live : dirname(__DIR__) . '/js/lsp-data.js';
}

function read_json(string $file, $default)
{
    if (!is_file($file)) {
        return $default;
    }
    $fh = fopen($file, 'r');
    if (!$fh) {
        return $default;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $v = json_decode((string) $raw, true);
    return $v === null ? $default : $v;
}

function write_json(string $file, $value): void
{
    $tmp = $file . '.tmp' . getmypid();
    file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
}

/** Ubah file JSON secara atomik di bawah kunci eksklusif. $fn menerima nilai (by ref) dan boleh mengembalikan hasil. */
function update_json(string $file, $default, callable $fn)
{
    $lock = fopen($file . '.lock', 'c');
    flock($lock, LOCK_EX);
    $v = read_json($file, $default);
    $result = $fn($v);
    write_json($file, $v);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

function new_id(): string
{
    return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

/* ---------------- cakupan ---------------- */

function normalize_scope(array $in): ?array
{
    $type = $in['type'] ?? '';
    $prov = trim((string) ($in['provinsi'] ?? ''));
    $kab = trim((string) ($in['kabkota'] ?? ''));
    if ($type === 'nasional') {
        return ['type' => 'nasional'];
    }
    if ($type === 'provinsi' && $prov !== '') {
        return ['type' => 'provinsi', 'provinsi' => $prov];
    }
    if ($type === 'kabkota' && $prov !== '' && $kab !== '') {
        return ['type' => 'kabkota', 'provinsi' => $prov, 'kabkota' => $kab];
    }
    return null;
}

function scope_label(array $s): string
{
    switch ($s['type'] ?? '') {
        case 'nasional': return 'Nasional';
        case 'provinsi': return 'Provinsi ' . $s['provinsi'];
        case 'kabkota': return $s['kabkota'] . ', ' . $s['provinsi'];
    }
    return '?';
}

function scope_key(array $s): string
{
    return implode('|', [$s['type'] ?? '', $s['provinsi'] ?? '', $s['kabkota'] ?? '']);
}

/* ---------------- antrean ---------------- */

/** Masukkan pekerjaan ke antrean. Bila cakupan yang sama sudah antre, kembalikan yang ada. */
function enqueue_job(array $scope, string $trigger, string $by, ?string $scheduleId = null): array
{
    return update_json(crawler_dir() . '/queue.json', [], function (array &$q) use ($scope, $trigger, $by, $scheduleId) {
        foreach ($q as $job) {
            if (scope_key($job['scope']) === scope_key($scope)) {
                return $job + ['duplicate' => true];
            }
        }
        $job = ['id' => new_id(), 'scope' => $scope, 'trigger' => $trigger, 'by' => $by,
                'scheduleId' => $scheduleId, 'queuedAt' => time()];
        $q[] = $job;
        return $job;
    });
}

function dequeue_job(): ?array
{
    return update_json(crawler_dir() . '/queue.json', [], function (array &$q) {
        return $q ? array_shift($q) : null;
    });
}

function remove_queued(string $id): bool
{
    return update_json(crawler_dir() . '/queue.json', [], function (array &$q) use ($id) {
        $before = count($q);
        $q = array_values(array_filter($q, function ($j) use ($id) { return $j['id'] !== $id; }));
        return count($q) < $before;
    });
}

/* ---------------- status & riwayat ---------------- */

function crawl_status(): array
{
    return read_json(crawler_dir() . '/status.json', ['state' => 'idle']);
}

function set_status(array $s): void
{
    $s['updatedAt'] = time();
    write_json(crawler_dir() . '/status.json', $s);
}

function append_history(array $entry): void
{
    update_json(crawler_dir() . '/history.json', [], function (array &$h) use ($entry) {
        array_unshift($h, $entry);
        $h = array_slice($h, 0, 60);
    });
    // simpan 60 log terakhir saja
    $logs = glob(crawler_dir('logs') . '/*.log') ?: [];
    rsort($logs);
    foreach (array_slice($logs, 60) as $old) {
        @unlink($old);
    }
}

function cancel_flag_file(): string
{
    return crawler_dir() . '/cancel.flag';
}

/* ---------------- jadwal ---------------- */

const HARI = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

/** Waktu jalan berikutnya (timestamp) setelah $after. */
function next_run(array $sch, int $after): int
{
    [$hh, $mm] = array_map('intval', explode(':', $sch['time'] ?? '02:00'));
    $tz = new DateTimeZone(CRAWL_TZ);
    $base = (new DateTimeImmutable('@' . $after))->setTimezone($tz);
    for ($i = 0; $i <= 62; $i++) {
        $d = $base->modify("+$i day")->setTime($hh, $mm);
        if ($d->getTimestamp() <= $after) continue;
        if ($sch['freq'] === 'daily') return $d->getTimestamp();
        if ($sch['freq'] === 'weekly' && (int) $d->format('N') === (int) $sch['weekday']) return $d->getTimestamp();
        if ($sch['freq'] === 'monthly' && (int) $d->format('j') === (int) $sch['monthday']) return $d->getTimestamp();
    }
    return $after + 86400;
}

function schedule_label(array $s): string
{
    $t = $s['time'];
    if ($s['freq'] === 'daily') return "Setiap hari, $t WIB";
    if ($s['freq'] === 'weekly') return 'Setiap ' . HARI[(int) $s['weekday']] . ", $t WIB";
    return 'Setiap tanggal ' . (int) $s['monthday'] . ", $t WIB";
}

function normalize_schedule(array $in): ?array
{
    $scope = normalize_scope($in);
    $freq = $in['freq'] ?? '';
    $time = (string) ($in['time'] ?? '');
    if (!$scope || !in_array($freq, ['daily', 'weekly', 'monthly'], true) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        return null;
    }
    $s = ['scope' => $scope, 'freq' => $freq, 'time' => $time];
    if ($freq === 'weekly') {
        $w = (int) ($in['weekday'] ?? 0);
        if ($w < 1 || $w > 7) return null;
        $s['weekday'] = $w;
    }
    if ($freq === 'monthly') {
        $m = (int) ($in['monthday'] ?? 0);
        if ($m < 1 || $m > 28) return null;
        $s['monthday'] = $m;
    }
    return $s;
}

/* ---------------- ringkasan wilayah dari data aktif ---------------- */

function load_dataset(string $file): array
{
    $s = (string) @file_get_contents($file);
    $meta = [];
    if (preg_match('/window\.LSP_META=(\{.*?\});/s', $s, $m)) {
        $meta = json_decode($m[1], true) ?: [];
    }
    $i = strpos($s, 'window.LSP_DATA=');
    if ($i === false) {
        return [$meta, []];
    }
    $json = rtrim(substr($s, $i + strlen('window.LSP_DATA=')));
    $json = rtrim($json, ";\n ");
    return [$meta, json_decode($json, true) ?: []];
}

/** {provinsi: {kabkota: jumlah}} dari data aktif, di-cache berdasarkan waktu ubah file. */
function region_summary(): array
{
    $file = live_data_file();
    $cache = crawler_dir() . '/regions-cache.json';
    $c = read_json($cache, []);
    if (($c['file'] ?? '') === $file && ($c['mtime'] ?? 0) === filemtime($file)) {
        return $c;
    }
    [$meta, $rows] = load_dataset($file);
    $reg = [];
    foreach ($rows as $r) {
        $p = $r['provinsi'] ?: 'Belum diketahui';
        $k = $r['kabkota'] ?: 'Belum diketahui';
        $reg[$p][$k] = ($reg[$p][$k] ?? 0) + 1;
    }
    ksort($reg);
    foreach ($reg as &$ks) {
        ksort($ks);
    }
    unset($ks);
    $c = ['file' => $file, 'mtime' => filemtime($file), 'meta' => $meta, 'total' => count($rows), 'regions' => $reg];
    write_json($cache, $c);
    return $c;
}

function is_admin(?array $user): bool
{
    return $user !== null && ($user['role'] ?? 'admin') === 'admin';
}
