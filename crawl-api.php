<?php
/* API JSON untuk halaman Pembaruan Data (crawl.php). Hanya untuk admin yang sudah masuk. */
declare(strict_types=1);
require __DIR__ . '/lib/crawl-state.php';

header('Cache-Control: no-store');
$user = require_login('asset');
if (!is_admin($user)) {
    http_response_code(403);
    exit;
}

function out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'state';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        out(['error' => 'Sesi formulir kedaluwarsa. Muat ulang halaman.'], 400);
    }
    session_write_close();
    $who = $user['name'] . ' (' . $user['username'] . ')';
    switch ($action) {
        case 'enqueue':
            $scope = normalize_scope($_POST);
            if (!$scope) out(['error' => 'Pilih cakupan crawl dengan lengkap.'], 422);
            $job = enqueue_job($scope, 'manual', $who);
            out(['ok' => true, 'job' => $job]);
        case 'cancel':
            $id = (string) ($_POST['id'] ?? '');
            if (remove_queued($id)) out(['ok' => true, 'message' => 'Dikeluarkan dari antrean.']);
            $st = crawl_status();
            if (($st['state'] ?? '') === 'running' && ($st['job']['id'] ?? '') === $id) {
                file_put_contents(cancel_flag_file(), $id);
                out(['ok' => true, 'message' => 'Permintaan batal dikirim; crawl berhenti dalam beberapa detik.']);
            }
            out(['error' => 'Pekerjaan tidak ditemukan.'], 404);
        case 'schedule_save':
            $s = normalize_schedule($_POST);
            if (!$s) out(['error' => 'Lengkapi cakupan, frekuensi, dan jam jadwal.'], 422);
            $s += ['id' => new_id(), 'enabled' => true, 'createdBy' => $who, 'createdAt' => time()];
            $s['nextRun'] = next_run($s, time());
            update_json(crawler_dir() . '/schedules.json', [], function (array &$l) use ($s) { $l[] = $s; });
            out(['ok' => true]);
        case 'schedule_toggle':
        case 'schedule_delete':
            $id = (string) ($_POST['id'] ?? '');
            $found = update_json(crawler_dir() . '/schedules.json', [], function (array &$l) use ($id, $action) {
                foreach ($l as $i => &$s) {
                    if ($s['id'] !== $id) continue;
                    if ($action === 'schedule_delete') {
                        array_splice($l, $i, 1);
                    } else {
                        $s['enabled'] = empty($s['enabled']);
                        if ($s['enabled']) $s['nextRun'] = next_run($s, time());
                    }
                    return true;
                }
                return false;
            });
            out($found ? ['ok' => true] : ['error' => 'Jadwal tidak ditemukan.'], $found ? 200 : 404);
    }
    out(['error' => 'Aksi tidak dikenal.'], 400);
}

session_write_close();

if ($action === 'log') {
    $id = preg_replace('/[^0-9A-Za-z-]/', '', (string) ($_GET['id'] ?? ''));
    $f = crawler_dir('logs') . "/$id.log";
    header('Content-Type: text/plain; charset=utf-8');
    if ($id === '' || !is_file($f)) {
        http_response_code(404);
        exit('Log tidak ditemukan.');
    }
    readfile($f);
    exit;
}

$sum = region_summary();
$schedules = read_json(crawler_dir() . '/schedules.json', []);
foreach ($schedules as &$s) {
    $s['label'] = schedule_label($s);
    $s['scopeLabel'] = scope_label($s['scope']);
}
unset($s);
$heartbeat = @filemtime(crawler_dir() . '/heartbeat') ?: null;
out([
    'now' => time(),
    'status' => crawl_status(),
    'queue' => read_json(crawler_dir() . '/queue.json', []),
    'schedules' => $schedules,
    'history' => array_slice(read_json(crawler_dir() . '/history.json', []), 0, 20),
    'data' => ['meta' => $sum['meta'], 'total' => $sum['total'], 'updated' => $sum['mtime'], 'crawled' => strpos($sum['file'], '/crawler/') !== false],
    'regions' => $sum['regions'],
    'runnerSeen' => $heartbeat,
]);
