<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';
require_once __DIR__ . '/lib/SemuaSemesterExportService.php';

Auth::guardPage();
if (!Auth::can('export')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Tidak berwenang mengunduh export.';
    exit;
}

/**
 * Unduh export Excel rekap semua semester (judul berwarna, kolom menyesuaikan).
 *
 * Query: tahun_ajaran, semester, kelas, id (opsional)
 */
try {
    $service = new RekapService();
    $data = $service->ensureData(false);

    $filter = [
        'tahun_ajaran' => trim((string) ($_GET['tahun_ajaran'] ?? '')),
        'semester' => trim((string) ($_GET['semester'] ?? '')),
        'kelas' => trim((string) ($_GET['kelas'] ?? '')),
        'id' => trim((string) ($_GET['id'] ?? '')),
    ];

    $q = array_filter($filter, static fn ($v) => $v !== '');
    $rekap = $service->rekapSemuaSemester($data, $q);

    $exporter = new SemuaSemesterExportService();
    $xml = $exporter->buildXls($rekap, $filter);
    $filename = $exporter->filename($filter);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $xml;
    exit;
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gagal export rekap semua semester: ' . $e->getMessage();
}
