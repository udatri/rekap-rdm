<?php

declare(strict_types=1);

require_once __DIR__ . '/KonversiStore.php';

/**
 * Mesin konversi nilai (skala linear per kelompok) + template Excel.
 * Pola mengikuti Mesin-Konversi: nilai asli → nilai rapor + predikat.
 */
final class KonversiService
{
    public function __construct(private readonly KonversiStore $store)
    {
    }

    /**
     * @param list<array{nis?:string,nisn?:string,nama?:string,nilai_asli?:float|int|string|null,kelompok?:string}> $siswa
     * @param array{kelas?:string,tahun_ajaran?:string,mapel?:string,kkm?:float,targets?:array} $meta
     * @return array{meta:array,stats:array,siswa:list<array>}
     */
    public function convert(array $siswa, array $meta = []): array
    {
        $settings = $this->store->getSettings();
        $kkm = isset($meta['kkm']) ? (float) $meta['kkm'] : (float) $settings['kkm'];
        $targets = $settings['targets'];
        if (isset($meta['targets']) && is_array($meta['targets'])) {
            foreach (['rendah', 'sedang', 'tinggi', 'semua'] as $k) {
                if (isset($meta['targets'][$k]) && is_array($meta['targets'][$k])) {
                    $targets[$k] = [
                        'min' => (float) $meta['targets'][$k]['min'],
                        'max' => (float) $meta['targets'][$k]['max'],
                    ];
                }
            }
        }

        $rows = [];
        foreach ($siswa as $s) {
            if (!is_array($s)) {
                continue;
            }
            $nama = trim((string) ($s['nama'] ?? ''));
            $asliRaw = $s['nilai_asli'] ?? null;
            if (($nama === '' && ($s['nisn'] ?? '') === '') && ($asliRaw === null || $asliRaw === '')) {
                continue;
            }
            $asli = $this->toFloatOrNull($asliRaw);
            $kelompok = $this->normalizeKelompok((string) ($s['kelompok'] ?? ''));
            $rows[] = [
                'nis' => trim((string) ($s['nis'] ?? '')),
                'nisn' => trim((string) ($s['nisn'] ?? $s['id'] ?? '')),
                'nama' => $nama !== '' ? $nama : '—',
                'nilai_asli' => $asli,
                'kelompok' => $kelompok,
            ];
        }

        if ($rows === []) {
            throw new InvalidArgumentException('Tidak ada baris nilai untuk dikonversi.');
        }

        $useKelompok = false;
        foreach ($rows as $r) {
            if ($r['kelompok'] !== 'semua' && $r['nilai_asli'] !== null) {
                $useKelompok = true;
                break;
            }
        }

        // Hitung min/max asli per kelompok
        $bounds = [];
        foreach ($rows as $r) {
            if ($r['nilai_asli'] === null) {
                continue;
            }
            $g = $useKelompok ? $r['kelompok'] : 'semua';
            if ($g === 'semua' && $useKelompok) {
                $g = 'sedang'; // fallback jika kosong saat mode kelompok
            }
            if (!isset($bounds[$g])) {
                $bounds[$g] = ['min' => $r['nilai_asli'], 'max' => $r['nilai_asli']];
            } else {
                $bounds[$g]['min'] = min($bounds[$g]['min'], $r['nilai_asli']);
                $bounds[$g]['max'] = max($bounds[$g]['max'], $r['nilai_asli']);
            }
        }

        $out = [];
        foreach ($rows as $i => $r) {
            $g = $useKelompok
                ? (($r['kelompok'] === 'semua') ? 'sedang' : $r['kelompok'])
                : 'semua';
            $target = $targets[$g] ?? $targets['semua'];
            $nilai = null;
            if ($r['nilai_asli'] !== null && isset($bounds[$g])) {
                $nilai = $this->scaleLinear(
                    $r['nilai_asli'],
                    (float) $bounds[$g]['min'],
                    (float) $bounds[$g]['max'],
                    (float) $target['min'],
                    (float) $target['max']
                );
            }
            $pred = $nilai !== null ? $this->store->convertPredikat($nilai) : null;
            $out[] = [
                'no' => $i + 1,
                'nis' => $r['nis'],
                'nisn' => $r['nisn'],
                'nama' => $r['nama'],
                'nilai_asli' => $r['nilai_asli'],
                'kelompok' => $useKelompok ? strtoupper($g) : '',
                'nilai_rapor' => $nilai,
                'huruf' => $pred['huruf'] ?? '',
                'predikat' => $pred['predikat'] ?? '',
                'tuntas' => $nilai !== null ? ($nilai >= $kkm) : null,
                'ketuntasan' => $nilai === null ? '' : ($nilai >= $kkm ? 'Tuntas' : 'Tidak Tuntas'),
            ];
        }

        // ranking by nilai_rapor desc
        $ranked = $out;
        usort($ranked, static function ($a, $b) {
            $na = $a['nilai_rapor'];
            $nb = $b['nilai_rapor'];
            if ($na === null && $nb === null) {
                return 0;
            }
            if ($na === null) {
                return 1;
            }
            if ($nb === null) {
                return -1;
            }
            return $nb <=> $na;
        });
        $rankMap = [];
        $rank = 0;
        $prev = null;
        foreach ($ranked as $idx => $r) {
            if ($r['nilai_rapor'] === null) {
                $rankMap[$r['no']] = null;
                continue;
            }
            if ($prev === null || abs($r['nilai_rapor'] - $prev) > 0.001) {
                $rank = $idx + 1;
                $prev = $r['nilai_rapor'];
            }
            $rankMap[$r['no']] = $rank;
        }
        foreach ($out as &$r) {
            $r['ranking'] = $rankMap[$r['no']] ?? null;
        }
        unset($r);

        $nilaiList = array_values(array_filter(
            array_column($out, 'nilai_rapor'),
            static fn ($v) => $v !== null
        ));
        $asliList = array_values(array_filter(
            array_column($out, 'nilai_asli'),
            static fn ($v) => $v !== null
        ));

        return [
            'meta' => [
                'kelas' => trim((string) ($meta['kelas'] ?? '')),
                'tahun_ajaran' => trim((string) ($meta['tahun_ajaran'] ?? '')),
                'mapel' => trim((string) ($meta['mapel'] ?? '')),
                'kkm' => $kkm,
                'mode_kelompok' => $useKelompok,
                'targets' => $targets,
                'bounds_asli' => $bounds,
            ],
            'stats' => [
                'siswa' => count($out),
                'terisi' => count($nilaiList),
                'tuntas' => count(array_filter($out, static fn ($r) => $r['tuntas'] === true)),
                'tidak_tuntas' => count(array_filter($out, static fn ($r) => $r['tuntas'] === false)),
                'rata_asli' => $asliList !== [] ? round(array_sum($asliList) / count($asliList), 2) : null,
                'rata_rapor' => $nilaiList !== [] ? round(array_sum($nilaiList) / count($nilaiList), 2) : null,
                'min_rapor' => $nilaiList !== [] ? min($nilaiList) : null,
                'max_rapor' => $nilaiList !== [] ? max($nilaiList) : null,
            ],
            'siswa' => $out,
        ];
    }

    /**
     * @param list<array{nis?:string,nisn?:string,nama?:string}> $siswa
     * @param array{kelas?:string,tahun_ajaran?:string,mapel?:string,kkm?:float|int} $meta
     */
    public function buildTemplateXls(array $siswa, array $meta = []): string
    {
        $settings = $this->store->getSettings();
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $kelas = trim((string) ($meta['kelas'] ?? ''));
        $tahun = trim((string) ($meta['tahun_ajaran'] ?? ''));
        $mapel = trim((string) ($meta['mapel'] ?? ''));
        $kkm = isset($meta['kkm']) ? (float) $meta['kkm'] : (float) $settings['kkm'];

        $xml = $this->xlsHeader();
        $xml .= '<Worksheet ss:Name="INPUT"><Table>';
        $xml .= $this->colWidths([40, 80, 100, 180, 80, 90]);
        $xml .= $this->row(['MESIN KONVERSI NILAI — TEMPLATE INPUT'], 'header', 6);
        $xml .= $this->row(['Isi kolom NILAI_ASLI. Opsional: KELOMPOK = RENDAH / SEDANG / TINGGI. Kosongkan kelompok untuk konversi tanpa pengelompokan.'], 'muted', 6);
        $xml .= $this->row(['KELAS', $kelas, 'TAHUN', $tahun, 'KKM', (string) $kkm]);
        $xml .= $this->row(['MAPEL', $mapel, '', '', '', '']);
        $xml .= $this->row([]);
        $xml .= $this->row(['NO', 'NIS', 'NISN', 'NAMA', 'NILAI_ASLI', 'KELOMPOK'], 'th');

        if ($siswa === []) {
            for ($i = 1; $i <= 20; $i++) {
                $xml .= $this->row([(string) $i, '', '', '', '', '']);
            }
        } else {
            foreach ($siswa as $i => $s) {
                $xml .= $this->row([
                    (string) ($i + 1),
                    (string) ($s['nis'] ?? ''),
                    (string) ($s['nisn'] ?? $s['id'] ?? ''),
                    (string) ($s['nama'] ?? ''),
                    '',
                    '',
                ]);
            }
        }
        $xml .= '</Table></Worksheet>';

        $xml .= '<Worksheet ss:Name="PETUNJUK"><Table>';
        $xml .= $this->colWidths([520]);
        $lines = [
            'Petunjuk Mesin Konversi Nilai',
            '1. Unduh template ini, isi NILAI_ASLI (skor mentah / nilai harian / gabungan).',
            '2. Opsional isi KELOMPOK: RENDAH, SEDANG, atau TINGGI (mirip Mesin-Konversi).',
            '3. Jika kelompok diisi, konversi memakai skala linear per kelompok ke rentang target di aplikasi.',
            '4. Jika kelompok dikosongkan, semua nilai dikonversi bersama ke rentang target SEMUA.',
            '5. Unggah file ke menu Konversi nilai → hasil: NILAI RAPOR, predikat, ketuntasan, ranking.',
            '6. Jangan ubah nama kolom header baris NO/NIS/NISN/NAMA/NILAI_ASLI/KELOMPOK.',
            '7. Atur KKM & rentang target (rendah/sedang/tinggi) di halaman Konversi nilai sebelum mengunggah.',
        ];
        foreach ($lines as $i => $line) {
            $xml .= $this->row([$line], $i === 0 ? 'header' : 'muted');
        }
        $xml .= '</Table></Worksheet>';
        $xml .= '</Workbook>';
        return $xml;
    }

    /**
     * @param array{meta:array,stats:array,siswa:list<array>} $result
     */
    public function buildHasilXls(array $result): string
    {
        $meta = $result['meta'] ?? [];
        $stats = $result['stats'] ?? [];
        $siswa = $result['siswa'] ?? [];
        $xml = $this->xlsHeader();
        $xml .= '<Worksheet ss:Name="HASIL"><Table>';
        $xml .= $this->colWidths([40, 80, 100, 160, 70, 80, 70, 50, 90, 90, 50]);
        $xml .= $this->row(['HASIL MESIN KONVERSI NILAI'], 'header', 11);
        $xml .= $this->row([
            'Kelas', (string) ($meta['kelas'] ?? ''),
            'Tahun', (string) ($meta['tahun_ajaran'] ?? ''),
            'Mapel', (string) ($meta['mapel'] ?? ''),
            'KKM', (string) ($meta['kkm'] ?? ''),
            '', '', '',
        ]);
        $xml .= $this->row([
            'Rata asli', (string) ($stats['rata_asli'] ?? ''),
            'Rata rapor', (string) ($stats['rata_rapor'] ?? ''),
            'Min', (string) ($stats['min_rapor'] ?? ''),
            'Max', (string) ($stats['max_rapor'] ?? ''),
            'Tuntas', (string) ($stats['tuntas'] ?? ''),
            '', '', '',
        ]);
        $xml .= $this->row([]);
        $xml .= $this->row([
            'NO', 'NIS', 'NISN', 'NAMA', 'NILAI_ASLI', 'KELOMPOK',
            'NILAI_RAPOR', 'HURUF', 'PREDIKAT', 'KETUNTASAN', 'RANKING',
        ], 'th');
        foreach ($siswa as $r) {
            $xml .= $this->row([
                (string) ($r['no'] ?? ''),
                (string) ($r['nis'] ?? ''),
                (string) ($r['nisn'] ?? ''),
                (string) ($r['nama'] ?? ''),
                $r['nilai_asli'] === null ? '' : (string) $r['nilai_asli'],
                (string) ($r['kelompok'] ?? ''),
                $r['nilai_rapor'] === null ? '' : (string) $r['nilai_rapor'],
                (string) ($r['huruf'] ?? ''),
                (string) ($r['predikat'] ?? ''),
                (string) ($r['ketuntasan'] ?? ''),
                $r['ranking'] === null ? '' : (string) $r['ranking'],
            ]);
        }
        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    /**
     * @return array{meta:array,siswa:list<array>}
     */
    public function parseUpload(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xlsm'], true)) {
            $grid = $this->readXlsxGrid($path);
        } elseif (in_array($ext, ['xls', 'xml'], true)) {
            $grid = $this->readSpreadsheetMlGrid($path);
        } else {
            throw new InvalidArgumentException('Format file harus .xlsx atau .xls');
        }
        return $this->parseGrid($grid);
    }

    public function scaleLinear(float $x, float $inMin, float $inMax, float $outMin, float $outMax): float
    {
        if (abs($inMax - $inMin) < 0.00001) {
            return round(($outMin + $outMax) / 2, 2);
        }
        $t = ($x - $inMin) / ($inMax - $inMin);
        $t = max(0.0, min(1.0, $t));
        return round($outMin + $t * ($outMax - $outMin), 2);
    }

    private function normalizeKelompok(string $raw): string
    {
        $v = strtolower(trim($raw));
        $v = str_replace([' ', '_', '-'], '', $v);
        return match ($v) {
            'rendah', 'low', 'bawah' => 'rendah',
            'sedang', 'tengah', 'mid', 'medium' => 'sedang',
            'tinggi', 'atas', 'high', 'tinggiatas' => 'tinggi',
            default => 'semua',
        };
    }

    private function toFloatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        if (!is_numeric($v)) {
            return null;
        }
        return round((float) $v, 2);
    }

    /** @param list<list<string>> $grid */
    private function parseGrid(array $grid): array
    {
        $meta = ['kelas' => '', 'tahun_ajaran' => '', 'mapel' => '', 'kkm' => null];
        $headerRow = -1;
        $cols = [];

        foreach ($grid as $ri => $row) {
            $cells = array_map(static fn ($c) => trim((string) $c), $row);
            $joined = strtoupper(implode('|', $cells));
            // meta
            for ($i = 0; $i < count($cells) - 1; $i++) {
                $key = strtoupper(preg_replace('/\s+/', '', $cells[$i]) ?? '');
                $val = $cells[$i + 1] ?? '';
                if ($key === 'KELAS' || $key === 'KELAS:') {
                    $meta['kelas'] = $val;
                }
                if (in_array($key, ['TAHUN', 'TAHUNAJARAN', 'TAHUNPELAJARAN', 'TAHUN:'], true)) {
                    $meta['tahun_ajaran'] = $val;
                }
                if (in_array($key, ['MAPEL', 'MATAPELAJARAN', 'MAPEL:'], true)) {
                    $meta['mapel'] = $val;
                }
                if ($key === 'KKM' || $key === 'KKM:') {
                    $meta['kkm'] = $this->toFloatOrNull($val);
                }
            }
            // header
            $map = [];
            foreach ($cells as $ci => $c) {
                $h = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $c) ?? '');
                if ($h === 'NO' || $h === 'NOMOR') {
                    $map['no'] = $ci;
                }
                if ($h === 'NIS') {
                    $map['nis'] = $ci;
                }
                if ($h === 'NISN' || $h === 'ID') {
                    $map['nisn'] = $ci;
                }
                if ($h === 'NAMA' || $h === 'NAMASISWA') {
                    $map['nama'] = $ci;
                }
                if (in_array($h, ['NILAIASLI', 'ASLI', 'NILAI_ASLI', 'SKOR', 'NILAI'], true)) {
                    $map['nilai_asli'] = $ci;
                }
                if (in_array($h, ['KELOMPOK', 'GROUP', 'KATEGORI'], true)) {
                    $map['kelompok'] = $ci;
                }
            }
            if (isset($map['nama'], $map['nilai_asli']) || (isset($map['nisn']) && isset($map['nilai_asli']))) {
                $headerRow = $ri;
                $cols = $map;
                break;
            }
        }

        if ($headerRow < 0) {
            throw new InvalidArgumentException(
                'Header template tidak ditemukan. Pastikan ada kolom NAMA dan NILAI_ASLI (atau ASLI).'
            );
        }

        $siswa = [];
        for ($ri = $headerRow + 1; $ri < count($grid); $ri++) {
            $row = $grid[$ri];
            $get = static function (string $k) use ($cols, $row): string {
                if (!isset($cols[$k])) {
                    return '';
                }
                return trim((string) ($row[$cols[$k]] ?? ''));
            };
            $nama = $get('nama');
            $nisn = $get('nisn');
            $asli = $get('nilai_asli');
            if ($nama === '' && $nisn === '' && $asli === '') {
                continue;
            }
            $siswa[] = [
                'nis' => $get('nis'),
                'nisn' => $nisn,
                'nama' => $nama,
                'nilai_asli' => $asli,
                'kelompok' => $get('kelompok'),
            ];
        }

        if ($siswa === []) {
            throw new InvalidArgumentException('Tidak ada baris siswa pada file template.');
        }

        return ['meta' => $meta, 'siswa' => $siswa];
    }

    /** @return list<list<string>> */
    private function readXlsxGrid(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Gagal membuka file Excel.');
        }
        try {
            $shared = [];
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            if ($ss !== false) {
                $sx = @simplexml_load_string($ss);
                if ($sx) {
                    foreach ($sx->si as $si) {
                        $texts = [];
                        foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                            $texts[] = (string) $t;
                        }
                        $shared[] = implode('', $texts);
                    }
                }
            }
            $sheetPath = 'xl/worksheets/sheet1.xml';
            $wb = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($wb !== false && $rels !== false) {
                $wx = @simplexml_load_string($wb);
                $rx = @simplexml_load_string($rels);
                if ($wx && $rx) {
                    $ridMap = [];
                    foreach ($rx->Relationship as $rel) {
                        $ridMap[(string) $rel['Id']] = (string) $rel['Target'];
                    }
                    foreach ($wx->sheets->sheet ?? [] as $sheet) {
                        $name = strtoupper((string) $sheet['name']);
                        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                        $rid = (string) ($attrs['id'] ?? '');
                        $target = $ridMap[$rid] ?? '';
                        if ($target === '') {
                            continue;
                        }
                        if (!str_starts_with($target, '/')) {
                            $target = 'xl/' . ltrim($target, '/');
                        } else {
                            $target = ltrim($target, '/');
                        }
                        if (in_array($name, ['INPUT', 'HASIL', 'SHEET1'], true) || $sheetPath === 'xl/worksheets/sheet1.xml') {
                            $sheetPath = $target;
                            if (in_array($name, ['INPUT', 'HASIL'], true)) {
                                break;
                            }
                        }
                    }
                }
            }
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new InvalidArgumentException('Worksheet tidak ditemukan.');
            }
            $sx = @simplexml_load_string($sheetXml);
            if (!$sx) {
                throw new InvalidArgumentException('Worksheet tidak valid.');
            }
            $grid = [];
            foreach ($sx->sheetData->row ?? [] as $row) {
                $cells = [];
                $maxCol = 0;
                foreach ($row->c as $c) {
                    $ref = (string) $c['r'];
                    $col = $this->colIndex($ref);
                    $maxCol = max($maxCol, $col);
                    $t = (string) ($c['t'] ?? '');
                    $val = '';
                    if ($t === 's') {
                        $idx = (int) ($c->v ?? -1);
                        $val = $shared[$idx] ?? '';
                    } elseif ($t === 'inlineStr') {
                        $val = (string) ($c->is->t ?? '');
                    } else {
                        $val = (string) ($c->v ?? '');
                    }
                    $cells[$col] = $val;
                }
                $line = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $grid[] = $line;
            }
            return $grid;
        } finally {
            $zip->close();
        }
    }

    /** @return list<list<string>> */
    private function readSpreadsheetMlGrid(string $path): array
    {
        $xml = (string) file_get_contents($path);
        if ($xml === '') {
            throw new InvalidArgumentException('File kosong.');
        }
        // strip BOM
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
        $doc = @simplexml_load_string($xml);
        if (!$doc) {
            throw new InvalidArgumentException('File SpreadsheetML tidak valid.');
        }
        $doc->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
        $sheets = $doc->xpath('//ss:Worksheet') ?: [];
        $chosen = $sheets[0] ?? null;
        foreach ($sheets as $sh) {
            $attrs = $sh->attributes('urn:schemas-microsoft-com:office:spreadsheet');
            $name = strtoupper((string) ($attrs['Name'] ?? ''));
            if (in_array($name, ['INPUT', 'HASIL'], true)) {
                $chosen = $sh;
                break;
            }
        }
        if ($chosen === null) {
            throw new InvalidArgumentException('Worksheet tidak ditemukan.');
        }
        $grid = [];
        foreach ($chosen->Table->Row ?? [] as $row) {
            $line = [];
            foreach ($row->Cell ?? [] as $cell) {
                $attrs = $cell->attributes('urn:schemas-microsoft-com:office:spreadsheet');
                $index = isset($attrs['Index']) ? ((int) $attrs['Index'] - 1) : count($line);
                while (count($line) < $index) {
                    $line[] = '';
                }
                $line[] = trim((string) ($cell->Data ?? ''));
            }
            $grid[] = $line;
        }
        return $grid;
    }

    private function colIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
            return 0;
        }
        $letters = $m[1];
        $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $n - 1);
    }

    private function xlsHeader(): string
    {
        return '<?xml version="1.0"?>'
            . '<?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">'
            . '<Styles>'
            . '<Style ss:ID="Default"><Font ss:FontName="Calibri" ss:Size="11"/></Style>'
            . '<Style ss:ID="header"><Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1"/><Interior ss:Color="#E7F3EC" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="th"><Font ss:Bold="1"/><Interior ss:Color="#D3E4DB" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="muted"><Font ss:Color="#666666" ss:Size="10"/></Style>'
            . '</Styles>';
    }

    /** @param list<int> $widths */
    private function colWidths(array $widths): string
    {
        $xml = '';
        foreach ($widths as $w) {
            $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . $w . '"/>';
        }
        return $xml;
    }

    /** @param list<string> $cells */
    private function row(array $cells, string $style = '', int $merge = 1): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $xml = '<Row>';
        foreach ($cells as $i => $c) {
            $type = is_numeric($c) && $c !== '' ? 'Number' : 'String';
            $attr = $style !== '' ? ' ss:StyleID="' . $style . '"' : '';
            if ($i === 0 && $merge > 1) {
                $attr .= ' ss:MergeAcross="' . ($merge - 1) . '"';
            }
            $xml .= '<Cell' . $attr . '><Data ss:Type="' . $type . '">' . $esc((string) $c) . '</Data></Cell>';
            if ($i === 0 && $merge > 1) {
                break;
            }
        }
        $xml .= '</Row>';
        return $xml;
    }
}
