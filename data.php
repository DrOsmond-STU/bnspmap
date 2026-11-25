<?php
/* Sajikan data LSP hanya untuk pengguna yang sudah masuk. File aslinya diblokir dari akses langsung (.htaccess). */
declare(strict_types=1);
require __DIR__ . '/lib/crawl-state.php';

require_login('asset');
session_write_close();

$file = live_data_file();   // hasil crawl terakhir, atau data bawaan repo
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
readfile($file);
