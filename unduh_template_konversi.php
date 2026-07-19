<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';

Auth::guardPage();
if (!Auth::can('olah_rapor')) {
    http_response_code(403);
    echo 'Tidak berwenang.';
    exit;
}

/**
 * Unduh template Excel mesin konversi nilai.
 * Query: kelas, tahun_ajaran, mapel, kkm, blank=1
 */
try {
    $service = new RekapService();
    $kelas = trim((string) ($_GET['kelas'] ?? ''));
    $tahun = trim((string) ($_GET['tahun_ajaran'] ?? ''));
    $mapel = trim((string) ($_GET['mapel'] ?? ''));
    $kkm = $_GET['kkm'] ?? null;
    $blank = isset($_GET['blank']) && $_GET['blank'] === '1';

    $meta = [
        'kelas' => $kelas,
        'tahun_ajaran' => $tahun,
        'mapel' => $mapel,
    ];
    if ($kkm !== null && $kkm !== '') {
        $meta['kkm'] = (float) $kkm;
    }

    $siswa = [];
    if (!$blank && $kelas !== '') {
        $data = $service->ensureData(false);
        $siswa = $service->siswaByKelas($data, [
            'kelas' => $kelas,
            'tahun_ajaran' => $tahun,
        ]);
        if ($siswa === []) {
            $siswa = $service->siswaByKelas($data, ['kelas' => $kelas]);
        }
    }

    $xml = $service->konversiService()->buildTemplateXls($siswa, $meta);
    $slug = $kelas !== ''
        ? preg_replace('/[^\w.\-]+/u', '-', $kelas)
        : 'kosong';
    $filename = 'template-konversi-nilai_' . $slug . '_' . date('Ymd-His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $xml;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
