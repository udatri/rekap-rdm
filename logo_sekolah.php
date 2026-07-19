<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/SekolahStore.php';

/**
 * Sajikan file logo sekolah dari data/logos/.
 */
$f = basename((string) ($_GET['f'] ?? ''));
if ($f === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $f)) {
    http_response_code(404);
    exit;
}

$store = new SekolahStore();
$path = $store->logoPath($f);
if ($path === null) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
