<?php

declare(strict_types=1);

/**
 * Skrip sekali pakai: pasang update aplikasi dari GitHub (ZIP) di hosting tanpa git.
 *
 * Cara:
 * 1. Upload file ini ke folder aplikasi (sama level index.php)
 * 2. Login sebagai superadmin di browser
 * 3. Buka: https://domain-anda/update_bootstrap_once.php
 * 4. Setelah sukses, HAPUS file ini dari server
 *
 * Tidak menimpa: config.php, data/, semua/, uploads/
 */
session_start();

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Security.php';

Auth::startSession();
$user = Auth::user();
if ($user === null || ($user['role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Akses ditolak. Login sebagai superadmin dulu, lalu buka ulang URL ini.\n";
    exit;
}

$repo = 'udatri/rekap-rdm';
$branch = 'main';
$root = __DIR__;

header('Content-Type: text/plain; charset=utf-8');

if (!class_exists('ZipArchive')) {
    echo "Gagal: ekstensi ZipArchive belum aktif di PHP hosting.\n";
    exit(1);
}

$zipUrl = 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . $branch;
$tmp = sys_get_temp_dir() . '/rekap-boot-' . bin2hex(random_bytes(3));
@mkdir($tmp, 0775, true);
$zipFile = $tmp . '/app.zip';

echo "Mengunduh {$zipUrl} …\n";

$ch = curl_init($zipUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_USERAGENT => 'rekap-rdm-bootstrap',
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $code >= 400 || strlen((string) $body) < 100) {
    // fallback tanpa curl
    $body = @file_get_contents('https://github.com/' . $repo . '/archive/refs/heads/' . $branch . '.zip');
    if ($body === false || strlen($body) < 100) {
        echo "Gagal unduh ZIP. curl_err={$err} http={$code}\n";
        exit(1);
    }
}

file_put_contents($zipFile, $body);
echo 'ZIP: ' . round(strlen($body) / 1048576, 2) . " MB\n";

$extract = $tmp . '/x';
@mkdir($extract, 0775, true);
$zip = new ZipArchive();
if ($zip->open($zipFile) !== true) {
    echo "Gagal membuka ZIP.\n";
    exit(1);
}
$zip->extractTo($extract);
$zip->close();

$src = null;
foreach (scandir($extract) ?: [] as $e) {
    if ($e === '.' || $e === '..') {
        continue;
    }
    $p = $extract . '/' . $e;
    if (is_dir($p) && is_file($p . '/index.php')) {
        $src = $p;
        break;
    }
}
if ($src === null) {
    echo "Struktur ZIP tidak dikenali.\n";
    exit(1);
}

$skipTop = ['data', 'semua', 'uploads', '.git'];
$copied = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
    $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));
    $top = explode('/', $rel, 2)[0];
    if (in_array($top, $skipTop, true) || $rel === 'config.php') {
        continue;
    }
    if (preg_match('#^reset_.*_once\.php$#', $rel) || preg_match('#^update_bootstrap_once\.php$#', $rel)) {
        continue;
    }
    $dest = $root . '/' . $rel;
    if ($item->isDir()) {
        if (!is_dir($dest)) {
            @mkdir($dest, 0775, true);
        }
        continue;
    }
    if (!is_dir(dirname($dest))) {
        @mkdir(dirname($dest), 0775, true);
    }
    if (!@copy($item->getPathname(), $dest)) {
        echo "Gagal salin: {$rel}\n";
        exit(1);
    }
    $copied++;
}

// bersihkan temp
$rm = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($rm as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($tmp);

echo "Selesai. File disalin: {$copied}\n";
echo "Hard-refresh browser, buka Pengaturan sekolah → Update Aplikasi.\n";
echo "PENTING: hapus update_bootstrap_once.php dari server sekarang.\n";
