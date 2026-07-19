<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Master kelas — MySQL jika tersedia, fallback ke JSON.
 */
final class KelasStore
{
    private ?PDO $pdo = null;
    private bool $useJson = false;
    private string $jsonPath;

    public function __construct(?string $jsonPath = null)
    {
        $this->jsonPath = $jsonPath ?? (Config::dataDir() . '/kelas.json');
        // Data per sekolah selalu JSON terpisah (bukan tabel MySQL bersama)
        if (str_contains($this->jsonPath, '/schools/') || str_contains($this->jsonPath, '\\schools\\')) {
            $this->useJson = true;
        }
    }

    /** @return list<array{id:string,nama:string,tingkat:string,tahun_ajaran:string,keterangan:string,sumber:string,created_at:string}> */
    public function all(): array
    {
        if ($this->preferJson()) {
            return $this->allJson();
        }
        try {
            $this->ensureTable();
            $stmt = $this->db()->query(
                'SELECT id, nama, tingkat, tahun_ajaran, keterangan, created_at
                 FROM rdm_kelas ORDER BY nama ASC'
            );
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'id' => (string) $row['id'],
                    'nama' => (string) $row['nama'],
                    'tingkat' => (string) ($row['tingkat'] ?? ''),
                    'tahun_ajaran' => (string) ($row['tahun_ajaran'] ?? ''),
                    'keterangan' => (string) ($row['keterangan'] ?? ''),
                    'sumber' => 'manual',
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
            return $rows;
        } catch (Throwable) {
            $this->useJson = true;
            return $this->allJson();
        }
    }

    /**
     * @param list<string> $fromExcel
     * @return list<array{nama:string,tingkat:string,tahun_ajaran:string,keterangan:string,sumber:string,id:?string,bisa_hapus:bool}>
     */
    public function merged(array $fromExcel): array
    {
        $map = [];

        foreach ($fromExcel as $nama) {
            $nama = trim((string) $nama);
            if ($nama === '') {
                continue;
            }
            $key = $this->normalizeKey($nama);
            $map[$key] = [
                'id' => null,
                'nama' => $nama,
                'tingkat' => $this->detectTingkat($nama),
                'tahun_ajaran' => '',
                'keterangan' => 'Dari file Excel',
                'sumber' => 'excel',
                'bisa_hapus' => false,
            ];
        }

        foreach ($this->all() as $row) {
            $key = $this->normalizeKey($row['nama']);
            if (isset($map[$key]) && $map[$key]['sumber'] === 'excel') {
                $map[$key]['tahun_ajaran'] = $row['tahun_ajaran'] ?: $map[$key]['tahun_ajaran'];
                $map[$key]['keterangan'] = $row['keterangan'] ?: $map[$key]['keterangan'];
                continue;
            }
            $map[$key] = [
                'id' => $row['id'],
                'nama' => $row['nama'],
                'tingkat' => $row['tingkat'] !== '' ? $row['tingkat'] : $this->detectTingkat($row['nama']),
                'tahun_ajaran' => $row['tahun_ajaran'] ?? '',
                'keterangan' => $row['keterangan'] ?? '',
                'sumber' => 'manual',
                'bisa_hapus' => true,
            ];
        }

        $list = array_values($map);
        usort($list, static fn ($a, $b) => strnatcasecmp($a['nama'], $b['nama']));
        return $list;
    }

    public function add(string $nama, string $tingkat = '', string $tahunAjaran = '', string $keterangan = ''): array
    {
        $nama = trim($nama);
        if ($nama === '') {
            throw new InvalidArgumentException('Nama kelas wajib diisi.');
        }
        if (!preg_match('/^[A-Za-z0-9.\-\s]+$/u', $nama)) {
            throw new InvalidArgumentException('Nama kelas hanya boleh huruf, angka, titik, strip, dan spasi.');
        }
        if (mb_strlen($nama) > 40) {
            throw new InvalidArgumentException('Nama kelas terlalu panjang (maks. 40 karakter).');
        }

        $tingkat = trim($tingkat);
        $tahunAjaran = trim($tahunAjaran);
        $keterangan = trim($keterangan);
        if ($tingkat === '') {
            $tingkat = $this->detectTingkat($nama);
        }
        if ($tahunAjaran !== '' && !preg_match('/^\d{4}\/\d{4}$/', $tahunAjaran)) {
            throw new InvalidArgumentException('Format tahun ajaran harus seperti 2025/2026.');
        }

        $row = [
            'id' => bin2hex(random_bytes(8)),
            'nama' => $nama,
            'tingkat' => $tingkat,
            'tahun_ajaran' => $tahunAjaran,
            'keterangan' => $keterangan,
            'sumber' => 'manual',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->preferJson()) {
            return $this->addJson($row);
        }

        try {
            $this->ensureTable();
            $check = $this->db()->prepare(
                "SELECT id FROM rdm_kelas WHERE LOWER(REPLACE(nama, ' ', '')) = ? LIMIT 1"
            );
            $check->execute([$this->normalizeKey($nama)]);
            if ($check->fetch()) {
                throw new InvalidArgumentException('Kelas "' . $nama . '" sudah ada.');
            }

            $stmt = $this->db()->prepare(
                'INSERT INTO rdm_kelas (id, nama, tingkat, tahun_ajaran, keterangan, created_at)
                 VALUES (:id, :nama, :tingkat, :tahun_ajaran, :keterangan, :created_at)'
            );
            $stmt->execute([
                ':id' => $row['id'],
                ':nama' => $row['nama'],
                ':tingkat' => $row['tingkat'],
                ':tahun_ajaran' => $row['tahun_ajaran'],
                ':keterangan' => $row['keterangan'],
                ':created_at' => $row['created_at'],
            ]);
            return $row;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            $this->useJson = true;
            return $this->addJson($row);
        }
    }

    public function delete(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('ID kelas tidak valid.');
        }

        if ($this->preferJson()) {
            $this->deleteJson($id);
            return;
        }

        try {
            $this->ensureTable();
            $stmt = $this->db()->prepare('DELETE FROM rdm_kelas WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('Kelas tidak ditemukan atau berasal dari Excel (tidak bisa dihapus).');
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            $this->useJson = true;
            $this->deleteJson($id);
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
            'CREATE TABLE IF NOT EXISTS rdm_kelas (
                id VARCHAR(32) NOT NULL PRIMARY KEY,
                nama VARCHAR(40) NOT NULL,
                tingkat VARCHAR(10) NOT NULL DEFAULT \'\',
                tahun_ajaran VARCHAR(9) NOT NULL DEFAULT \'\',
                keterangan VARCHAR(120) NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_rdm_kelas_nama (nama)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return list<array{id:string,nama:string,tingkat:string,tahun_ajaran:string,keterangan:string,sumber:string,created_at:string}> */
    private function allJson(): array
    {
        if (!is_readable($this->jsonPath)) {
            return [];
        }
        $data = json_decode(file_get_contents($this->jsonPath) ?: '', true);
        if (!is_array($data) || !isset($data['kelas']) || !is_array($data['kelas'])) {
            return [];
        }
        return array_values($data['kelas']);
    }

    private function saveJson(array $items): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $this->jsonPath . '.tmp';
        $ok = file_put_contents(
            $tmp,
            json_encode(['kelas' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        if ($ok === false) {
            throw new RuntimeException('Gagal menyimpan data kelas.');
        }
        rename($tmp, $this->jsonPath);
    }

    private function addJson(array $row): array
    {
        $items = $this->allJson();
        $key = $this->normalizeKey($row['nama']);
        foreach ($items as $item) {
            if ($this->normalizeKey($item['nama']) === $key) {
                throw new InvalidArgumentException('Kelas "' . $row['nama'] . '" sudah ada.');
            }
        }
        $items[] = $row;
        $this->saveJson($items);
        return $row;
    }

    private function deleteJson(string $id): void
    {
        $items = $this->allJson();
        $filtered = array_values(array_filter(
            $items,
            static fn ($r) => (string) ($r['id'] ?? '') !== $id
        ));
        if (count($filtered) === count($items)) {
            throw new InvalidArgumentException('Kelas tidak ditemukan atau berasal dari Excel (tidak bisa dihapus).');
        }
        $this->saveJson($filtered);
    }

    private function normalizeKey(string $nama): string
    {
        return strtolower(preg_replace('/\s+/', '', $nama) ?? $nama);
    }

    private function detectTingkat(string $nama): string
    {
        if (preg_match('/^(XII|XI|X)\b/i', trim($nama), $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }
}
