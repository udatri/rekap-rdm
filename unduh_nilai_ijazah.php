<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';
require_once __DIR__ . '/lib/IjazahExportService.php';

Auth::guardPage();

/**
 * Unduh export Excel nilai ijazah (template visual).
 *
 * Query: kelas, tahun_ajaran, semester, id (opsional untuk detail 1 siswa)
 */
try {
    $service = new RekapService();
    $data = $service->ensureData(false);

    $filter = [
        'kelas' => trim((string) ($_GET['kelas'] ?? '')),
        'tahun_ajaran' => trim((string) ($_GET['tahun_ajaran'] ?? '')),
        'semester' => trim((string) ($_GET['semester'] ?? '')),
        'id' => trim((string) ($_GET['id'] ?? '')),
    ];

    $q = array_filter($filter, static fn ($v) => $v !== '');
    $rekap = $service->ijazahService()->rekap($data, $q);

    $exporter = new IjazahExportService();
    $xml = $exporter->buildXls($rekap, $filter);
    $filename = $exporter->filename($rekap, $filter);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $xml;
    exit;
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gagal export nilai ijazah: ' . $e->getMessage();
}
