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
 * Unduh hasil konversi dari POST JSON body:
 * { result: { meta, stats, siswa } }
 * atau query session via POST action from api (we accept raw JSON in POST).
 */
try {
    $raw = file_get_contents('php://input');
    $input = [];
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = $json;
        }
    }
    if ($input === [] && !empty($_POST['result'])) {
        $decoded = json_decode((string) $_POST['result'], true);
        if (is_array($decoded)) {
            $input['result'] = $decoded;
        }
    }

    $result = $input['result'] ?? null;
    if (!is_array($result) || empty($result['siswa'])) {
        throw new InvalidArgumentException('Data hasil konversi tidak valid.');
    }

    $service = new RekapService();
    $xml = $service->konversiService()->buildHasilXls($result);
    $kelas = (string) ($result['meta']['kelas'] ?? 'hasil');
    $slug = preg_replace('/[^\w.\-]+/u', '-', $kelas) ?: 'hasil';
    $filename = 'hasil-konversi-nilai_' . $slug . '_' . date('Ymd-His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $xml;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
