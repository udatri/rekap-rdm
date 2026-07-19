<?php

declare(strict_types=1);

/**
 * Membaca rekap nilai dari satu file atau folder berisi banyak .xlsx.
 */
final class ExcelReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const META_COLS = ['A', 'B', 'C', 'D', 'E'];

    private const NON_SUBJECT = [
        'no', 'nis', 'nisn', 'nama', 'jk', 'jumlah', 'rank', 's', 'i', 'a',
        'pai', 'sb', 'mulok', 'kmpm', 'kmps',
    ];

    public function __construct(private readonly string $sourcePath)
    {
        if (!file_exists($this->sourcePath)) {
            throw new RuntimeException('Sumber data tidak ditemukan: ' . $this->sourcePath);
        }
    }

    public function import(): array
    {
        $files = $this->resolveFiles();
        if ($files === []) {
            throw new RuntimeException('Tidak ada file .xlsx di sumber data.');
        }

        $records = [];
        $semesterMap = [];
        $madrasah = 'MAN 4 SLEMAN';
        $sources = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $parsed = $this->importFile($file);
            } catch (Throwable $e) {
                $errors[] = basename($file) . ': ' . $e->getMessage();
                continue;
            }

            if ($parsed === null) {
                continue;
            }

            $sources[] = basename($file);
            if (($parsed['madrasah'] ?? '') !== '') {
                $madrasah = $parsed['madrasah'];
            }

            foreach ($parsed['semesters'] as $meta) {
                $key = $meta['semester_ke'] . '|' . $meta['tahun_ajaran'] . '|' . $meta['kelas'] . '|' . $meta['semester'];
                if (!isset($semesterMap[$key])) {
                    $semesterMap[$key] = $meta;
                } else {
                    $semesterMap[$key]['jumlah_siswa'] = max(
                        (int) $semesterMap[$key]['jumlah_siswa'],
                        (int) $meta['jumlah_siswa']
                    );
                    foreach ($meta['subjects'] as $subj) {
                        if (!in_array($subj, $semesterMap[$key]['subjects'], true)) {
                            $semesterMap[$key]['subjects'][] = $subj;
                        }
                    }
                }
            }

            foreach ($parsed['records'] as $row) {
                $records[] = $row;
            }
        }

        if ($records === []) {
            $msg = 'Tidak ada data nilai yang berhasil dibaca.';
            if ($errors !== []) {
                $msg .= ' ' . implode('; ', array_slice($errors, 0, 3));
            }
            throw new RuntimeException($msg);
        }

        $semesters = array_values($semesterMap);
        usort($semesters, static function ($a, $b) {
            return [$a['semester_ke'], $a['kelas']] <=> [$b['semester_ke'], $b['kelas']];
        });

        return [
            'madrasah' => $madrasah,
            'imported_at' => date('c'),
            'source' => is_dir($this->sourcePath)
                ? 'semua/ (' . count($sources) . ' file)'
                : basename($this->sourcePath),
            'source_files' => $sources,
            'source_errors' => $errors,
            'semesters' => $semesters,
            'records' => $records,
            'students' => $this->buildStudentIndex($records),
        ];
    }

    /** @return list<string> */
    private function resolveFiles(): array
    {
        if (is_file($this->sourcePath)) {
            return [$this->sourcePath];
        }

        $files = glob(rtrim($this->sourcePath, '/\\') . '/*.xlsx') ?: [];
        $files = array_values(array_filter($files, static fn ($f) => is_readable($f) && !str_starts_with(basename($f), '~$')));
        natcasesort($files);
        return array_values($files);
    }

    private function importFile(string $xlsxPath): ?array
    {
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new RuntimeException('Gagal membuka file.');
        }

        try {
            $shared = $this->readSharedStrings($zip);
            $sheets = $this->listSheets($zip);
            $records = [];
            $semesters = [];
            $madrasah = '';

            foreach ($sheets as $sheet) {
                $parsed = $this->parseSheet(
                    $zip,
                    $sheet['path'],
                    $shared,
                    $sheet['name'],
                    basename($xlsxPath)
                );
                if ($parsed === null) {
                    continue;
                }
                if ($madrasah === '' && ($parsed['meta']['madrasah'] ?? '') !== '') {
                    $madrasah = $parsed['meta']['madrasah'];
                }
                $semesters[] = $parsed['meta'];
                foreach ($parsed['students'] as $student) {
                    $student['source_file'] = basename($xlsxPath);
                    $records[] = $student;
                }
            }

            if ($records === []) {
                return null;
            }

            return [
                'madrasah' => $madrasah,
                'semesters' => $semesters,
                'records' => $records,
            ];
        } finally {
            $zip->close();
        }
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $root = $this->loadXml($xml);
        $out = [];
        foreach ($root->si as $si) {
            $texts = [];
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texts[] = (string) $t;
            }
            $out[] = implode('', $texts);
        }
        return $out;
    }

    private function listSheets(ZipArchive $zip): array
    {
        $wb = $this->loadXml($zip->getFromName('xl/workbook.xml'));
        $rels = $this->loadXml($zip->getFromName('xl/_rels/workbook.xml.rels'));

        $ridMap = [];
        foreach ($rels->Relationship as $rel) {
            $ridMap[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $sheets = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes(self::REL_NS);
            $rid = (string) ($attrs['id'] ?? '');
            $target = $ridMap[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $sheets[] = [
                'name' => (string) $sheet['name'],
                'path' => $target,
            ];
        }
        return $sheets;
    }

    private function parseSheet(
        ZipArchive $zip,
        string $path,
        array $shared,
        string $sheetName,
        string $fileName
    ): ?array {
        $xml = $zip->getFromName($path);
        if ($xml === false) {
            return null;
        }

        $root = $this->loadXml($xml);
        $grid = [];
        foreach ($root->xpath('//*[local-name()="c"]') ?: [] as $cell) {
            $ref = (string) $cell['r'];
            if ($ref === '' || !preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $grid[(int) $m[2]][$m[1]] = $this->cellValue($cell, $shared);
        }

        if ($grid === []) {
            return null;
        }

        $metaInfo = $this->extractMeta($grid, $sheetName, $fileName);
        $header = $this->findHeaderRows($grid);
        if ($header === null) {
            return null;
        }

        $subjects = $this->resolveSubjects($header['row_main'], $header['row_sub']);
        if ($subjects === []) {
            return null;
        }

        $rankCol = $this->findLabeledColumn($header['row_main'], 'rank')
            ?? $this->findLabeledColumn($header['row_sub'], 'rank');

        $kelas = $metaInfo['kelas'];
        $semesterLabel = $metaInfo['semester'];
        $tahunAjaran = $metaInfo['tahun_ajaran'];
        $madrasah = $metaInfo['madrasah'];
        $semesterKe = $metaInfo['semester_ke'];

        $meta = [
            'sheet' => $sheetName,
            'semester_ke' => $semesterKe,
            'semester' => $semesterLabel,
            'tahun_ajaran' => $tahunAjaran,
            'kelas' => $kelas,
            'madrasah' => $madrasah,
            'subjects' => array_values($subjects),
            'source_file' => $fileName,
        ];

        $students = [];
        $startRow = $header['data_start'];
        $maxRow = max(array_keys($grid));

        for ($r = $startRow; $r <= $maxRow; $r++) {
            $row = $grid[$r] ?? [];
            $nis = $this->normalizeId($row['B'] ?? null);
            $nisnRaw = $this->normalizeId($row['C'] ?? null);
            $nisn = $this->canonicalNisn($nisnRaw);
            if ($nisn === '' && $nisnRaw !== '') {
                $nisn = $nisnRaw;
            }
            $nama = trim((string) ($row['D'] ?? ''));

            if ($nis === '' && $nisn === '' && $nama === '') {
                // Lewati baris kosong di tengah; berhenti setelah beberapa kosong beruntun
                if ($this->isTrailingEmpty($grid, $r, $maxRow)) {
                    break;
                }
                continue;
            }

            // Stop pada baris ringkasan (MAX/MIN/dll)
            $first = strtoupper(trim((string) ($row['A'] ?? $row['E'] ?? '')));
            if (in_array($first, ['MAX', 'MIN', 'RATA', 'RATA-RATA', 'AVERAGE'], true)) {
                break;
            }

            if ($nama === '') {
                continue;
            }
            if (!$this->looksLikeStudentId($nis) && !$this->looksLikeStudentId($nisn) && !$this->looksLikeStudentId($nisnRaw)) {
                continue;
            }

            $scores = [];
            $sum = 0.0;
            $count = 0;
            foreach ($subjects as $col => $subject) {
                $val = $this->toNumber($row[$col] ?? null);
                $scores[$subject] = $val;
                // Nilai kosong atau 0 tidak masuk perhitungan jumlah/rataan
                if ($val !== null && $val > 0) {
                    $sum += $val;
                    $count++;
                }
            }

            // Hitung ulang dari nilai valid (abaikan kolom Jumlah Excel jika berisi 0)
            $jumlah = $count > 0 ? round($sum, 2) : null;
            $rata = $count > 0 ? round($sum / $count, 1) : null;
            $rank = $rankCol ? $this->toNumber($row[$rankCol] ?? null) : null;

            $studentId = $nisn !== '' ? $nisn : ($this->canonicalNisn($nis) ?: $nis);

            $students[] = [
                'id' => $studentId,
                'nis' => $nis,
                'nisn' => $nisn !== '' ? $nisn : $nisnRaw,
                'nama' => $nama,
                'jk' => trim((string) ($row['E'] ?? '')),
                'semester_ke' => $semesterKe,
                'semester' => $semesterLabel,
                'tahun_ajaran' => $tahunAjaran,
                'kelas' => $kelas,
                'sheet' => $sheetName,
                'scores' => $scores,
                'jumlah' => $jumlah,
                'rata_rata' => $rata,
                'rank' => $rank,
                'mapel_count' => $count,
            ];
        }

        $meta['jumlah_siswa'] = count($students);
        if ($students === []) {
            return null;
        }

        return ['meta' => $meta, 'students' => $students];
    }

    /**
     * @param array<int, array<string, mixed>> $grid
     * @return array{kelas:string,semester:string,tahun_ajaran:string,madrasah:string,semester_ke:int}
     */
    private function extractMeta(array $grid, string $sheetName, string $fileName): array
    {
        $kelas = '';
        $semester = '';
        $tahunAjaran = '';
        $madrasah = '';

        $maxScan = min(10, max(array_keys($grid) ?: [1]));
        for ($r = 1; $r <= $maxScan; $r++) {
            $row = $grid[$r] ?? [];
            foreach ($row as $col => $val) {
                $label = strtolower(trim((string) $val));
                $nextCol = $this->colLetter($this->colIndex($col) + 1);
                $nextVal = trim((string) ($row[$nextCol] ?? ''));

                if ($kelas === '' && (str_contains($label, 'kelas') || $label === 'kelas:')) {
                    $kelas = $nextVal !== '' ? $nextVal : $kelas;
                }
                if ($semester === '' && (str_contains($label, 'semester') || $label === 'semester:')) {
                    $semester = $nextVal !== '' ? $nextVal : $semester;
                }
                if ($tahunAjaran === '' && (str_contains($label, 'tahun ajaran') || str_contains($label, 'tahunajaran'))) {
                    $tahunAjaran = $nextVal !== '' ? $nextVal : $tahunAjaran;
                }
                if ($madrasah === '' && (str_contains($label, 'madrasah') || str_contains($label, 'sekolah'))) {
                    $madrasah = $nextVal !== '' ? $nextVal : $madrasah;
                }
            }
        }

        if ($kelas === '') {
            if (preg_match('/KELAS\s+([A-Z0-9.]+)/i', $fileName, $m)) {
                $kelas = strtoupper($m[1]);
            } elseif (preg_match('/^(X{1,3}\.[A-Z0-9]+)$/i', trim($sheetName), $m)) {
                $kelas = strtoupper($m[1]);
            }
        }

        if ($semester === '') {
            // File bertanda (1) biasanya Genap
            $semester = str_contains($fileName, '(1)') ? 'Genap' : 'Ganjil';
        }

        $semesterKe = $this->resolveSemesterKe($kelas, $semester, $tahunAjaran, $sheetName);

        return [
            'kelas' => $kelas,
            'semester' => $semester,
            'tahun_ajaran' => $tahunAjaran,
            'madrasah' => $madrasah,
            'semester_ke' => $semesterKe,
        ];
    }

    private function resolveSemesterKe(string $kelas, string $semester, string $tahunAjaran, string $sheetName): int
    {
        if (preg_match('/SEM\s*(\d+)/i', $sheetName, $m)) {
            return (int) $m[1];
        }

        $tingkat = 0;
        if (preg_match('/^(XII|XI|X)\b/i', $kelas, $m)) {
            $tingkat = match (strtoupper($m[1])) {
                'X' => 1,
                'XI' => 3,
                'XII' => 5,
                default => 0,
            };
        }

        $offset = str_contains(strtolower($semester), 'genap') ? 1 : 0;
        if ($tingkat > 0) {
            return $tingkat + $offset;
        }

        // Fallback dari tahun ajaran urutan
        if (preg_match('/^(\d{4})/', $tahunAjaran, $m)) {
            $year = (int) $m[1];
            // Asumsi angkatan mulai 2023 = X
            $base = max(1, (($year - 2023) * 2) + 1);
            return $base + $offset;
        }

        return $offset + 1;
    }

    /**
     * @param array<int, array<string, mixed>> $grid
     * @return array{row_main:array<string,mixed>,row_sub:array<string,mixed>,data_start:int}|null
     */
    private function findHeaderRows(array $grid): ?array
    {
        $maxScan = min(20, max(array_keys($grid) ?: [1]));
        for ($r = 1; $r <= $maxScan; $r++) {
            $row = $grid[$r] ?? [];
            $a = strtolower(trim((string) ($row['A'] ?? '')));
            $b = strtolower(trim((string) ($row['B'] ?? '')));
            $d = strtolower(trim((string) ($row['D'] ?? '')));
            if ($a === 'no' && ($b === 'nis' || $d === 'nama')) {
                return [
                    'row_main' => $row,
                    'row_sub' => $grid[$r + 1] ?? [],
                    'data_start' => $r + 2,
                ];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    private function findLabeledColumn(array $row, string $needle): ?string
    {
        $needle = strtolower($needle);
        foreach ($row as $col => $val) {
            if (strtolower(trim((string) $val)) === $needle) {
                return $col;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $rowMain
     * @param array<string, mixed> $rowSub
     * @return array<string, string>
     */
    private function resolveSubjects(array $rowMain, array $rowSub): array
    {
        $maxIdx = 0;
        foreach (array_merge(array_keys($rowMain), array_keys($rowSub)) as $col) {
            $maxIdx = max($maxIdx, $this->colIndex($col));
        }

        $subjects = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $col = $this->colLetter($i);
            if (in_array($col, self::META_COLS, true)) {
                continue;
            }

            $labelSub = trim((string) ($rowSub[$col] ?? ''));
            $labelMain = trim((string) ($rowMain[$col] ?? ''));
            $label = $labelSub !== '' ? $labelSub : $labelMain;
            if ($label === '') {
                continue;
            }

            $key = strtolower($label);
            if (in_array($key, self::NON_SUBJECT, true)) {
                continue;
            }

            $name = $label;
            $n = 2;
            while (in_array($name, $subjects, true)) {
                $name = $label . ' (' . $n . ')';
                $n++;
            }
            $subjects[$col] = $name;
        }

        return $subjects;
    }

    private function buildStudentIndex(array $records): array
    {
        $byId = [];
        foreach ($records as $rec) {
            $raw = (string) (($rec['nisn'] ?? '') !== '' ? $rec['nisn'] : ($rec['id'] ?? ''));
            $key = $this->studentKey($raw);
            if ($key === '') {
                $key = 'row:' . count($byId);
            }
            $canon = $this->canonicalNisn($raw) ?: (string) ($rec['id'] ?? $raw);

            if (!isset($byId[$key])) {
                $byId[$key] = [
                    'id' => $canon,
                    'nisn' => $canon,
                    'nama' => $rec['nama'],
                    'jk' => $rec['jk'],
                    'nis_list' => [],
                    'kelas_list' => [],
                ];
            }
            $st = &$byId[$key];
            if (strlen($canon) >= strlen((string) $st['nisn'])) {
                $st['id'] = $canon;
                $st['nisn'] = $canon;
            }
            if ($rec['nis'] !== '' && !in_array($rec['nis'], $st['nis_list'], true)) {
                $st['nis_list'][] = $rec['nis'];
            }
            if ($rec['kelas'] !== '' && !in_array($rec['kelas'], $st['kelas_list'], true)) {
                $st['kelas_list'][] = $rec['kelas'];
            }
            $st['nama'] = $rec['nama'] !== '' ? $rec['nama'] : $st['nama'];
            $st['jk'] = $rec['jk'] ?: $st['jk'];
            unset($st);
        }

        $students = array_values($byId);
        usort($students, static fn ($a, $b) => strcasecmp($a['nama'], $b['nama']));
        return $students;
    }

    /** @param array<int, array<string, mixed>> $grid */
    private function isTrailingEmpty(array $grid, int $from, int $maxRow): bool
    {
        $empty = 0;
        for ($r = $from; $r <= min($from + 4, $maxRow); $r++) {
            $row = $grid[$r] ?? [];
            $nis = trim((string) ($row['B'] ?? ''));
            $nisn = trim((string) ($row['C'] ?? ''));
            $nama = trim((string) ($row['D'] ?? ''));
            if ($nis === '' && $nisn === '' && $nama === '') {
                $empty++;
            }
        }
        return $empty >= 3;
    }

    private function studentKey(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
        if ($digits === '') {
            return trim($raw);
        }
        return ltrim($digits, '0') ?: '0';
    }

    private function canonicalNisn(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
        if ($digits === '') {
            return '';
        }
        $stripped = ltrim($digits, '0');
        if ($stripped === '') {
            return '0000000000';
        }
        return str_pad($stripped, 10, '0', STR_PAD_LEFT);
    }

    private function normalizeId(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $val = trim((string) $raw);
        // Hindari notasi ilmiah dari Excel
        if (is_numeric($raw) && !str_contains($val, 'E') && !str_contains($val, 'e')) {
            if (str_contains($val, '.')) {
                $val = rtrim(rtrim($val, '0'), '.');
            }
        }
        return $val;
    }

    private function looksLikeStudentId(string $id): bool
    {
        if ($id === '') {
            return false;
        }
        return (bool) preg_match('/^\d{5,20}$/', $id);
    }

    private function cellValue(SimpleXMLElement $cell, array $shared): mixed
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 'inlineStr') {
            $texts = [];
            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texts[] = (string) $t;
            }
            return implode('', $texts);
        }

        $v = $cell->v ?? null;
        if ($v === null) {
            return null;
        }
        $text = (string) $v;
        if ($type === 's') {
            return $shared[(int) $text] ?? null;
        }
        if ($type === 'b') {
            return $text === '1';
        }
        return $text;
    }

    private function toNumber(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        return null;
    }

    private function colIndex(string $col): int
    {
        $n = 0;
        foreach (str_split($col) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    private function colLetter(int $index): string
    {
        $index++;
        $s = '';
        while ($index > 0) {
            $index--;
            $s = chr(65 + ($index % 26)) . $s;
            $index = intdiv($index, 26);
        }
        return $s;
    }

    private function loadXml(string $xml): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $el = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($el === false) {
            throw new RuntimeException('XML Excel tidak valid.');
        }
        return $el;
    }
}
