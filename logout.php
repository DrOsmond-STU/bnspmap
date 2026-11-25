<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf($_POST['csrf'] ?? null)) {
    logout();
    start_session();
    session_regenerate_id(true);   // sesi baru yang bersih, hanya membawa pesan
    $_SESSION['flash'] = 'Anda sudah keluar.';
}
header('Location: login.php');
exit;
