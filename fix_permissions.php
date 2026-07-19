<?php

declare(strict_types=1);

/**
 * Jalankan sekali di server untuk membuat folder sumber/data dapat ditulis.
 *
 * CLI (disarankan): php fix_permissions.php
 * Browser: hanya dari localhost, lalu hapus file ini.
 */
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowed = in_array($remote, ['127.0.0.1', '::1'], true);
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Akses ditolak. Jalankan via CLI: php fix_permissions.php\n";
        exit(1);
    }
}

$base = __DIR__;
require_once $base . '/lib/Config.php';
Config::all();

$dirs = [
    $base . '/data',
    Config::sourceDir(),
    $base . '/semua', // legacy
];

foreach ($dirs as $dir) {
    if ($dir === '' || $dir === '/') {
        continue;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
}

header_remove();
$msg = "Permission diperbarui.\n"
    . 'source: ' . Config::sourceDir() . ' writable=' . (is_writable(Config::sourceDir()) ? 'yes' : 'no') . "\n"
    . 'data: ' . Config::dataDir() . ' writable=' . (is_writable(Config::dataDir()) ? 'yes' : 'no') . "\n"
    . "Hapus atau lindungi file ini setelah dipakai.\n";

if ($isCli) {
    echo $msg;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
}
