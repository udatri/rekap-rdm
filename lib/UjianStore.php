<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Penyimpanan ujian praktek & teori — hanya nilai akhir.
 */
final class UjianStore
{
    public const JENIS_PRAKTEK = 'praktek';
    public const JENIS_TEORI = 'teori';

    /** Template jenis ujian */
    public const TEMPLATES = [
        self::JENIS_PRAKTEK => [
            'nama' => 'Ujian Praktek',
            'warna' => '#0f5c45',
            'warna_soft' => '#e7f3ec',
        ],
        self::JENIS_TEORI => [
            'nama' => 'Ujian Teori',
            'warna' => '#1d4f91',
            'warna_soft' => '#e8f0fa',
        ],
    ];

    /** Daftar mata pelajaran untuk template (semua mapel) */
    public const MAPEL = [
        'QH' => 'Al-Qur\'an Hadis',
        'AA' => 'Akidah Akhlak',
        'FIK' => 'Fikih',
        'SKI' => 'Sejarah Kebudayaan Islam',
        'BAR' => 'Bahasa Arab',
        'PP' => 'Pendidikan Pancasila',
        'BINDO' => 'Bahasa Indonesia',
        'MTK' => 'Matematika',
        'IPAT' => 'IPAS / IPA Terapan',
        'IPST' => 'IPS Terapan',
        'BING' => 'Bahasa Inggris',
        'PJOK' => 'PJOK',
        'SEJ' => 'Sejarah',
        'SejL' => 'Sejarah Lanjut',
        'SB' => 'Seni Budaya',
        'INFO' => 'Informatika',
        'INFOP' => 'Informatika Peminatan',
        'Jawa' => 'Bahasa Jawa',
        'Tahfi' => 'Tahfidz',
        'APHP' => 'Agribisnis Pengolahan Hasil Pertanian',
        'DKV' => 'Desain Komunikasi Visual',
        'SOS' => 'Sosiologi',
        'EKO' => 'Ekonomi',
        'GEO' => 'Geografi',
        'BIO' => 'Biologi',
        'KIM' => 'Kimia',
        'FIS' => 'Fisika',
        'MTL' => 'Matematika Lanjut',
        'ABAR' => 'Bahasa Arab Lanjut',
        'BARTL' => 'Bahasa Inggris Lanjut',
        'IHad' => 'Ilmu Hadis',
        'ITaf' => 'Ilmu Tafsir',
        'UFiq' => 'Ushul Fikih',
        'TB' => 'Tata Busana',
        'riset' => 'Riset',
    ];

    private ?PDO $pdo = null;
    private bool $useJson = false;

    public function __construct(?string $jsonPath = null)
    {
        $this->jsonPath = $jsonPath ?? (Config::dataDir() . '/ujian.json');
    }

    private string $jsonPath;

    public function templates(): array
    {
        return [
            'jenis' => self::TEMPLATES,
            'mapel' => self::MAPEL,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $jenis = null): array
    {
        $items = $this->all();
        if ($jenis !== null && $jenis !== '') {
            $items = array_values(array_filter(
                $items,
                static fn ($u) => ($u['jenis'] ?? '') === $jenis
            ));
        }
        usort($items, static function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return $items;
    }

    public function get(string $id): ?array
    {
        foreach ($this->all() as $item) {
            if (($item['id'] ?? '') === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Cari sesi yang sama (jenis + kelas + mapel + tahun + semester).
     */
    public function findSession(
        string $jenis,
        string $kelas,
        string $mapel,
        string $tahunAjaran = '',
        string $semester = ''
    ): ?array {
        $jenis = trim($jenis);
        $kelas = trim($kelas);
        $mapel = trim($mapel);
        $tahunAjaran = trim($tahunAjaran);
        $semester = trim($semester);

        foreach ($this->all() as $item) {
            if (($item['jenis'] ?? '') !== $jenis) {
                continue;
            }
            if (strcasecmp((string) ($item['kelas'] ?? ''), $kelas) !== 0) {
                continue;
            }
            if (strcasecmp((string) ($item['mapel'] ?? ''), $mapel) !== 0) {
                continue;
            }
            if ($tahunAjaran !== '' && (string) ($item['tahun_ajaran'] ?? '') !== $tahunAjaran) {
                continue;
            }
            if ($semester !== '' && strcasecmp((string) ($item['semester'] ?? ''), $semester) !== 0) {
                continue;
            }
            return $item;
        }
        return null;
    }

    public function create(array $input): array
    {
        $jenis = trim((string) ($input['jenis'] ?? ''));
        if (!isset(self::TEMPLATES[$jenis])) {
            throw new InvalidArgumentException('Jenis ujian harus praktek atau teori.');
        }

        $mapelKode = trim((string) ($input['mapel'] ?? ''));
        $mapelNama = trim((string) ($input['mapel_nama'] ?? ''));
        if ($mapelNama === '' && $mapelKode !== '') {
            $mapelNama = self::MAPEL[$mapelKode] ?? $mapelKode;
        }
        if ($mapelKode === '' && $mapelNama === '') {
            throw new InvalidArgumentException('Mata pelajaran wajib dipilih.');
        }

        $judul = trim((string) ($input['judul'] ?? ''));
        if ($judul === '') {
            $judul = self::TEMPLATES[$jenis]['nama'] . ' — ' . ($mapelNama !== '' ? $mapelNama : $mapelKode);
        }

        $kelas = trim((string) ($input['kelas'] ?? ''));
        if ($kelas === '') {
            throw new InvalidArgumentException('Kelas wajib diisi.');
        }

        $tahunAjaran = trim((string) ($input['tahun_ajaran'] ?? ''));
        if ($tahunAjaran !== '' && !preg_match('/^\d{4}\/\d{4}$/', $tahunAjaran)) {
            throw new InvalidArgumentException('Format tahun ajaran harus seperti 2025/2026.');
        }

        $siswa = [];
        if (!empty($input['siswa']) && is_array($input['siswa'])) {
            foreach ($input['siswa'] as $s) {
                $siswa[] = $this->normalizeSiswaRow($s);
            }
        }

        $tpl = self::TEMPLATES[$jenis];
        $now = date('c');
        $row = [
            'id' => bin2hex(random_bytes(8)),
            'jenis' => $jenis,
            'judul' => $judul,
            'mapel' => $mapelKode !== '' ? $mapelKode : $mapelNama,
            'mapel_nama' => $mapelNama !== '' ? $mapelNama : $mapelKode,
            'kelas' => $kelas,
            'tahun_ajaran' => $tahunAjaran,
            'semester' => trim((string) ($input['semester'] ?? '')),
            'tanggal' => trim((string) ($input['tanggal'] ?? date('Y-m-d'))),
            'penguji' => trim((string) ($input['penguji'] ?? '')),
            'keterangan' => trim((string) ($input['keterangan'] ?? '')),
            'warna' => $tpl['warna'],
            'warna_soft' => $tpl['warna_soft'],
            'siswa' => $siswa,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->upsert($row, true);
        return $row;
    }

    public function update(string $id, array $input): array
    {
        $current = $this->get($id);
        if ($current === null) {
            throw new InvalidArgumentException('Data ujian tidak ditemukan.');
        }

        foreach (['judul', 'mapel', 'mapel_nama', 'kelas', 'tahun_ajaran', 'semester', 'tanggal', 'penguji', 'keterangan'] as $field) {
            if (array_key_exists($field, $input)) {
                $current[$field] = trim((string) $input[$field]);
            }
        }
        if (isset($input['siswa']) && is_array($input['siswa'])) {
            $siswa = [];
            foreach ($input['siswa'] as $s) {
                $siswa[] = $this->normalizeSiswaRow($s);
            }
            $current['siswa'] = $siswa;
        }
        $current['updated_at'] = date('c');
        $this->upsert($current, false);
        return $current;
    }

    public function delete(string $id): void
    {
        if ($this->preferJson()) {
            $data = $this->readJson();
            $before = count($data['ujian']);
            $data['ujian'] = array_values(array_filter(
                $data['ujian'],
                static fn ($u) => ($u['id'] ?? '') !== $id
            ));
            if (count($data['ujian']) === $before) {
                throw new InvalidArgumentException('Data ujian tidak ditemukan.');
            }
            $this->writeJson($data);
            return;
        }

        try {
            $this->ensureTable();
            $stmt = $this->db()->prepare('DELETE FROM rdm_ujian WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('Data ujian tidak ditemukan.');
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            $this->useJson = true;
            $this->delete($id);
        }
    }

    public function saveNilai(string $id, array $siswaRows): array
    {
        return $this->update($id, ['siswa' => $siswaRows]);
    }

    /** @return list<array<string,mixed>> */
    private function all(): array
    {
        if ($this->preferJson()) {
            return $this->readJson()['ujian'];
        }
        try {
            $this->ensureTable();
            $stmt = $this->db()->query('SELECT payload FROM rdm_ujian ORDER BY updated_at DESC');
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode((string) $row['payload'], true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
            return $out;
        } catch (Throwable) {
            $this->useJson = true;
            return $this->readJson()['ujian'];
        }
    }

    private function upsert(array $row, bool $isNew): void
    {
        if ($this->preferJson()) {
            $data = $this->readJson();
            if ($isNew) {
                $data['ujian'][] = $row;
            } else {
                foreach ($data['ujian'] as $i => $item) {
                    if (($item['id'] ?? '') === $row['id']) {
                        $data['ujian'][$i] = $row;
                        break;
                    }
                }
            }
            $this->writeJson($data);
            return;
        }

        try {
            $this->ensureTable();
            $payload = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($isNew) {
                $stmt = $this->db()->prepare(
                    'INSERT INTO rdm_ujian (id, jenis, kelas, tahun_ajaran, semester, judul, updated_at, payload)
                     VALUES (:id, :jenis, :kelas, :tahun_ajaran, :semester, :judul, :updated_at, :payload)'
                );
            } else {
                $stmt = $this->db()->prepare(
                    'UPDATE rdm_ujian
                     SET jenis=:jenis, kelas=:kelas, tahun_ajaran=:tahun_ajaran, semester=:semester,
                         judul=:judul, updated_at=:updated_at, payload=:payload
                     WHERE id=:id'
                );
            }
            $stmt->execute([
                ':id' => $row['id'],
                ':jenis' => $row['jenis'],
                ':kelas' => $row['kelas'],
                ':tahun_ajaran' => $row['tahun_ajaran'],
                ':semester' => $row['semester'],
                ':judul' => $row['judul'],
                ':updated_at' => date('Y-m-d H:i:s'),
                ':payload' => $payload,
            ]);
        } catch (Throwable) {
            $this->useJson = true;
            $this->upsert($row, $isNew);
        }
    }

    private function preferJson(): bool
    {
        return $this->useJson;
    }

    private function db(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        $this->pdo = Config::pdo();
        return $this->pdo;
    }

    private function ensureTable(): void
    {
        $this->db()->exec(
            'CREATE TABLE IF NOT EXISTS rdm_ujian (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                jenis VARCHAR(20) NOT NULL,
                kelas VARCHAR(40) NOT NULL,
                tahun_ajaran VARCHAR(9) NOT NULL DEFAULT \'\',
                semester VARCHAR(20) NOT NULL DEFAULT \'\',
                judul VARCHAR(160) NOT NULL,
                updated_at DATETIME NOT NULL,
                payload LONGTEXT NOT NULL,
                KEY idx_rdm_ujian_jenis (jenis),
                KEY idx_rdm_ujian_kelas (kelas)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function readJson(): array
    {
        if (!is_readable($this->jsonPath)) {
            return ['ujian' => []];
        }
        $json = json_decode(file_get_contents($this->jsonPath) ?: '', true);
        if (!is_array($json) || !isset($json['ujian']) || !is_array($json['ujian'])) {
            return ['ujian' => []];
        }
        return $json;
    }

    private function writeJson(array $data): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->jsonPath . '.tmp';
        $ok = @file_put_contents(
            $tmp,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        if ($ok === false) {
            throw new RuntimeException('Gagal menyimpan data ujian. Pastikan MySQL berjalan atau folder data/ dapat ditulis.');
        }
        rename($tmp, $this->jsonPath);
    }

    private function normalizeSiswaRow(array $s): array
    {
        $nilaiAkhir = $s['nilai_akhir'] ?? null;
        if ($nilaiAkhir === '' || $nilaiAkhir === null) {
            $nilaiAkhir = null;
        } elseif (is_numeric($nilaiAkhir)) {
            $nilaiAkhir = round((float) $nilaiAkhir, 2);
        } else {
            $nilaiAkhir = null;
        }

        return [
            'nis' => trim((string) ($s['nis'] ?? '')),
            'nisn' => trim((string) ($s['nisn'] ?? '')),
            'nama' => trim((string) ($s['nama'] ?? '')),
            'jk' => trim((string) ($s['jk'] ?? '')),
            'nilai_akhir' => $nilaiAkhir,
            'catatan' => trim((string) ($s['catatan'] ?? '')),
        ];
    }
}
