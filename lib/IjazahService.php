<?php

declare(strict_types=1);

require_once __DIR__ . '/UjianStore.php';
require_once __DIR__ . '/Config.php';

/**
 * Rekap nilai ijazah: rataan rapor (X–XII) + ujian praktek + ujian teori.
 */
final class IjazahService
{
    /**
     * Urutan mapel nilai ijazah.
     * Label singkat: PP→PPKn, BINDO→INDO, IPAT→IPA, IPST→IPS, SEJ→SI, SB→SP
     */
    public const MAPEL_ORDER = [
        'QH', 'AA', 'FIK', 'SKI', 'PP', 'BINDO', 'MTK', 'IPAT', 'IPST',
        'BING', 'BAR', 'PJOK', 'INFO', 'SEJ', 'SB',
        // seterusnya
        'INFOP', 'Jawa', 'Tahfi', 'SOS', 'EKO', 'GEO', 'BIO', 'KIM', 'FIS', 'MTL',
        'APHP_DKV_TB', 'DKV', 'APHP', 'ABAR', 'BARTL', 'IHad', 'ITaf', 'UFiq', 'TB', 'SejL', 'riset',
    ];

    /**
     * Mapel yang dianggap sama saat mengelompokkan siswa.
     * Siswa dengan APHP, DKV, atau TB masuk kelompok yang sama.
     */
    public const MAPEL_EQUIVALEN = [
        'APHP' => 'APHP_DKV_TB',
        'DKV' => 'APHP_DKV_TB',
        'TB' => 'APHP_DKV_TB',
    ];

    /** Label kolom singkat di tabel ijazah */
    public const MAPEL_SHORT = [
        'QH' => 'QH',
        'AA' => 'AA',
        'FIK' => 'FIK',
        'SKI' => 'SKI',
        'PP' => 'PPKn',
        'BINDO' => 'INDO',
        'MTK' => 'MTK',
        'IPAT' => 'IPA',
        'IPST' => 'IPS',
        'BING' => 'BING',
        'BAR' => 'BAR',
        'PJOK' => 'PJOK',
        'INFO' => 'INFO',
        'SEJ' => 'SI',
        'SejL' => 'SejL',
        'SB' => 'SP',
        'APHP' => 'APHP',
        'DKV' => 'DKV',
        'APHP_DKV_TB' => 'APHP/DKV/TB',
    ];

    public const DEFAULT_BOBOT = [
        'rataan' => 60,
        'praktek' => 20,
        'teori' => 20,
    ];

    public function __construct(
        private readonly UjianStore $ujianStore,
        private readonly string $settingsPath,
    ) {
    }

    public function getBobot(): array
    {
        $bobot = self::DEFAULT_BOBOT;

        // Utama: MySQL (bisa ditulis Apache)
        try {
            $pdo = Config::pdo();
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS rdm_settings (
                    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    setting_value LONGTEXT NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $stmt = $pdo->query('SELECT setting_value FROM rdm_settings WHERE setting_key = "ijazah_bobot" LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($row) {
                $decoded = json_decode((string) $row['setting_value'], true);
                if (is_array($decoded)) {
                    foreach (['rataan', 'praktek', 'teori'] as $k) {
                        if (isset($decoded[$k]) && is_numeric($decoded[$k])) {
                            $bobot[$k] = (float) $decoded[$k];
                        }
                    }
                    return $bobot;
                }
            }
        } catch (Throwable) {
            // lanjut ke file
        }

        if (is_readable($this->settingsPath)) {
            $json = json_decode(file_get_contents($this->settingsPath) ?: '', true);
            if (is_array($json['bobot'] ?? null)) {
                foreach (['rataan', 'praktek', 'teori'] as $k) {
                    if (isset($json['bobot'][$k]) && is_numeric($json['bobot'][$k])) {
                        $bobot[$k] = (float) $json['bobot'][$k];
                    }
                }
            }
        }
        return $bobot;
    }

    public function saveBobot(array $input): array
    {
        $praktek = round((float) ($input['praktek'] ?? self::DEFAULT_BOBOT['praktek']), 2);
        $teori = round((float) ($input['teori'] ?? self::DEFAULT_BOBOT['teori']), 2);
        if ($praktek < 0 || $praktek > 100) {
            throw new InvalidArgumentException('Bobot praktek harus antara 0–100.');
        }
        if ($teori < 0 || $teori > 100) {
            throw new InvalidArgumentException('Bobot teori harus antara 0–100.');
        }
        $ujian = $praktek + $teori;
        if ($ujian > 100) {
            throw new InvalidArgumentException('Total bobot praktek + teori tidak boleh lebih dari 100% (sekarang ' . $ujian . '%).');
        }
        // Persentase rapor dihitung otomatis: sisa dari 100%
        $rataan = round(100 - $ujian, 2);
        $bobot = [
            'rataan' => $rataan,
            'praktek' => $praktek,
            'teori' => $teori,
        ];

        $dir = dirname($this->settingsPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Simpan juga ke MySQL jika memungkinkan; file sebagai cadangan
        $payload = ['bobot' => $bobot, 'updated_at' => date('c')];
        @file_put_contents(
            $this->settingsPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        $this->saveBobotDb($bobot);

        return $bobot;
    }

    /**
     * Rekap nilai ijazah.
     *
     * @param array{kelas?:string,id?:string,tahun_ajaran?:string,semester?:string} $q
     */
    public function rekap(array $data, array $q = []): array
    {
        $bobot = $this->getBobot();
        $studentId = trim((string) ($q['id'] ?? ''));
        $kelas = trim((string) ($q['kelas'] ?? ''));

        $ujianIndex = $this->buildUjianIndex();

        if ($studentId !== '') {
            $detail = $this->buildStudentIjazah($data, $studentId, $bobot, $ujianIndex, $q);
            return [
                'mode' => 'detail',
                'bobot' => $bobot,
                'mapel_labels' => UjianStore::MAPEL,
                'mapel_short' => self::MAPEL_SHORT,
                'siswa' => $detail,
            ];
        }

        // Daftar: hanya siswa yang punya nilai ujian teori
        $students = $this->listCandidates($data, $kelas);
        $teoriNisn = $this->nisnWithUjian('teori');
        $buckets = []; // angkatan => mapelKey => group
        $seen = []; // angkatan|nisnNorm

        foreach ($students as $st) {
            $stNisn = $this->normalizeNisn((string) ($st['nisn'] ?? $st['id'] ?? ''));
            if ($stNisn === '' || !isset($teoriNisn[$stNisn])) {
                continue;
            }

            $detail = $this->buildStudentIjazah($data, $st['id'], $bobot, $ujianIndex, $q);
            if ($detail === null) {
                continue;
            }

            $nisn = $this->normalizeNisn((string) ($detail['nisn'] !== '' ? $detail['nisn'] : $detail['id']));
            if ($nisn === '' || !isset($teoriNisn[$nisn])) {
                continue;
            }

            $angkatan = $this->resolveAngkatan($data, $nisn);
            $seenKey = $angkatan . '|' . $nisn;
            if (isset($seen[$seenKey])) {
                continue;
            }
            $seen[$seenKey] = true;

            $mapelMeta = [];
            $nilaiMapel = [];
            $seenGroup = [];
            foreach ($detail['mapel'] as $m) {
                $nilai = $m['nilai_ijazah'] ?? $m['rataan'] ?? null;
                // Hanya mapel yang punya nilai
                if ($nilai === null || !is_numeric($nilai)) {
                    continue;
                }
                $kodeAsli = (string) $m['kode'];
                $kode = $this->mapelGroupKode($kodeAsli);
                if (isset($seenGroup[$kode])) {
                    if (($nilaiMapel[$kode] ?? null) === null) {
                        $nilaiMapel[$kode] = (float) $nilai;
                    }
                    continue;
                }
                $seenGroup[$kode] = true;
                $mapelMeta[] = [
                    'kode' => $kode,
                    'kode_asli' => $kodeAsli,
                    'nama' => $this->mapelGroupNama($kode, (string) $m['nama']),
                    'short' => $this->mapelGroupShort($kode),
                ];
                $nilaiMapel[$kode] = (float) $nilai;
            }
            $kodeSorted = $this->sortedMapelKeys(array_keys($seenGroup));
            $metaByKode = [];
            foreach ($mapelMeta as $meta) {
                $metaByKode[$meta['kode']] = $meta;
            }
            $mapelMeta = [];
            foreach ($kodeSorted as $kode) {
                if (isset($metaByKode[$kode])) {
                    $mapelMeta[] = $metaByKode[$kode];
                }
            }
            $mapelKey = implode('|', array_column($mapelMeta, 'kode'));
            if ($mapelKey === '') {
                $mapelKey = '_kosong';
            }

            if (!isset($buckets[$angkatan][$mapelKey])) {
                $buckets[$angkatan][$mapelKey] = [
                    'key' => $mapelKey,
                    'mapel' => $mapelMeta,
                    'mapel_count' => count($mapelMeta),
                    'siswa' => [],
                ];
            }

            $buckets[$angkatan][$mapelKey]['siswa'][] = [
                'id' => $nisn,
                'nisn' => $nisn,
                'nis' => $detail['nis'],
                'nama' => $detail['nama'],
                'jk' => $detail['jk'],
                'kelas_list' => $detail['kelas_list'],
                'kelas_akhir' => $detail['kelas_akhir'],
                'angkatan' => $angkatan,
                'mapel_count' => count($mapelMeta),
                'nilai_mapel' => $nilaiMapel,
                'rata_rataan' => $detail['ringkasan']['rata_rataan'],
                'rata_praktek' => $detail['ringkasan']['rata_praktek'],
                'rata_teori' => $detail['ringkasan']['rata_teori'],
                'rata_ijazah' => $detail['ringkasan']['rata_ijazah'],
            ];
        }

        $angkatanList = [];
        $flat = [];
        $totalKelompok = 0;
        $tahunKeys = array_keys($buckets);
        rsort($tahunKeys);

        foreach ($tahunKeys as $tahun) {
            $kelompok = [];
            $seenInAngkatan = [];

            foreach ($buckets[$tahun] as $g) {
                $siswaUniq = [];
                foreach ($g['siswa'] as $s) {
                    $nid = $this->normalizeNisn((string) ($s['nisn'] ?? $s['id'] ?? ''));
                    if ($nid === '' || isset($seenInAngkatan[$nid])) {
                        continue;
                    }
                    $seenInAngkatan[$nid] = true;
                    $s['nisn'] = $nid;
                    $s['id'] = $nid;
                    $siswaUniq[] = $s;
                }
                if ($siswaUniq === []) {
                    continue;
                }
                $g['siswa'] = $siswaUniq;

                usort($g['siswa'], static function ($a, $b) {
                    $ia = $a['rata_ijazah'] ?? -INF;
                    $ib = $b['rata_ijazah'] ?? -INF;
                    if ($ia === $ib) {
                        return strcasecmp($a['nama'], $b['nama']);
                    }
                    return $ib <=> $ia;
                });
                $rank = 1;
                foreach ($g['siswa'] as &$r) {
                    $r['rank'] = $rank++;
                }
                unset($r);

                $kodeList = array_column($g['mapel'], 'kode');
                $rataMapel = [];
                foreach ($kodeList as $kode) {
                    $vals = [];
                    foreach ($g['siswa'] as $s) {
                        $v = $s['nilai_mapel'][$kode] ?? null;
                        if ($v !== null && is_numeric($v)) {
                            $vals[] = (float) $v;
                        }
                    }
                    $rataMapel[$kode] = $vals === [] ? null : round(array_sum($vals) / count($vals), 1);
                }

                $namaList = array_column($g['mapel'], 'nama');
                $preview = array_slice($namaList, 0, 6);
                $judul = $g['mapel_count'] > 0
                    ? implode(', ', $preview) . ($g['mapel_count'] > 6 ? '…' : '')
                    : 'Tanpa mapel';

                $kelompok[] = [
                    'key' => $g['key'],
                    'judul' => $judul,
                    'mapel' => $g['mapel'],
                    'mapel_kode' => $kodeList,
                    'mapel_nama' => $namaList,
                    'mapel_count' => $g['mapel_count'],
                    'rata_mapel' => $rataMapel,
                    'total_siswa' => count($g['siswa']),
                    'siswa' => $g['siswa'],
                ];
                foreach ($g['siswa'] as $s) {
                    $flat[] = $s;
                }
            }

            usort($kelompok, static function ($a, $b) {
                if ($a['total_siswa'] === $b['total_siswa']) {
                    if ($a['mapel_count'] === $b['mapel_count']) {
                        return strcmp($a['judul'], $b['judul']);
                    }
                    return $b['mapel_count'] <=> $a['mapel_count'];
                }
                return $b['total_siswa'] <=> $a['total_siswa'];
            });

            $totalKelompok += count($kelompok);
            $countSiswa = 0;
            foreach ($kelompok as $g) {
                $countSiswa += $g['total_siswa'];
            }

            $angkatanList[] = [
                'tahun' => $tahun,
                'label' => $tahun === 'Tanpa angkatan' ? $tahun : ('Angkatan ' . $tahun),
                'total_siswa' => $countSiswa,
                'total_kelompok' => count($kelompok),
                'kelompok' => $kelompok,
            ];
        }

        return [
            'mode' => 'daftar',
            'bobot' => $bobot,
            'mapel_labels' => UjianStore::MAPEL,
            'mapel_short' => self::MAPEL_SHORT,
            'mapel_order' => self::MAPEL_ORDER,
            'total_siswa' => count($flat),
            'total_angkatan' => count($angkatanList),
            'total_kelompok' => $totalKelompok,
            'angkatan' => $angkatanList,
            'kelompok' => $angkatanList[0]['kelompok'] ?? [],
            'siswa' => $flat,
        ];
    }

    /** NISN kanonik 10 digit (007… dan 7… dianggap sama). */
    private function normalizeNisn(string $raw): string
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

    /** Angkatan = tahun ajaran saat di kelas XII (terbaru). */
    private function resolveAngkatan(array $data, string $nisnNorm): string
    {
        $xiiYears = [];
        $allYears = [];
        foreach ($data['records'] as $r) {
            $match = $this->normalizeNisn((string) ($r['nisn'] ?? '')) === $nisnNorm
                || $this->normalizeNisn((string) ($r['id'] ?? '')) === $nisnNorm;
            if (!$match) {
                continue;
            }
            $ta = trim((string) ($r['tahun_ajaran'] ?? ''));
            if ($ta === '') {
                continue;
            }
            $allYears[$ta] = true;
            if (preg_match('/^XII\b/i', (string) ($r['kelas'] ?? ''))) {
                $xiiYears[$ta] = true;
            }
        }
        $pool = $xiiYears !== [] ? array_keys($xiiYears) : array_keys($allYears);
        if ($pool === []) {
            return 'Tanpa angkatan';
        }
        rsort($pool);
        return $pool[0];
    }

    /**
     * NISN (normalized) yang punya minimal satu nilai ujian jenis tertentu.
     *
     * @return array<string, true>
     */
    public function nisnWithUjian(string $jenis): array
    {
        $jenis = strtolower(trim($jenis));
        $set = [];
        foreach ($this->ujianStore->list($jenis !== '' ? $jenis : null) as $ujian) {
            if ($jenis !== '' && ($ujian['jenis'] ?? '') !== $jenis) {
                continue;
            }
            foreach ($ujian['siswa'] ?? [] as $s) {
                if (($s['nilai_akhir'] ?? null) === null || $s['nilai_akhir'] === '') {
                    continue;
                }
                if (!is_numeric($s['nilai_akhir'])) {
                    continue;
                }
                $nisn = $this->normalizeNisn((string) ($s['nisn'] ?? ''));
                if ($nisn === '') {
                    continue;
                }
                $set[$nisn] = true;
            }
        }
        return $set;
    }

    /**
     * Daftar siswa (untuk filter ID) yang punya nilai ujian teori.
     *
     * @return list<array{id:string,nisn:string,nama:string,kelas_list:list<string>}>
     */
    public function studentsWithUjianTeori(array $data): array
    {
        $teori = $this->nisnWithUjian('teori');
        if ($teori === []) {
            return [];
        }
        $out = [];
        foreach ($data['students'] ?? [] as $st) {
            $id = $this->normalizeNisn((string) ($st['nisn'] ?? $st['id'] ?? ''));
            if ($id === '' || !isset($teori[$id])) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'nisn' => $id,
                'nama' => (string) ($st['nama'] ?? ''),
                'kelas_list' => array_values($st['kelas_list'] ?? []),
            ];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['nama'], $b['nama']));
        return $out;
    }

    private function listCandidates(array $data, string $kelas): array
    {
        $byNisn = [];
        foreach ($data['records'] as $row) {
            $nisn = $this->normalizeNisn((string) ($row['nisn'] !== '' ? $row['nisn'] : $row['id']));
            if ($nisn === '') {
                continue;
            }
            if (!isset($byNisn[$nisn])) {
                $byNisn[$nisn] = [
                    'id' => $nisn,
                    'nisn' => $nisn,
                    'nama' => $row['nama'],
                    'kelas_set' => [],
                ];
            }
            $byNisn[$nisn]['nama'] = $row['nama'];
            if ($row['kelas'] !== '') {
                $byNisn[$nisn]['kelas_set'][$row['kelas']] = true;
            }
        }

        $out = [];
        foreach ($byNisn as $st) {
            $kelasList = array_keys($st['kelas_set']);
            if ($kelas !== '' && !$this->matchKelasList($kelasList, $kelas)) {
                continue;
            }
            $out[] = [
                'id' => $st['id'],
                'nisn' => $st['nisn'],
                'nama' => $st['nama'],
            ];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['nama'], $b['nama']));
        return $out;
    }

    /** @param list<string> $kelasList */
    private function matchKelasList(array $kelasList, string $filter): bool
    {
        $filter = trim($filter);
        if ($filter === '') {
            return true;
        }
        if (str_starts_with($filter, 'tingkat:')) {
            $tingkat = strtoupper(substr($filter, strlen('tingkat:')));
            foreach ($kelasList as $k) {
                if (preg_match('/^(XII|XI|X)\b/i', trim((string) $k), $m)
                    && strtoupper($m[1]) === $tingkat
                ) {
                    return true;
                }
            }
            return false;
        }
        return in_array($filter, $kelasList, true);
    }

    private function buildStudentIjazah(
        array $data,
        string $studentId,
        array $bobot,
        array $ujianIndex,
        array $q
    ): ?array {
        $want = $this->normalizeNisn($studentId);
        $records = array_values(array_filter(
            $data['records'],
            function ($r) use ($studentId, $want) {
                if ($want !== '' && (
                    $this->normalizeNisn((string) ($r['id'] ?? '')) === $want
                    || $this->normalizeNisn((string) ($r['nisn'] ?? '')) === $want
                )) {
                    return true;
                }
                return (string) $r['id'] === $studentId
                    || (string) $r['nisn'] === $studentId
                    || (string) $r['nis'] === $studentId;
            }
        ));
        if ($records === []) {
            return null;
        }

        usort($records, static fn ($a, $b) => $a['semester_ke'] <=> $b['semester_ke']);
        $first = $records[0];
        $last = $records[count($records) - 1];

        $nisList = [];
        $kelasList = [];
        foreach ($records as $r) {
            if ($r['nis'] !== '' && !in_array($r['nis'], $nisList, true)) {
                $nisList[] = $r['nis'];
            }
            if ($r['kelas'] !== '' && !in_array($r['kelas'], $kelasList, true)) {
                $kelasList[] = $r['kelas'];
            }
        }

        // Agregasi per mapel dari rapor X–XII (nilai kosong/0 diabaikan)
        $mapelAgg = [];
        foreach ($records as $r) {
            foreach ($r['scores'] as $kode => $nilai) {
                if (!isset($mapelAgg[$kode])) {
                    $mapelAgg[$kode] = [
                        'kode' => $kode,
                        'nama' => $this->mapelLabel($kode),
                        'nilai' => [],
                        'semesters' => [],
                        'diabaikan' => [],
                    ];
                }

                $meta = [
                    'semester_ke' => $r['semester_ke'],
                    'kelas' => $r['kelas'],
                    'tahun_ajaran' => $r['tahun_ajaran'],
                    'semester' => $r['semester'],
                    'nilai' => $nilai === null ? null : (float) $nilai,
                ];

                if (!$this->isValidNilaiRapor($nilai)) {
                    $mapelAgg[$kode]['diabaikan'][] = $meta + [
                        'alasan' => ($nilai === null) ? 'kosong' : '0',
                    ];
                    continue;
                }

                $mapelAgg[$kode]['nilai'][] = (float) $nilai;
                $mapelAgg[$kode]['semesters'][] = $meta;
            }
        }

        $nisnRaw = $last['nisn'] !== '' ? $last['nisn'] : $last['id'];
        $nisn = $this->normalizeNisn((string) $nisnRaw) ?: (string) $nisnRaw;
        $rows = [];
        foreach ($this->sortedMapelKeys(array_keys($mapelAgg)) as $kode) {
            $agg = $mapelAgg[$kode];
            $vals = $agg['nilai'];
            $jumlah = $vals === [] ? null : round(array_sum($vals), 2);
            $rataan = $vals === [] ? null : round(array_sum($vals) / count($vals), 1);

            $praktek = $this->findUjianNilai($ujianIndex, 'praktek', $nisn, $kode, $agg['nama']);
            $teori = $this->findUjianNilai($ujianIndex, 'teori', $nisn, $kode, $agg['nama']);

            $ijazah = $this->hitungIjazah($rataan, $praktek, $teori, $bobot);
            $keterangan = $this->formatKeteranganDiabaikan($agg['diabaikan']);

            $rows[] = [
                'kode' => $kode,
                'nama' => $agg['nama'],
                'jumlah' => $jumlah,
                'rataan' => $rataan,
                'semester_count' => count($vals),
                'diabaikan_count' => count($agg['diabaikan']),
                'ujian_praktek' => $praktek,
                'ujian_teori' => $teori,
                'nilai_ijazah' => $ijazah,
                'keterangan' => $keterangan,
                'detail_semester' => $agg['semesters'],
                'detail_diabaikan' => $agg['diabaikan'],
            ];
        }

        $ringkasan = $this->ringkasanRows($rows);

        return [
            'id' => $nisn,
            'nisn' => $nisn,
            'nis' => implode(', ', $nisList),
            'nama' => $last['nama'],
            'jk' => $last['jk'],
            'kelas_list' => $kelasList,
            'kelas_awal' => $first['kelas'],
            'kelas_akhir' => $last['kelas'],
            'semester_count' => count($records),
            'bobot' => $bobot,
            'mapel' => $rows,
            'ringkasan' => $ringkasan,
        ];
    }

    /** Nilai rapor valid untuk perhitungan: bukan kosong dan > 0 */
    private function isValidNilaiRapor(mixed $nilai): bool
    {
        if ($nilai === null || $nilai === '') {
            return false;
        }
        if (!is_numeric($nilai)) {
            return false;
        }
        return (float) $nilai > 0;
    }

    /**
     * @param list<array{semester_ke:int|string,kelas:string,alasan:string}> $diabaikan
     */
    private function formatKeteranganDiabaikan(array $diabaikan): string
    {
        if ($diabaikan === []) {
            return '';
        }

        $parts = [];
        foreach ($diabaikan as $d) {
            $sem = 'Sem ' . ($d['semester_ke'] ?? '?');
            $kelas = trim((string) ($d['kelas'] ?? ''));
            $lokasi = $kelas !== '' ? "{$sem} ({$kelas})" : $sem;
            $parts[] = ($d['alasan'] ?? '') === '0'
                ? "{$lokasi}=0"
                : "{$lokasi} kosong";
        }

        return 'Diabaikan: ' . implode('; ', $parts);
    }

    private function hitungIjazah(?float $rataan, ?float $praktek, ?float $teori, array $bobot): ?float
    {
        $parts = [];
        if ($rataan !== null && $rataan > 0) {
            $parts[] = ['nilai' => $rataan, 'bobot' => $bobot['rataan']];
        }
        if ($praktek !== null) {
            $parts[] = ['nilai' => $praktek, 'bobot' => $bobot['praktek']];
        }
        if ($teori !== null) {
            $parts[] = ['nilai' => $teori, 'bobot' => $bobot['teori']];
        }
        if ($parts === []) {
            return null;
        }

        $bobotAktif = array_sum(array_column($parts, 'bobot'));
        if ($bobotAktif <= 0) {
            return null;
        }

        $total = 0.0;
        foreach ($parts as $p) {
            $total += $p['nilai'] * ($p['bobot'] / $bobotAktif);
        }
        return round($total, 2);
    }

    private function ringkasanRows(array $rows): array
    {
        $rataan = [];
        $praktek = [];
        $teori = [];
        $ijazah = [];
        foreach ($rows as $r) {
            if ($r['rataan'] !== null) {
                $rataan[] = $r['rataan'];
            }
            if ($r['ujian_praktek'] !== null) {
                $praktek[] = $r['ujian_praktek'];
            }
            if ($r['ujian_teori'] !== null) {
                $teori[] = $r['ujian_teori'];
            }
            if ($r['nilai_ijazah'] !== null) {
                $ijazah[] = $r['nilai_ijazah'];
            }
        }

        $avg = static fn (array $a) => $a === [] ? null : round(array_sum($a) / count($a), 1);

        return [
            'mapel_count' => count($rows),
            'rata_rataan' => $avg($rataan),
            'rata_praktek' => $avg($praktek),
            'rata_teori' => $avg($teori),
            'rata_ijazah' => $avg($ijazah),
        ];
    }

    /**
     * Index: jenis|nisn|mapelKey => nilai
     *
     * @return array<string, float>
     */
    private function buildUjianIndex(): array
    {
        $index = [];
        foreach ($this->ujianStore->list() as $ujian) {
            $jenis = $ujian['jenis'] ?? '';
            $mapelKode = strtoupper(trim((string) ($ujian['mapel'] ?? '')));
            $mapelNama = strtolower(trim((string) ($ujian['mapel_nama'] ?? '')));
            foreach ($ujian['siswa'] ?? [] as $s) {
                if (($s['nilai_akhir'] ?? null) === null) {
                    continue;
                }
                $nisn = $this->normalizeNisn((string) ($s['nisn'] ?? ''));
                if ($nisn === '') {
                    continue;
                }
                $nilai = (float) $s['nilai_akhir'];
                $keys = [];
                if ($mapelKode !== '') {
                    $keys[] = $jenis . '|' . $nisn . '|' . strtolower($mapelKode);
                }
                if ($mapelNama !== '') {
                    $keys[] = $jenis . '|' . $nisn . '|nama:' . $mapelNama;
                }
                // Alias dari MAPEL
                foreach (UjianStore::MAPEL as $kode => $nama) {
                    if (strtoupper($kode) === $mapelKode || strtolower($nama) === $mapelNama) {
                        $keys[] = $jenis . '|' . $nisn . '|' . strtolower($kode);
                        $keys[] = $jenis . '|' . $nisn . '|nama:' . strtolower($nama);
                    }
                }
                foreach (array_unique($keys) as $key) {
                    // Ambil nilai tertinggi jika ada beberapa sesi
                    if (!isset($index[$key]) || $nilai > $index[$key]) {
                        $index[$key] = $nilai;
                    }
                }
            }
        }
        return $index;
    }

    private function findUjianNilai(array $index, string $jenis, string $nisn, string $kode, string $nama): ?float
    {
        $nisn = $this->normalizeNisn($nisn) ?: trim($nisn);
        $candidates = [
            $jenis . '|' . $nisn . '|' . strtolower($kode),
            $jenis . '|' . $nisn . '|nama:' . strtolower($nama),
        ];
        foreach ($candidates as $key) {
            if (isset($index[$key])) {
                return $index[$key];
            }
        }
        return null;
    }

    private function mapelLabel(string $kode): string
    {
        // Alias penulisan kode dari rapor
        $aliases = [
            'SEJL' => 'SejL',
            'SEJ_L' => 'SejL',
        ];
        $kode = $aliases[strtoupper($kode)] ?? $kode;
        return UjianStore::MAPEL[$kode] ?? $kode;
    }

    private function mapelShort(string $kode): string
    {
        return self::MAPEL_SHORT[$kode] ?? $kode;
    }

    /** Kode untuk kunci kelompok (APHP/DKV/TB → satu kode). */
    private function mapelGroupKode(string $kode): string
    {
        return self::MAPEL_EQUIVALEN[$kode] ?? $kode;
    }

    private function mapelGroupShort(string $kode): string
    {
        if ($kode === 'APHP_DKV_TB') {
            return self::MAPEL_SHORT['APHP_DKV_TB'];
        }
        return $this->mapelShort($kode);
    }

    private function mapelGroupNama(string $kode, string $namaAsli): string
    {
        if ($kode === 'APHP_DKV_TB') {
            return 'APHP / DKV / Tata Busana';
        }
        return $namaAsli !== '' ? $namaAsli : $this->mapelLabel($kode);
    }

    /** @param list<string> $keys */
    private function sortedMapelKeys(array $keys): array
    {
        $order = array_flip(self::MAPEL_ORDER);
        usort($keys, static function ($a, $b) use ($order) {
            $ia = $order[$a] ?? 1000;
            $ib = $order[$b] ?? 1000;
            if ($ia === $ib) {
                return strcasecmp($a, $b);
            }
            return $ia <=> $ib;
        });
        return $keys;
    }

    private function saveBobotDb(array $bobot): void
    {
        try {
            $pdo = Config::pdo();
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS rdm_settings (
                    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    setting_value LONGTEXT NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $stmt = $pdo->prepare(
                'INSERT INTO rdm_settings (setting_key, setting_value, updated_at)
                 VALUES ("ijazah_bobot", :v, NOW())
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=VALUES(updated_at)'
            );
            $stmt->execute([':v' => json_encode($bobot, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable) {
            // optional
        }
    }
}
