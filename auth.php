<?php
/**
 * Autentikasi sederhana berbasis sesi PHP untuk dashboard Peta LSP BNSP.
 *
 * Akun disimpan di file PHP di LUAR folder web (default: ../bnspmap-config/users.php):
 *   <?php return ['admin' => ['name' => 'Administrator', 'hash' => '$2y$10$...']];
 * Buat hash dengan: php tools/hash-password.php
 * Lokasi bisa diubah lewat environment variable BNSPMAP_CONFIG_DIR.
 */

declare(strict_types=1);

const SESSION_IDLE_SECONDS = 8 * 3600;   // keluar otomatis setelah 8 jam tidak aktif
const MAX_FAILED_ATTEMPTS = 5;           // percobaan gagal sebelum dikunci
const LOCK_SECONDS = 15 * 60;            // lama kunci

function config_dir(): string
{
    $env = getenv('BNSPMAP_CONFIG_DIR');
    return $env ? rtrim($env, '/') : dirname(__DIR__) . '/bnspmap-config';
}

function load_users(): array
{
    $file = config_dir() . '/users.php';
    if (!is_file($file)) {
        return [];
    }
    $users = include $file;
    return is_array($users) ? $users : [];
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('BNSPMAPSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['user'], $_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Sesi berakhir karena lama tidak aktif. Silakan masuk lagi.';
    }
    if (isset($_SESSION['user'])) {
        $_SESSION['last_seen'] = time();
    }
}

function current_user(): ?array
{
    start_session();
    return $_SESSION['user'] ?? null;
}

/** Hentikan halaman bila belum masuk. $mode 'page' -> alihkan ke login, 'asset' -> 401. */
function require_login(string $mode = 'page'): array
{
    $user = current_user();
    if ($user) {
        return $user;
    }
    header('Cache-Control: no-store');
    if ($mode === 'asset') {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Silakan masuk terlebih dahulu.');
    }
    header('Location: login.php');
    exit;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): bool
{
    start_session();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- pembatasan percobaan masuk (per alamat IP) ---------- */

function attempts_file(): string
{
    return config_dir() . '/login-attempts.json';
}

function with_attempts(callable $fn)
{
    $file = attempts_file();
    $fh = @fopen($file, 'c+');
    if (!$fh) {                  // tidak bisa menulis: jangan blokir pengguna
        $empty = [];
        return $fn($empty, false)[0];
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw ?: '{}', true) ?: [];
    $now = time();
    foreach ($data as $k => $v) {           // buang catatan lama
        if (($v['last'] ?? 0) < $now - LOCK_SECONDS) {
            unset($data[$k]);
        }
    }
    [$result, $changed] = $fn($data, true);
    if ($changed) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function client_key(): string
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Detik tersisa sampai boleh mencoba lagi (0 = tidak terkunci). */
function lock_remaining(): int
{
    return with_attempts(function (array &$data) {
        $r = $data[client_key()] ?? null;
        if ($r && $r['count'] >= MAX_FAILED_ATTEMPTS) {
            return [max(0, $r['last'] + LOCK_SECONDS - time()), false];
        }
        return [0, false];
    });
}

function record_failure(): void
{
    with_attempts(function (array &$data) {
        $k = client_key();
        $data[$k] = ['count' => ($data[$k]['count'] ?? 0) + 1, 'last' => time()];
        return [null, true];
    });
}

function clear_failures(): void
{
    with_attempts(function (array &$data) {
        unset($data[client_key()]);
        return [null, true];
    });
}

/** Coba masuk. Kembalikan pesan galat, atau null bila berhasil. */
function attempt_login(string $username, string $password): ?string
{
    $wait = lock_remaining();
    if ($wait > 0) {
        return 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . (int) ceil($wait / 60) . ' menit.';
    }
    $users = load_users();
    if (!$users) {
        return 'Belum ada akun yang dikonfigurasi di server. Hubungi administrator.';
    }
    $key = strtolower(trim($username));
    $user = $users[$key] ?? null;
    // Tetap jalankan password_verify agar waktu respons tidak membocorkan nama pengguna yang valid
    $hash = $user['hash'] ?? '$2y$10$usesomesillystringforsaltDUMMYHASHxxxxxxxxxxxxxxxxxxxxxx';
    if (!$user || !password_verify($password, $hash)) {
        record_failure();
        usleep(400000);
        return 'Nama pengguna atau kata sandi salah.';
    }
    clear_failures();
    start_session();
    session_regenerate_id(true);
    $_SESSION['user'] = ['username' => $key, 'name' => $user['name'] ?? $key];
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return null;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => $p['httponly'], 'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
