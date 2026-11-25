<?php
/**
 * Pelaksana crawl (CLI). Dipanggil cron hosting setiap 5 menit:
 *   /opt/alt/php83/usr/bin/php /home/semestat/bnspmap.semestateknologiutama.com/tools/cron.php
 * - Menjalankan jadwal yang sudah jatuh tempo (dimasukkan ke antrean).
 * - Mengerjakan antrean satu per satu. Hanya satu proses yang berjalan (flock).
 * Untuk uji manual: php tools/cron.php --now nasional | --now "provinsi:Jawa Timur" | --now "kabkota:Jawa Timur:Kabupaten Tuban"
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
set_time_limit(0);
ini_set('memory_limit', '512M');
require __DIR__ . '/../lib/crawler.php';

touch(crawler_dir() . '/heartbeat');   // ditampilkan di halaman Pembaruan Data: "penjadwal terakhir aktif"
$lockFh = fopen(crawler_dir() . '/run.lock', 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    exit(0);   // masih ada crawl yang berjalan
}

// status "running" tersisa dari proses yang mati di tengah jalan
$st = crawl_status();
if (($st['state'] ?? '') === 'running') {
    append_history([
        'id' => $st['job']['id'] ?? new_id(), 'scope' => $st['job']['scope'] ?? null, 'label' => $st['label'] ?? '',
        'trigger' => $st['job']['trigger'] ?? '', 'by' => $st['job']['by'] ?? '', 'startedAt' => $st['startedAt'] ?? null,
        'finishedAt' => time(), 'result' => 'gagal', 'message' => 'Proses terhenti sebelum selesai (server memutus proses).',
    ]);
    set_status(['state' => 'idle']);
}

// pekerjaan manual dari baris perintah
$args = $argv;
if (($args[1] ?? '') === '--now' && isset($args[2])) {
    $p = explode(':', $args[2]);
    $scope = normalize_scope(['type' => $p[0], 'provinsi' => $p[1] ?? '', 'kabkota' => $p[2] ?? '']);
    if (!$scope) {
        fwrite(STDERR, "Cakupan tidak dikenal.\n");
        exit(1);
    }
    enqueue_job($scope, 'manual', 'cli');
}

// jadwal yang jatuh tempo -> antrean
$now = time();
update_json(crawler_dir() . '/schedules.json', [], function (array &$list) use ($now) {
    foreach ($list as &$s) {
        if (empty($s['enabled'])) continue;
        if (empty($s['nextRun'])) $s['nextRun'] = next_run($s, $now);
        if ($s['nextRun'] <= $now) {
            enqueue_job($s['scope'], 'jadwal', schedule_label($s), $s['id']);
            $s['lastQueued'] = $now;
            $s['nextRun'] = next_run($s, $now);
        }
    }
    unset($s);
});

while ($job = dequeue_job()) {
    run_job($job);
}
exit(0);

function run_job(array $job): void
{
    @unlink(cancel_flag_file());
    $label = scope_label($job['scope']);
    $logFile = crawler_dir('logs') . '/' . $job['id'] . '.log';
    $logFh = fopen($logFile, 'a');
    $log = function (string $m) use ($logFh) {
        fwrite($logFh, '[' . date('Y-m-d H:i:s') . "] $m\n");
        fflush($logFh);
        if (getenv('CRAWL_VERBOSE')) echo "$m\n";
    };
    $started = time();
    $status = ['state' => 'running', 'job' => $job, 'label' => $label, 'startedAt' => $started, 'phase' => 'Memulai…', 'done' => 0, 'total' => 0];
    set_status($status);
    $lastWrite = 0.0;
    $progress = function (string $phase, int $done, int $total) use (&$status, &$lastWrite) {
        $status['phase'] = $phase;
        $status['done'] = $done;
        $status['total'] = $total;
        if (microtime(true) - $lastWrite > 2 || $done === $total) {
            set_status($status);
            $lastWrite = microtime(true);
        }
    };
    $cancelled = function () use ($job) {
        $f = cancel_flag_file();
        return is_file($f) && trim((string) file_get_contents($f)) === $job['id'];
    };

    $log("Mulai crawl: $label (pemicu: {$job['trigger']}, oleh: {$job['by']})");
    $entry = ['id' => $job['id'], 'scope' => $job['scope'], 'label' => $label, 'trigger' => $job['trigger'], 'by' => $job['by'],
              'startedAt' => $started];
    try {
        $source = live_data_file();
        [$oldMeta, $baseRows] = load_dataset($source);
        $log('Data dasar: ' . count($baseRows) . " LSP dari $source");
        $crawler = new Crawler($log, $progress, $cancelled);
        $res = $crawler->run($job['scope'], $baseRows);
        $progress('Menyimpan data', 1, 1);
        $meta = [
            'sumber' => 'https://bnsp.go.id/lsp', 'diambil' => date('Y-m-d'), 'jumlah' => count($res['rows']),
            'crawlTerakhir' => ['cakupan' => $label, 'waktu' => date('c')],
        ];
        $file = write_dataset($res['rows'], $meta);
        $s = $res['stats'];
        $log(sprintf('Selesai: %d LSP total, %d baru, %d diperbarui, %d tetap, %d dihapus, %d detail gagal, %d geocoding. Disimpan ke %s',
            $s['total'], $s['baru'], $s['diperbarui'], $s['tetap'], $s['dihapus'], $s['detailGagal'], $s['geocode'], $file));
        $entry += ['finishedAt' => time(), 'result' => 'berhasil', 'stats' => $s, 'message' => ''];
    } catch (CrawlCancelled $e) {
        $log('Dibatalkan. Data tidak diubah.');
        $entry += ['finishedAt' => time(), 'result' => 'dibatalkan', 'message' => 'Dibatalkan; data tidak diubah.'];
    } catch (\Throwable $e) {
        $log('GAGAL: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        $entry += ['finishedAt' => time(), 'result' => 'gagal', 'message' => $e->getMessage()];
    }
    fclose($logFh);
    @unlink(cancel_flag_file());
    append_history($entry);
    set_status(['state' => 'idle']);
}
