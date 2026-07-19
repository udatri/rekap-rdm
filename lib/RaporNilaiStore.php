<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/UjianStore.php';

/**
 * Penyimpanan nilai rapor komponen: sumatif & PAS.
 */
final class RaporNilaiStore
{
    public const JENIS_SUMATIF = 'sumatif';
    public const JENIS_PAS = 'pas';

    public const JENIS_LABEL = [
        self::JENIS_SUMATIF => 'Nilai Sumatif',
        self::JENIS_PAS => 'Nilai PAS',
    ];

    private string $jsonPath;

    public function __construct(?string $jsonPath = null)
    {
        $this->jsonPath = $jsonPath ?? (Config::dataDir() . '/rapor_nilai.json');
    }

    public function mapelList(): array
    {
        return UjianStore::MAPEL;
    }

    /** @return list<array> */
    public function list(?string $jenis = null): array
    {
        $out = [];
        foreach ($this->read()['entries'] as $e) {
            if ($jenis !== null && $jenis !== '' && ($e['jenis'] ?? '') !== $jenis) {
                continue;
            }
            $out[] = $this->publicEntry($e);
        }
        usort($out, static function ($a, $b) {
            $ta = (string) ($a['tahun_ajaran'] ?? '');
            $tb = (string) ($b['tahun_ajaran'] ?? '');
            if ($ta !== $tb) {
                return strcmp($tb, $ta);
            }
            return strcmp((string) ($a['kelas'] ?? ''), (string) ($b['kelas'] ?? ''));
        });
        return $out;
    }

    public function get(string $id): ?array
    {
        foreach ($this->read()['entries'] as $e) {
            if (($e['id'] ?? '') === $id) {
                return $this->publicEntry($e);
            }
        }
        return null;
    }

    /**
     * @param array{
     *   jenis?:string,kelas?:string,tahun_ajaran?:string,semester?:string,
     *   mapel?:string,tanggal?:string,keterangan?:string,siswa?:list<array>
     * } $input
     */
    public function create(array $input): array
    {
        $jenis = strtolower(trim((string) ($input['jenis'] ?? '')));
        if (!isset(self::JENIS_LABEL[$jenis])) {
            throw new InvalidArgumentException('Jenis nilai tidak valid (sumatif/pas).');
        }
        $kelas = trim((string) ($input['kelas'] ?? ''));
        $mapel = trim((string) ($input['mapel'] ?? ''));
        if ($kelas === '') {
            throw new InvalidArgumentException('Kelas wajib diisi.');
        }
        if ($mapel === '' || !isset(UjianStore::MAPEL[$mapel])) {
            throw new InvalidArgumentException('Mata pelajaran tidak valid.');
        }

        $siswa = [];
        foreach ($input['siswa'] ?? [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $siswa[] = [
                'nisn' => trim((string) ($s['nisn'] ?? $s['id'] ?? '')),
                'nis' => trim((string) ($s['nis'] ?? '')),
                'nama' => trim((string) ($s['nama'] ?? '')),
                'nilai' => $this->normalizeNilai($s['nilai'] ?? null),
            ];
        }

        $row = [
            'id' => bin2hex(random_bytes(8)),
            'jenis' => $jenis,
            'kelas' => $kelas,
            'tahun_ajaran' => trim((string) ($input['tahun_ajaran'] ?? '')),
            'semester' => trim((string) ($input['semester'] ?? '')),
            'mapel' => $mapel,
            'mapel_nama' => UjianStore::MAPEL[$mapel],
            'tanggal' => trim((string) ($input['tanggal'] ?? date('Y-m-d'))),
            'keterangan' => trim((string) ($input['keterangan'] ?? '')),
            'siswa' => $siswa,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $state = $this->read();
        $state['entries'][] = $row;
        $this->write($state);
        return $this->publicEntry($row);
    }

    /** @param array{siswa?:list<array>,keterangan?:string,tanggal?:string,semester?:string,tahun_ajaran?:string} $input */
    public function update(string $id, array $input): array
    {
        $state = $this->read();
        $found = false;
        foreach ($state['entries'] as &$e) {
            if (($e['id'] ?? '') !== $id) {
                continue;
            }
            if (isset($input['tanggal'])) {
                $e['tanggal'] = trim((string) $input['tanggal']);
            }
            if (isset($input['keterangan'])) {
                $e['keterangan'] = trim((string) $input['keterangan']);
            }
            if (isset($input['semester'])) {
                $e['semester'] = trim((string) $input['semester']);
            }
            if (isset($input['tahun_ajaran'])) {
                $e['tahun_ajaran'] = trim((string) $input['tahun_ajaran']);
            }
            if (isset($input['siswa']) && is_array($input['siswa'])) {
                $siswa = [];
                foreach ($input['siswa'] as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $siswa[] = [
                        'nisn' => trim((string) ($s['nisn'] ?? $s['id'] ?? '')),
                        'nis' => trim((string) ($s['nis'] ?? '')),
                        'nama' => trim((string) ($s['nama'] ?? '')),
                        'nilai' => $this->normalizeNilai($s['nilai'] ?? null),
                    ];
                }
                $e['siswa'] = $siswa;
            }
            $e['updated_at'] = date('c');
            $found = true;
            $saved = $e;
            break;
        }
        unset($e);
        if (!$found) {
            throw new InvalidArgumentException('Data nilai rapor tidak ditemukan.');
        }
        $this->write($state);
        return $this->publicEntry($saved);
    }

    public function delete(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('ID wajib.');
        }
        $state = $this->read();
        $before = count($state['entries']);
        $state['entries'] = array_values(array_filter(
            $state['entries'],
            static fn ($e) => ($e['id'] ?? '') !== $id
        ));
        if (count($state['entries']) === $before) {
            throw new InvalidArgumentException('Data nilai rapor tidak ditemukan.');
        }
        $this->write($state);
    }

    private function normalizeNilai(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $n = (float) $v;
        if ($n < 0 || $n > 100) {
            throw new InvalidArgumentException('Nilai harus antara 0–100.');
        }
        return round($n, 2);
    }

    private function publicEntry(array $e): array
    {
        $siswa = $e['siswa'] ?? [];
        $nilaiList = [];
        foreach ($siswa as $s) {
            if (isset($s['nilai']) && $s['nilai'] !== null && $s['nilai'] !== '') {
                $nilaiList[] = (float) $s['nilai'];
            }
        }
        $avg = $nilaiList !== [] ? round(array_sum($nilaiList) / count($nilaiList), 2) : null;
        return [
            'id' => (string) ($e['id'] ?? ''),
            'jenis' => (string) ($e['jenis'] ?? ''),
            'jenis_label' => self::JENIS_LABEL[$e['jenis'] ?? ''] ?? (string) ($e['jenis'] ?? ''),
            'kelas' => (string) ($e['kelas'] ?? ''),
            'tahun_ajaran' => (string) ($e['tahun_ajaran'] ?? ''),
            'semester' => (string) ($e['semester'] ?? ''),
            'mapel' => (string) ($e['mapel'] ?? ''),
            'mapel_nama' => (string) ($e['mapel_nama'] ?? (UjianStore::MAPEL[$e['mapel'] ?? ''] ?? $e['mapel'] ?? '')),
            'tanggal' => (string) ($e['tanggal'] ?? ''),
            'keterangan' => (string) ($e['keterangan'] ?? ''),
            'siswa' => array_values($siswa),
            'siswa_count' => count($siswa),
            'terisi' => count($nilaiList),
            'rata_rata' => $avg,
            'created_at' => (string) ($e['created_at'] ?? ''),
            'updated_at' => (string) ($e['updated_at'] ?? ''),
        ];
    }

    /** @return array{entries:list<array>} */
    private function read(): array
    {
        if (!is_readable($this->jsonPath)) {
            return ['entries' => []];
        }
        $json = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($json)) {
            return ['entries' => []];
        }
        $entries = [];
        foreach ($json['entries'] ?? [] as $e) {
            if (!is_array($e) || trim((string) ($e['id'] ?? '')) === '') {
                continue;
            }
            $entries[] = $e;
        }
        return ['entries' => $entries];
    }

    /** @param array{entries:list<array>} $state */
    private function write(array $state): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $ok = @file_put_contents(
            $this->jsonPath,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        if ($ok === false) {
            throw new RuntimeException('Gagal menyimpan nilai rapor. Pastikan folder data/ writable.');
        }
    }
}
