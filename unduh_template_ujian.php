<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';

Auth::guardPage();
if (!Auth::can('ujian')) {
    http_response_code(403);
    echo 'Tidak berwenang.';
    exit;
}

/**
 * Unduh template Excel ujian (1 file per kelas, mapel terpilih).
 *
 * Query: jenis, kelas, tahun_ajaran, semester, tanggal, keterangan, mapel[] / mapel=QH,AA
 */
try {
    $service = new RekapService();
    $data = $service->ensureData(false);

    $meta = [
        'jenis' => strtolower(trim((string) ($_GET['jenis'] ?? 'praktek'))),
        'kelas' => trim((string) ($_GET['kelas'] ?? '')),
        'tahun_ajaran' => trim((string) ($_GET['tahun_ajaran'] ?? '')),
        'semester' => trim((string) ($_GET['semester'] ?? '')),
        'tanggal' => trim((string) ($_GET['tanggal'] ?? date('Y-m-d'))),
        'penguji' => '',
        'keterangan' => trim((string) ($_GET['keterangan'] ?? '')),
    ];

    if (!isset(UjianStore::TEMPLATES[$meta['jenis']])) {
        throw new InvalidArgumentException('Jenis ujian tidak valid.');
    }
    if ($meta['kelas'] === '') {
        throw new InvalidArgumentException('Pilih kelas atau tingkat terlebih dahulu.');
    }

    $rawMapel = $_GET['mapel'] ?? [];
    if (is_string($rawMapel)) {
        $rawMapel = preg_split('/[,\s]+/', $rawMapel, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($rawMapel)) {
        $rawMapel = [];
    }
    $selected = [];
    foreach ($rawMapel as $kode) {
        $kode = trim((string) $kode);
        if ($kode === '') {
            continue;
        }
        if (isset(UjianStore::MAPEL[$kode])) {
            $selected[] = $kode;
            continue;
        }
        foreach (UjianStore::MAPEL as $k => $nama) {
            if (strcasecmp($k, $kode) === 0) {
                $selected[] = $k;
                break;
            }
        }
    }
    $selected = array_values(array_unique($selected));
    if ($selected === []) {
        throw new InvalidArgumentException('Pilih minimal satu mata pelajaran.');
    }

    $siswa = $service->siswaByKelas($data, $meta);
    if ($siswa === []) {
        $siswa = $service->siswaByKelas($data, ['kelas' => $meta['kelas']]);
    }
    if ($siswa === []) {
        $label = RekapService::kelasFilterLabel($meta['kelas']);
        throw new InvalidArgumentException('Tidak ada siswa untuk ' . $label . '.');
    }

    $importer = $service->ujianImportService();
    // Urutkan sesuai daftar rapor kelas, tetap hanya yang dipilih
    $available = $importer->mapelForKelas($data, $meta['kelas']);
    $order = array_flip($available !== [] ? $available : array_keys(UjianStore::MAPEL));
    usort($selected, static function ($a, $b) use ($order) {
        return ($order[$a] ?? 1000) <=> ($order[$b] ?? 1000);
    });

    $xml = $importer->buildSpreadsheetXml($meta, $siswa, $selected, $data);
    $filename = $importer->suggestedFilename($meta + ['mapel_count' => count($selected)]);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $xml;
    exit;
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gagal unduh template: ' . $e->getMessage();
}
