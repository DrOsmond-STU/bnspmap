<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (current_user()) {
    header('Location: ./');
    exit;
}

$error = null;
$username = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (trim($username) === '' || $password === '') {
        $error = 'Isi nama pengguna dan kata sandi.';
    } else {
        $error = attempt_login($username, $password);
        if ($error === null) {
            header('Location: ./');
            exit;
        }
    }
}
$token = csrf_token();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Masuk · Peta LSP BNSP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="css/style.css?v=20261125">
</head>
<body class="login-body">
<main class="login-wrap">
  <section class="login-card" aria-labelledby="loginTitle">
    <div class="login-side" aria-hidden="true">
      <svg class="login-dots" viewBox="0 0 320 220" preserveAspectRatio="xMidYMid slice">
        <g fill="currentColor">
          <circle cx="40" cy="70" r="5"/><circle cx="62" cy="96" r="7"/><circle cx="88" cy="132" r="4"/><circle cx="112" cy="150" r="12"/>
          <circle cx="140" cy="158" r="9"/><circle cx="168" cy="162" r="6"/><circle cx="150" cy="80" r="5"/><circle cx="178" cy="96" r="7"/>
          <circle cx="214" cy="120" r="6"/><circle cx="226" cy="74" r="4"/><circle cx="258" cy="104" r="5"/><circle cx="286" cy="140" r="4"/>
          <circle cx="196" cy="170" r="4"/><circle cx="120" cy="104" r="3"/><circle cx="246" cy="160" r="3"/>
        </g>
      </svg>
      <div class="login-brand">
        <svg viewBox="0 0 32 32" width="40" height="40"><rect width="32" height="32" rx="8" fill="#fff"/><path d="M16 6c-4 0-7 3-7 7 0 5 7 13 7 13s7-8 7-13c0-4-3-7-7-7zm0 10a3 3 0 110-6 3 3 0 010 6z" fill="#c8191f"/></svg>
        <div>
          <strong>Peta LSP Terlisensi BNSP</strong>
          <span>Sebaran LSP P1 · P2 · P3 di Indonesia</span>
        </div>
      </div>
      <p class="login-note">Dashboard internal untuk memantau lokasi dan masa berlaku lisensi Lembaga Sertifikasi Profesi.</p>
    </div>

    <form class="login-form" method="post" action="login.php" novalidate>
      <h1 id="loginTitle">Masuk</h1>
      <p class="login-sub">Gunakan akun yang diberikan administrator.</p>

      <?php if ($flash): ?>
        <div class="login-msg info" role="status"><?= e($flash) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="login-msg error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <input type="hidden" name="csrf" value="<?= e($token) ?>">

      <label class="fsub" for="username">Nama pengguna</label>
      <input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false"
             value="<?= e($username) ?>" required <?= $username === '' ? 'autofocus' : '' ?>>

      <label class="fsub" for="password">Kata sandi</label>
      <div class="pw">
        <input id="password" name="password" type="password" autocomplete="current-password" required <?= $username !== '' ? 'autofocus' : '' ?>>
        <button type="button" class="pw-toggle" id="pwToggle" aria-label="Tampilkan kata sandi" aria-pressed="false">Tampilkan</button>
      </div>

      <button class="btn btn-primary login-submit" type="submit">Masuk</button>
      <p class="login-help">Lupa kata sandi? Hubungi administrator dashboard.</p>
    </form>
  </section>
</main>
<script>
  (function () {
    var b = document.getElementById("pwToggle"), i = document.getElementById("password");
    b.addEventListener("click", function () {
      var show = i.type === "password";
      i.type = show ? "text" : "password";
      b.textContent = show ? "Sembunyikan" : "Tampilkan";
      b.setAttribute("aria-pressed", String(show));
      b.setAttribute("aria-label", show ? "Sembunyikan kata sandi" : "Tampilkan kata sandi");
      i.focus();
    });
  })();
</script>
</body>
</html>
