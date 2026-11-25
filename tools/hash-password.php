<?php
/* Buat hash kata sandi untuk users.php.  Pemakaian: php tools/hash-password.php  (kata sandi diketik, tidak tampil) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDOUT, "Kata sandi: ");
@system('stty -echo');
$pw = rtrim((string) fgets(STDIN), "\r\n");
@system('stty echo');
fwrite(STDOUT, "\n");
if (strlen($pw) < 10) { fwrite(STDERR, "Minimal 10 karakter.\n"); exit(1); }
echo password_hash($pw, PASSWORD_DEFAULT), "\n";
