<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/ExcelReader.php';
require_once __DIR__ . '/KelasStore.php';
require_once __DIR__ . '/UjianStore.php';
require_once __DIR__ . '/IjazahService.php';
require_once __DIR__ . '/ImportService.php';
require_once __DIR__ . '/UjianImportService.php';
require_once __DIR__ . '/SekolahStore.php';
require_once __DIR__ . '/KonversiStore.php';
require_once __DIR__ . '/KonversiService.php';
require_once __DIR__ . '/RaporNilaiStore.php';

/**
 * Cache & layanan rekap nilai.
 */
final class RekapService
{
    private KelasStore $kelasStore;
    private UjianStore $ujianStore;
    private IjazahService $ijazahService;
    private ImportService $importService;
    private UjianImportService $ujianImportService;
    private SekolahStore $sekolahStore;
    private KonversiStore $konversiStore;
    private KonversiService $konversiService;
    private RaporNilaiStore $raporNilaiStore;

    public function __construct(
        ?string $sourcePath = null,
        ?string $cachePath = null,
    ) {
        $source = $sourcePath ?? Config::sourceDir();
        $cache = $cachePath ?? Config::cachePath();
        $this->sourcePath = $source;
        $this->cachePath = $cache;

        $dataDir = dirname($cache);
        $this->kelasStore = new KelasStore($dataDir . '/kelas.json');
        $this->ujianStore = new UjianStore($dataDir . '/ujian.json');
        $this->ijazahService = new IjazahService(
            $this->ujianStore,
            $dataDir . '/ijazah_settings.json'
        );
        $this->importService = new ImportService($source);
        $this->ujianImportService = new UjianImportService($this->ujianStore);
        $this->sekolahStore = new SekolahStore();
        $this->konversiStore = new KonversiStore($dataDir . '/konversi_nilai.json');
        $this->konversiService = new KonversiService($this->konversiStore);
        $this->raporNilaiStore = new RaporNilaiStore($dataDir . '/rapor_nilai.json');
        $this->sekolahId = Config::activeSekolahId();
    }

    private string $sourcePath;
    private string $cachePath;
    private string $sekolahId;

    public function sekolahId(): string
    {
        return $this->sekolahId;
    }

    public function kelasStore(): KelasStore
    {
        return $this->kelasStore;
    }

    public function ujianStore(): UjianStore
    {
        return $this->ujianStore;
    }

    public function ijazahService(): IjazahService
    {
        return $this->ijazahService;
    }

    public function importService(): ImportService
    {
        return $this->importService;
    }

    public function ujianImportService(): UjianImportService
    {
        return $this->ujianImportService;
    }

    public function sekolahStore(): SekolahStore
    {
        return $this->sekolahStore;
    }

    public function konversiStore(): KonversiStore
    {
        return $this->konversiStore;
    }

    public function konversiService(): KonversiService
    {
        return $this->konversiService;
    }

    public function raporNilaiStore(): RaporNilaiStore
    {
        return $this->raporNilaiStore;
    }

    /** Nama & identitas sekolah aktif (multi-sekolah). */
    public function sekolahAktif(): array
    {
        return $this->sekolahStore->activeForApi();
    }

    public function ensureData(bool $force = false): array
    {
        if (!$force && is_readable($this->cachePath)) {
            $sourceMtime = $this->sourceMtime();
            $cacheMtime = filemtime($this->cachePath) ?: 0;
            if ($cacheMtime >= $sourceMtime) {
                $json = file_get_contents($this->cachePath);
                $data = json_decode($json ?: '', true);
                if (is_array($data) && isset($data['records']) && ($data['source_type'] ?? '') === 'folder') {
                    return $data;
                }
            }
        }

        $reader = new ExcelReader($this->sourcePath);
        try {
            $data = $reader->import();
        } catch (RuntimeException $e) {
            // Folder kosong / belum ada file — tetap jalankan aplikasi
            if (str_contains($e->getMessage(), 'Tidak ada file') || str_contains($e->getMessage(), 'Tidak ada data')) {
                $data = [
                    'madrasah' => Config::get('madrasah', 'MAN 4 Sleman'),
                    'imported_at' => date('c'),
                    'source' => 'semua/ (0 file)',
                    'source_files' => [],
                    'source_errors' => [$e->getMessage()],
                    'semesters' => [],
                    'records' => [],
                    'students' => [],
                ];
            } else {
                throw $e;
            }
        }
        $data['source_type'] = is_dir($this->sourcePath) ? 'folder' : 'file';
        $data['source_path'] = $this->sourcePath;

        $dir = dirname($this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $this->cachePath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        return $data;
    }

    private function sourceMtime(): int
    {
        if (is_file($this->sourcePath)) {
            return filemtime($this->sourcePath) ?: 0;
        }
        if (!is_dir($this->sourcePath)) {
            return 0;
        }
        $mtime = filemtime($this->sourcePath) ?: 0;
        foreach (glob(rtrim($this->sourcePath, '/\\') . '/*.xlsx') ?: [] as $file) {
            $mtime = max($mtime, filemtime($file) ?: 0);
        }
        return $mtime;
    }

    public function filters(array $data): array
    {
        $tahun = [];
        $semester = [];
        $kelas = [];
        foreach ($data['semesters'] as $s) {
            if ($s['tahun_ajaran'] !== '') {
                $tahun[$s['tahun_ajaran']] = true;
            }
            if ($s['semester'] !== '') {
                $semester[$s['semester']] = true;
            }
            if ($s['kelas'] !== '') {
                $kelas[$s['kelas']] = true;
            }
        }

        $tahunList = array_keys($tahun);
        sort($tahunList);
        $kelasExcel = array_keys($kelas);
        $kelasMerged = $this->kelasStore->merged($kelasExcel);
        $kelasList = array_map(static fn ($r) => $r['nama'], $kelasMerged);

        $semesterKeMap = [];
        foreach ($data['semesters'] as $s) {
            $ke = (int) $s['semester_ke'];
            if ($ke <= 0) {
                continue;
            }
            if (!isset($semesterKeMap[$ke])) {
                $semesterKeMap[$ke] = [
                    'ke' => $ke,
                    'label' => 'SEM ' . $ke . ' · ' . $s['semester'] . ' · ' . $s['tahun_ajaran'],
                    'tahun_ajaran' => $s['tahun_ajaran'],
                    'semester' => $s['semester'],
                    'kelas' => '',
                ];
            }
        }
        ksort($semesterKeMap);

        return [
            'madrasah' => $this->sekolahStore->active()['nama']
                ?: ($data['madrasah'] ?? Config::get('madrasah', 'MAN 4 Sleman')),
            'sekolah_id' => $this->sekolahId,
            'sekolah' => $this->sekolahStore->activeForApi(),
            'sekolah_list' => $this->sekolahStore->listForApi(),
            'tahun_ajaran' => $tahunList,
            'semester' => array_values(array_keys($semester)),
            'semester_ke' => array_values($semesterKeMap),
            'kelas' => $kelasList,
            'kelas_detail' => $kelasMerged,
            'students' => $this->buildNormalizedStudentIndex($data['records'] ?? []),
            'students_ujian_teori' => $this->ijazahService->studentsWithUjianTeori($data),
            'imported_at' => $data['imported_at'] ?? null,
            'source' => $data['source'] ?? null,
            'source_files' => count($data['source_files'] ?? []),
        ];
    }

    public function listKelas(array $data): array
    {
        $fromExcel = [];
        foreach ($data['semesters'] as $s) {
            if (($s['kelas'] ?? '') !== '') {
                $fromExcel[$s['kelas']] = true;
            }
        }
        return [
            'kelas' => $this->kelasStore->merged(array_keys($fromExcel)),
        ];
    }

    /**
     * Ambil daftar siswa unik untuk kelas (opsional filter tahun/semester).
     *
     * @return list<array{nis:string,nisn:string,nama:string,jk:string}>
     */
    public function siswaByKelas(array $data, array $q): array
    {
        $kelas = trim((string) ($q['kelas'] ?? ''));
        if ($kelas === '') {
            throw new InvalidArgumentException('Pilih kelas atau tingkat terlebih dahulu.');
        }

        $rows = $this->filterRecords($data['records'], [
            'kelas' => $kelas,
            'tahun_ajaran' => $q['tahun_ajaran'] ?? '',
            'semester' => $q['semester'] ?? '',
        ]);

        $byId = [];
        foreach ($rows as $row) {
            $raw = (string) (($row['nisn'] ?? '') !== '' ? $row['nisn'] : ($row['id'] ?? $row['nis'] ?? ''));
            $key = $this->studentKey($raw);
            if ($key === '') {
                continue;
            }
            $canon = $this->canonicalNisn($raw) ?: (string) ($row['nisn'] ?? $row['nis'] ?? '');
            if (!isset($byId[$key])) {
                $byId[$key] = [
                    'nis' => $row['nis'],
                    'nisn' => $canon !== '' ? $canon : (string) ($row['nisn'] ?? ''),
                    'nama' => $row['nama'],
                    'jk' => $row['jk'],
                    'kelas' => (string) ($row['kelas'] ?? ''),
                ];
                continue;
            }
            // Pertahankan identitas; perbarui kelas/nama jika ada
            if (($row['kelas'] ?? '') !== '') {
                $byId[$key]['kelas'] = (string) $row['kelas'];
            }
            if (($row['nama'] ?? '') !== '') {
                $byId[$key]['nama'] = $row['nama'];
            }
            if (($row['jk'] ?? '') !== '') {
                $byId[$key]['jk'] = $row['jk'];
            }
            if (($row['nis'] ?? '') !== '') {
                $byId[$key]['nis'] = $row['nis'];
            }
            if (strlen($canon) >= strlen((string) $byId[$key]['nisn'])) {
                $byId[$key]['nisn'] = $canon;
            }
        }

        $list = array_values($byId);
        usort($list, static function ($a, $b) {
            $ka = (string) ($a['kelas'] ?? '');
            $kb = (string) ($b['kelas'] ?? '');
            $cmp = strnatcasecmp($ka, $kb);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) ($a['nama'] ?? ''), (string) ($b['nama'] ?? ''));
        });
        return $list;
    }

    /** Label tampilan filter kelas / tingkat. */
    public static function kelasFilterLabel(string $kelas): string
    {
        $kelas = trim($kelas);
        if (str_starts_with($kelas, 'tingkat:')) {
            return 'Semua kelas ' . strtoupper(substr($kelas, strlen('tingkat:')));
        }
        return $kelas !== '' ? $kelas : '—';
    }

    public function rekapPerSemester(array $data, array $q): array
    {
        $rows = $this->filterRecords($data['records'], $q);
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['semester_ke'] . '|' . $row['tahun_ajaran'] . '|' . $row['kelas'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'semester_ke' => $row['semester_ke'],
                    'semester' => $row['semester'],
                    'tahun_ajaran' => $row['tahun_ajaran'],
                    'kelas' => $row['kelas'],
                    'subjects' => array_keys($row['scores']),
                    'siswa' => [], // keyed by NISN norm
                ];
            }
            foreach (array_keys($row['scores']) as $subj) {
                if (!in_array($subj, $groups[$key]['subjects'], true)) {
                    $groups[$key]['subjects'][] = $subj;
                }
            }

            $sid = $this->studentKey((string) ($row['nisn'] !== '' ? $row['nisn'] : $row['id']));
            if ($sid === '') {
                $sid = 'row:' . count($groups[$key]['siswa']);
            }

            if (!isset($groups[$key]['siswa'][$sid])) {
                $groups[$key]['siswa'][$sid] = [
                    'id' => (string) ($row['id'] !== '' ? $row['id'] : ($row['nisn'] !== '' ? $row['nisn'] : $row['nis'])),
                    'nis' => $row['nis'],
                    'nisn' => $this->canonicalNisn((string) ($row['nisn'] !== '' ? $row['nisn'] : $row['id'])) ?: $row['nisn'],
                    'nama' => $row['nama'],
                    'jk' => $row['jk'],
                    'scores' => $row['scores'],
                    'jumlah' => $row['jumlah'],
                    'rata_rata' => $row['rata_rata'],
                    'rank' => $row['rank'],
                    '_sources' => 1,
                ];
            } else {
                // File legger ganda untuk kelas/semester sama → gabung nilai mapel
                $cur = &$groups[$key]['siswa'][$sid];
                $cur['nama'] = $row['nama'] !== '' ? $row['nama'] : $cur['nama'];
                if ($row['nis'] !== '') {
                    $cur['nis'] = $row['nis'];
                }
                if ($row['jk'] !== '') {
                    $cur['jk'] = $row['jk'];
                }
                // Pertahankan id filter (NISN/id mentah) agar klik nama cocok dengan dropdown
                if (($cur['id'] ?? '') === '' && ($row['id'] ?? '') !== '') {
                    $cur['id'] = (string) $row['id'];
                }
                foreach ($row['scores'] as $subj => $val) {
                    $prev = $cur['scores'][$subj] ?? null;
                    if ($val !== null && is_numeric($val) && (float) $val > 0) {
                        if ($prev === null || !is_numeric($prev) || (float) $prev <= 0) {
                            $cur['scores'][$subj] = $val;
                        }
                        // jika keduanya ada, biarkan nilai yang sudah ada (file pertama / lebih lengkap digabung non-null)
                    } elseif (!array_key_exists($subj, $cur['scores'])) {
                        $cur['scores'][$subj] = $val;
                    }
                }
                $cur['_sources']++;
                unset($cur);
            }
        }

        foreach ($groups as &$g) {
            foreach ($g['siswa'] as &$s) {
                $stats = $this->recalcJumlahRata($s['scores']);
                $s['jumlah'] = $stats['jumlah'];
                $s['rata_rata'] = $stats['rata_rata'];
                $s['mapel_count'] = $stats['mapel_count'];
                unset($s['_sources']);
            }
            unset($s);

            $g['siswa'] = array_values($g['siswa']);
            usort($g['siswa'], static function ($a, $b) {
                $ja = $a['jumlah'] ?? -INF;
                $jb = $b['jumlah'] ?? -INF;
                if ($ja === $jb) {
                    return strcasecmp($a['nama'], $b['nama']);
                }
                return $jb <=> $ja;
            });
            $rank = 1;
            foreach ($g['siswa'] as &$s) {
                $s['rank'] = $rank++;
            }
            unset($s);
            $g['ringkasan'] = $this->summaryStats(array_column($g['siswa'], 'jumlah'), array_column($g['siswa'], 'rata_rata'));
        }
        unset($g);

        $list = array_values($groups);
        usort($list, static fn ($a, $b) => $a['semester_ke'] <=> $b['semester_ke']);

        $totalSiswa = 0;
        foreach ($list as $g) {
            $totalSiswa += count($g['siswa']);
        }

        return [
            'mode' => 'per_semester',
            'filters' => $q,
            'total_records' => count($rows),
            'total_siswa' => $totalSiswa,
            'groups' => $list,
        ];
    }

    /** @param array<string, mixed> $scores */
    private function recalcJumlahRata(array $scores): array
    {
        $vals = [];
        foreach ($scores as $v) {
            if ($v !== null && is_numeric($v) && (float) $v > 0) {
                $vals[] = (float) $v;
            }
        }
        if ($vals === []) {
            return ['jumlah' => null, 'rata_rata' => null, 'mapel_count' => 0];
        }
        return [
            'jumlah' => round(array_sum($vals), 2),
            'rata_rata' => round(array_sum($vals) / count($vals), 1),
            'mapel_count' => count($vals),
        ];
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

    public function rekapSemuaSemester(array $data, array $q): array
    {
        $rows = $this->filterRecords($data['records'], $q);
        $byStudent = [];

        foreach ($rows as $row) {
            $rawId = (string) (($row['nisn'] ?? '') !== '' ? $row['nisn'] : ($row['id'] ?? ''));
            $key = $this->studentKey($rawId);
            if ($key === '') {
                $key = 'row:' . count($byStudent);
            }
            $canonId = $this->canonicalNisn($rawId) ?: (string) ($row['id'] ?? $rawId);

            if (!isset($byStudent[$key])) {
                $byStudent[$key] = [
                    'id' => $canonId,
                    'nisn' => $canonId,
                    'nama' => $row['nama'],
                    'jk' => $row['jk'],
                    'nis_list' => [],
                    'kelas_list' => [],
                    'semesters' => [], // keyed by semester_ke
                ];
            }
            $st = &$byStudent[$key];
            $st['nama'] = $row['nama'] !== '' ? $row['nama'] : $st['nama'];
            if (($row['jk'] ?? '') !== '') {
                $st['jk'] = $row['jk'];
            }
            // Prefer NISN 10 digit jika ada
            if (strlen($canonId) >= strlen((string) $st['nisn'])) {
                $st['id'] = $canonId;
                $st['nisn'] = $canonId;
            }
            if (($row['nis'] ?? '') !== '' && !in_array($row['nis'], $st['nis_list'], true)) {
                $st['nis_list'][] = $row['nis'];
            }
            if (($row['kelas'] ?? '') !== '' && !in_array($row['kelas'], $st['kelas_list'], true)) {
                $st['kelas_list'][] = $row['kelas'];
            }

            $stats = $this->recalcJumlahRata(is_array($row['scores'] ?? null) ? $row['scores'] : []);
            $jumlah = $stats['jumlah'] ?? $row['jumlah'];
            $rata = $stats['rata_rata'] ?? $row['rata_rata'];
            $mapelCount = $stats['mapel_count'] ?? (int) ($row['mapel_count'] ?? 0);
            $semKe = (int) ($row['semester_ke'] ?? 0);

            $candidate = [
                'semester_ke' => $semKe,
                'semester' => $row['semester'],
                'tahun_ajaran' => $row['tahun_ajaran'],
                'kelas' => $row['kelas'],
                'jumlah' => $jumlah,
                'rata_rata' => $rata,
                'rank' => $row['rank'],
                'mapel_count' => $mapelCount,
            ];

            if ($semKe > 0 && isset($st['semesters'][$semKe])) {
                $prev = $st['semesters'][$semKe];
                // Duplikat file legger → ambil yang lebih lengkap
                if ($mapelCount > (int) ($prev['mapel_count'] ?? 0)
                    || ($mapelCount === (int) ($prev['mapel_count'] ?? 0) && ($rata ?? -INF) > ($prev['rata_rata'] ?? -INF))
                ) {
                    $st['semesters'][$semKe] = $candidate;
                }
            } else {
                $st['semesters'][$semKe > 0 ? $semKe : ('x' . count($st['semesters']))] = $candidate;
            }
            unset($st);
        }

        $siswa = [];
        foreach ($byStudent as $st) {
            $semesters = array_values($st['semesters']);
            usort($semesters, static fn ($a, $b) => $a['semester_ke'] <=> $b['semester_ke']);

            $totalJumlah = 0.0;
            $totalRata = 0.0;
            $nRata = 0;
            foreach ($semesters as $sem) {
                if ($sem['jumlah'] !== null) {
                    $totalJumlah += (float) $sem['jumlah'];
                }
                if ($sem['rata_rata'] !== null) {
                    $totalRata += (float) $sem['rata_rata'];
                    $nRata++;
                }
            }

            $siswa[] = [
                'id' => $st['id'],
                'nisn' => $st['nisn'],
                'nama' => $st['nama'],
                'jk' => $st['jk'],
                'nis' => implode(', ', $st['nis_list']),
                'kelas' => implode(' → ', $st['kelas_list']),
                'semester_count' => count($semesters),
                'total_jumlah' => round($totalJumlah, 2),
                'rata_rata_semua' => $nRata > 0 ? round($totalRata / $nRata, 1) : null,
                'semesters' => $semesters,
            ];
        }

        usort($siswa, static function ($a, $b) {
            $ra = $a['rata_rata_semua'] ?? -INF;
            $rb = $b['rata_rata_semua'] ?? -INF;
            if ($ra === $rb) {
                return strcasecmp($a['nama'], $b['nama']);
            }
            return $rb <=> $ra;
        });

        $rank = 1;
        foreach ($siswa as &$s) {
            $s['rank_keseluruhan'] = $rank++;
        }
        unset($s);

        return [
            'mode' => 'semua_semester',
            'filters' => $q,
            'total_siswa' => count($siswa),
            'ringkasan' => $this->summaryStats(
                array_column($siswa, 'total_jumlah'),
                array_column($siswa, 'rata_rata_semua')
            ),
            'siswa' => $siswa,
        ];
    }

    public function rekapPerSiswa(array $data, array $q): array
    {
        $studentId = trim((string) ($q['id'] ?? ''));
        if ($studentId === '') {
            return [
                'mode' => 'per_siswa',
                'error' => 'Pilih ID siswa (NISN) terlebih dahulu.',
                'siswa' => null,
            ];
        }

        $rows = array_values(array_filter(
            $data['records'],
            function ($r) use ($studentId) {
                $candidates = [
                    (string) ($r['id'] ?? ''),
                    (string) ($r['nisn'] ?? ''),
                    (string) ($r['nis'] ?? ''),
                ];
                foreach ($candidates as $c) {
                    if ($c !== '' && ($c === $studentId || $this->studentKey($c) === $this->studentKey($studentId))) {
                        return true;
                    }
                }
                return false;
            }
        ));

        // Filter tambahan (tahun/semester/kelas) jika ada
        $rows = $this->filterRecords($rows, array_diff_key($q, ['id' => true]));

        if ($rows === []) {
            return [
                'mode' => 'per_siswa',
                'error' => 'Data siswa tidak ditemukan untuk filter yang dipilih.',
                'siswa' => null,
            ];
        }

        usort($rows, static fn ($a, $b) => $a['semester_ke'] <=> $b['semester_ke']);

        $first = $rows[0];
        $last = $rows[count($rows) - 1];
        $nisList = [];
        $kelasList = [];
        $semDetail = [];
        $sumJumlah = 0.0;
        $sumRata = 0.0;
        $nRata = 0;
        $allSubjects = [];

        foreach ($rows as $row) {
            if ($row['nis'] !== '' && !in_array($row['nis'], $nisList, true)) {
                $nisList[] = $row['nis'];
            }
            if ($row['kelas'] !== '' && !in_array($row['kelas'], $kelasList, true)) {
                $kelasList[] = $row['kelas'];
            }
            foreach ($row['scores'] as $subj => $val) {
                $allSubjects[$subj] = true;
            }
            if ($row['jumlah'] !== null) {
                $sumJumlah += $row['jumlah'];
            }
            if ($row['rata_rata'] !== null) {
                $sumRata += $row['rata_rata'];
                $nRata++;
            }
            $semDetail[] = [
                'semester_ke' => $row['semester_ke'],
                'semester' => $row['semester'],
                'tahun_ajaran' => $row['tahun_ajaran'],
                'kelas' => $row['kelas'],
                'scores' => $row['scores'],
                'subjects' => array_keys($row['scores']),
                'jumlah' => $row['jumlah'],
                'rata_rata' => $row['rata_rata'],
                'rank' => $row['rank'],
                'mapel_count' => $row['mapel_count'],
            ];
        }

        // Tren per mapel lintas semester (mapel yang pernah muncul)
        $subjectTrend = [];
        foreach (array_keys($allSubjects) as $subj) {
            $series = [];
            foreach ($rows as $row) {
                if (array_key_exists($subj, $row['scores'])) {
                    $series[] = [
                        'semester_ke' => $row['semester_ke'],
                        'label' => 'SEM ' . $row['semester_ke'],
                        'nilai' => $row['scores'][$subj],
                    ];
                }
            }
            $vals = array_values(array_filter(
                array_column($series, 'nilai'),
                static fn ($v) => $v !== null && (float) $v > 0
            ));
            $subjectTrend[$subj] = [
                'series' => $series,
                'rata' => $vals ? round(array_sum($vals) / count($vals), 1) : null,
                'max' => $vals ? max($vals) : null,
                'min' => $vals ? min($vals) : null,
            ];
        }

        return [
            'mode' => 'per_siswa',
            'filters' => $q,
            'siswa' => [
                'id' => $last['id'],
                'nisn' => $last['nisn'],
                'nama' => $last['nama'],
                'jk' => $last['jk'],
                'nis_list' => $nisList,
                'kelas_list' => $kelasList,
                'kelas_awal' => $first['kelas'],
                'kelas_akhir' => $last['kelas'],
                'semester_count' => count($rows),
                'total_jumlah' => round($sumJumlah, 2),
                'rata_rata_semua' => $nRata > 0 ? round($sumRata / $nRata, 1) : null,
                'semesters' => $semDetail,
                'subject_trend' => $subjectTrend,
                'hasil_belajar' => $this->buildHasilBelajar($data, $studentId, $last),
            ],
        ];
    }

    /**
     * Matriks rekap hasil belajar: X–XII (Ganjil/Genap) + rataan + ujian + nilai akhir.
     *
     * @return array{
     *   madrasah:string,nama:string,nis:string,nisn:string,jk:string,
     *   bobot:array,kelompok:list,jumlah_rataan:?float,jumlah_akhir:?float
     * }
     */
    private function buildHasilBelajar(array $data, string $studentId, array $last): array
    {
        // Semua jejak siswa (tanpa filter tahun/semester) untuk matriks X–XII
        $allRows = array_values(array_filter(
            $data['records'],
            static fn ($r) => (string) $r['id'] === $studentId
                || (string) $r['nisn'] === $studentId
                || (string) $r['nis'] === $studentId
        ));
        // Perluas match NISN dinormalisasi (007… vs 7…)
        if ($allRows === []) {
            $allRows = array_values(array_filter(
                $data['records'],
                function ($r) use ($studentId) {
                    return $this->sameNisn((string) ($r['nisn'] ?? ''), $studentId)
                        || $this->sameNisn((string) ($r['id'] ?? ''), $studentId);
                }
            ));
        }
        usort($allRows, static fn ($a, $b) => $a['semester_ke'] <=> $b['semester_ke']);

        $slots = ['x_ganjil', 'x_genap', 'xi_ganjil', 'xi_genap', 'xii_ganjil', 'xii_genap'];
        $matrix = []; // kode => slot => nilai

        foreach ($allRows as $row) {
            $tingkat = self::kelasTingkat((string) ($row['kelas'] ?? ''));
            if ($tingkat === null) {
                // fallback dari semester_ke
                $ke = (int) ($row['semester_ke'] ?? 0);
                $tingkat = $ke <= 2 ? 'X' : ($ke <= 4 ? 'XI' : ($ke <= 6 ? 'XII' : null));
            }
            if ($tingkat === null) {
                continue;
            }
            $slot = $this->hasilBelajarSlot($tingkat, (string) ($row['semester'] ?? ''), (int) ($row['semester_ke'] ?? 0));
            if ($slot === null) {
                continue;
            }
            foreach ($row['scores'] as $kode => $nilai) {
                $kode = (string) $kode;
                if ($kode === '') {
                    continue;
                }
                if (!isset($matrix[$kode])) {
                    $matrix[$kode] = array_fill_keys($slots, null);
                }
                if ($nilai === null || $nilai === '' || !is_numeric($nilai) || (float) $nilai <= 0) {
                    continue;
                }
                $v = round((float) $nilai, 1);
                // Jika bentrok (duplikat file), ambil nilai lebih tinggi
                $prev = $matrix[$kode][$slot];
                if ($prev === null || $v > $prev) {
                    $matrix[$kode][$slot] = $v;
                }
            }
        }

        $ijazah = $this->ijazahService->rekap($data, ['id' => $studentId]);
        $ijazahMap = [];
        foreach ($ijazah['siswa']['mapel'] ?? [] as $m) {
            $ijazahMap[(string) $m['kode']] = $m;
        }

        $kelompokDef = [
            'umum' => [
                'judul' => 'Kelompok Mata Pelajaran Umum',
                'kode' => ['QH', 'AA', 'FIK', 'SKI', 'BAR', 'PP', 'BINDO', 'MTK', 'IPAT', 'IPST', 'BING', 'PJOK', 'SEJ', 'INFO', 'SB', 'Jawa', 'Tahfi', 'riset'],
            ],
            'pilihan' => [
                'judul' => 'Kelompok Mata Pelajaran Pilihan',
                'kode' => ['INFOP', 'SOS', 'EKO', 'GEO', 'BIO', 'KIM', 'FIS', 'MTL', 'ABAR', 'BARTL', 'IHad', 'ITaf', 'UFiq', 'SejL'],
            ],
            'vokasi' => [
                'judul' => 'Kelompok Mata Pelajaran Vokasi / Keterampilan',
                'kode' => ['APHP', 'DKV', 'TB'],
            ],
        ];

        $used = [];
        $kelompok = [];
        $sumRataan = 0.0;
        $nRataan = 0;
        $sumAkhir = 0.0;
        $nAkhir = 0;

        foreach ($kelompokDef as $def) {
            $rowsOut = [];
            foreach ($def['kode'] as $kode) {
                $hasMatrix = isset($matrix[$kode]);
                $hasIjazah = isset($ijazahMap[$kode]);
                if (!$hasMatrix && !$hasIjazah) {
                    continue;
                }
                $nilai = $hasMatrix ? $matrix[$kode] : array_fill_keys($slots, null);
                $vals = array_values(array_filter($nilai, static fn ($v) => $v !== null));
                $rataan = $vals !== [] ? round(array_sum($vals) / count($vals), 1) : ($ijazahMap[$kode]['rataan'] ?? null);
                if ($rataan !== null && is_numeric($rataan)) {
                    $rataan = round((float) $rataan, 1);
                }
                $praktek = $ijazahMap[$kode]['ujian_praktek'] ?? null;
                $teori = $ijazahMap[$kode]['ujian_teori'] ?? null;
                $akhir = $ijazahMap[$kode]['nilai_ijazah'] ?? $rataan;

                if ($vals === [] && $rataan === null && $akhir === null && $praktek === null && $teori === null) {
                    continue;
                }
                $used[$kode] = true;

                if ($rataan !== null) {
                    $sumRataan += (float) $rataan;
                    $nRataan++;
                }
                if ($akhir !== null && is_numeric($akhir)) {
                    $sumAkhir += (float) $akhir;
                    $nAkhir++;
                }

                $rowsOut[] = [
                    'kode' => $kode,
                    'nama' => UjianStore::MAPEL[$kode] ?? ($ijazahMap[$kode]['nama'] ?? $kode),
                    'nilai' => $nilai,
                    'rataan' => $rataan,
                    'ujian_praktek' => $praktek !== null ? round((float) $praktek, 1) : null,
                    'ujian' => $teori !== null ? round((float) $teori, 1) : null,
                    'nilai_akhir' => $akhir !== null ? round((float) $akhir, 1) : null,
                ];
            }
            if ($rowsOut !== []) {
                $kelompok[] = [
                    'judul' => $def['judul'],
                    'rows' => $rowsOut,
                ];
            }
        }

        // Mapel lain yang tidak masuk definisi di atas
        $lain = [];
        foreach ($matrix as $kode => $nilai) {
            if (isset($used[$kode])) {
                continue;
            }
            $vals = array_values(array_filter($nilai, static fn ($v) => $v !== null));
            if ($vals === []) {
                continue;
            }
            $rataan = round(array_sum($vals) / count($vals), 1);
            $praktek = $ijazahMap[$kode]['ujian_praktek'] ?? null;
            $teori = $ijazahMap[$kode]['ujian_teori'] ?? null;
            $akhir = $ijazahMap[$kode]['nilai_ijazah'] ?? $rataan;
            if ($rataan !== null) {
                $sumRataan += $rataan;
                $nRataan++;
            }
            if ($akhir !== null && is_numeric($akhir)) {
                $sumAkhir += (float) $akhir;
                $nAkhir++;
            }
            $lain[] = [
                'kode' => $kode,
                'nama' => UjianStore::MAPEL[$kode] ?? ($ijazahMap[$kode]['nama'] ?? $kode),
                'nilai' => $nilai,
                'rataan' => $rataan,
                'ujian_praktek' => $praktek !== null ? round((float) $praktek, 1) : null,
                'ujian' => $teori !== null ? round((float) $teori, 1) : null,
                'nilai_akhir' => $akhir !== null ? round((float) $akhir, 1) : null,
            ];
        }
        if ($lain !== []) {
            usort($lain, static fn ($a, $b) => strcasecmp($a['nama'], $b['nama']));
            $kelompok[] = [
                'judul' => 'Mata Pelajaran Lainnya',
                'rows' => $lain,
            ];
        }

        $nisList = [];
        foreach ($allRows as $r) {
            if (($r['nis'] ?? '') !== '' && !in_array($r['nis'], $nisList, true)) {
                $nisList[] = $r['nis'];
            }
        }

        return [
            'madrasah' => (string) ($this->sekolahStore->active()['nama']
                ?: Config::get('madrasah', 'MAN 4 Sleman')),
            'sekolah' => $this->sekolahStore->activeForApi(),
            'cetak' => $this->sekolahStore->blokCetak(),
            'nama' => (string) ($last['nama'] ?? ''),
            'nis' => $nisList !== [] ? implode(', ', $nisList) : (string) ($last['nis'] ?? ''),
            'nisn' => (string) ($last['nisn'] !== '' ? $last['nisn'] : $last['id']),
            'jk' => (string) ($last['jk'] ?? ''),
            'kelas_akhir' => (string) ($last['kelas'] ?? ''),
            'bobot' => $this->ijazahService->getBobot(),
            'slots' => $slots,
            'kelompok' => $kelompok,
            'jumlah_rataan' => $nRataan > 0 ? round($sumRataan, 1) : null,
            'jumlah_akhir' => $nAkhir > 0 ? round($sumAkhir, 1) : null,
        ];
    }

    private function hasilBelajarSlot(string $tingkat, string $semester, int $semesterKe): ?string
    {
        $tingkat = strtoupper($tingkat);
        $sem = strtolower(trim($semester));
        $isGanjil = str_contains($sem, 'ganjil') || in_array($semesterKe, [1, 3, 5], true);
        $isGenap = str_contains($sem, 'genap') || in_array($semesterKe, [2, 4, 6], true);
        if (!$isGanjil && !$isGenap) {
            return null;
        }
        $half = $isGenap ? 'genap' : 'ganjil';
        return match ($tingkat) {
            'X' => 'x_' . $half,
            'XI' => 'xi_' . $half,
            'XII' => 'xii_' . $half,
            default => null,
        };
    }

    /**
     * Gabungkan siswa yang NISN-nya beda hanya karena nol di depan (Excel).
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function buildNormalizedStudentIndex(array $records): array
    {
        $byId = [];
        foreach ($records as $rec) {
            $raw = (string) (($rec['nisn'] ?? '') !== '' ? $rec['nisn'] : ($rec['id'] ?? ''));
            $key = $this->studentKey($raw);
            if ($key === '') {
                continue;
            }
            $canon = $this->canonicalNisn($raw) ?: (string) ($rec['id'] ?? $raw);
            if (!isset($byId[$key])) {
                $byId[$key] = [
                    'id' => $canon,
                    'nisn' => $canon,
                    'nama' => $rec['nama'] ?? '',
                    'jk' => $rec['jk'] ?? '',
                    'nis_list' => [],
                    'kelas_list' => [],
                ];
            }
            $st = &$byId[$key];
            if (strlen($canon) >= strlen((string) $st['nisn'])) {
                $st['id'] = $canon;
                $st['nisn'] = $canon;
            }
            if (($rec['nis'] ?? '') !== '' && !in_array($rec['nis'], $st['nis_list'], true)) {
                $st['nis_list'][] = $rec['nis'];
            }
            if (($rec['kelas'] ?? '') !== '' && !in_array($rec['kelas'], $st['kelas_list'], true)) {
                $st['kelas_list'][] = $rec['kelas'];
            }
            if (($rec['nama'] ?? '') !== '') {
                $st['nama'] = $rec['nama'];
            }
            if (($rec['jk'] ?? '') !== '') {
                $st['jk'] = $rec['jk'];
            }
            unset($st);
        }

        $students = array_values($byId);
        usort($students, static fn ($a, $b) => strcasecmp((string) $a['nama'], (string) $b['nama']));
        return $students;
    }

    private function sameNisn(string $a, string $b): bool
    {
        $na = preg_replace('/\D+/', '', $a) ?? '';
        $nb = preg_replace('/\D+/', '', $b) ?? '';
        if ($na === '' || $nb === '') {
            return false;
        }
        return ltrim($na, '0') === ltrim($nb, '0');
    }

    private function filterRecords(array $records, array $q): array
    {
        $tahun = trim((string) ($q['tahun_ajaran'] ?? ''));
        $semester = trim((string) ($q['semester'] ?? ''));
        $semesterKe = trim((string) ($q['semester_ke'] ?? ''));
        $kelas = trim((string) ($q['kelas'] ?? ''));
        $id = trim((string) ($q['id'] ?? ''));

        return array_values(array_filter($records, function ($r) use ($tahun, $semester, $semesterKe, $kelas, $id) {
            if ($tahun !== '' && (string) $r['tahun_ajaran'] !== $tahun) {
                return false;
            }
            if ($semester !== '' && (string) $r['semester'] !== $semester) {
                return false;
            }
            if ($semesterKe !== '' && (string) $r['semester_ke'] !== $semesterKe) {
                return false;
            }
            if ($kelas !== '' && !$this->matchKelasFilter((string) $r['kelas'], $kelas)) {
                return false;
            }
            if ($id !== '') {
                $ok = $this->sameNisn((string) ($r['id'] ?? ''), $id)
                    || $this->sameNisn((string) ($r['nisn'] ?? ''), $id)
                    || (string) ($r['nis'] ?? '') === $id
                    || (string) ($r['id'] ?? '') === $id
                    || (string) ($r['nisn'] ?? '') === $id;
                if (!$ok) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Cocokkan filter kelas: nama kelas exact atau tingkat:X / tingkat:XI / tingkat:XII.
     */
    public function matchKelasFilter(string $rowKelas, string $filter): bool
    {
        $filter = trim($filter);
        $rowKelas = trim($rowKelas);
        if ($filter === '') {
            return true;
        }
        if (str_starts_with($filter, 'tingkat:')) {
            $tingkat = strtoupper(substr($filter, strlen('tingkat:')));
            if (!in_array($tingkat, ['X', 'XI', 'XII'], true)) {
                return false;
            }
            if (!preg_match('/^(XII|XI|X)\b/i', $rowKelas, $m)) {
                return false;
            }
            return strtoupper($m[1]) === $tingkat;
        }
        return $rowKelas === $filter;
    }

    /** Apakah nama kelas termasuk tingkat tertentu. */
    public static function kelasTingkat(string $kelas): ?string
    {
        if (preg_match('/^(XII|XI|X)\b/i', trim($kelas), $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    private function summaryStats(array $jumlahs, array $ratas): array
    {
        $jumlahs = array_values(array_filter($jumlahs, static fn ($v) => $v !== null));
        $ratas = array_values(array_filter($ratas, static fn ($v) => $v !== null));

        return [
            'jumlah_siswa' => count($jumlahs) ?: count($ratas),
            'jumlah_max' => $jumlahs ? max($jumlahs) : null,
            'jumlah_min' => $jumlahs ? min($jumlahs) : null,
            'jumlah_avg' => $jumlahs ? round(array_sum($jumlahs) / count($jumlahs), 2) : null,
            'rata_max' => $ratas ? round((float) max($ratas), 1) : null,
            'rata_min' => $ratas ? round((float) min($ratas), 1) : null,
            'rata_avg' => $ratas ? round(array_sum($ratas) / count($ratas), 1) : null,
        ];
    }
}
