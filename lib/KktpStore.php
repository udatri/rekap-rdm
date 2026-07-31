<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Security.php';

/**
 * KKTP (Kriteria Ketercapaian Tujuan Pembelajaran) per tingkat.
 * Tingkat ditampilkan sesuai jenjang yang terdeteksi dari nama kelas Excel:
 * SD I–VI, SMP VII–IX, SMA/MA X–XII.
 */
final class KktpStore
{
    public const DEFAULT_NILAI = 75.0;

    /** @var list<string> */
    public const TINGKAT_SD = ['I', 'II', 'III', 'IV', 'V', 'VI'];

    /** @var list<string> */
    public const TINGKAT_SMP = ['VII', 'VIII', 'IX'];

    /** @var list<string> */
    public const TINGKAT_SMA = ['X', 'XI', 'XII'];

    /** @var array<string, int> nomor urut untuk sorting */
    private const ORDER = [
        'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6,
        'VII' => 7, 'VIII' => 8, 'IX' => 9,
        'X' => 10, 'XI' => 11, 'XII' => 12,
    ];

    private string $jsonPath;

    public function __construct(?string $jsonPath = null)
    {
        $this->jsonPath = $jsonPath ?? (Config::dataDir() . '/kktp_settings.json');
    }

    /**
     * @param list<string> $kelasFromExcel
     * @return array{
     *   jenjang:list<string>,
     *   tingkat:list<array{kode:string,label:string,nilai:float}>,
     *   default:float,
     *   updated_at:?string
     * }
     */
    public function getForKelas(array $kelasFromExcel): array
    {
        $state = $this->read();
        $jenjang = $this->detectJenjang($kelasFromExcel);
        $tingkatKode = $this->tingkatForJenjang($jenjang);

        $rows = [];
        foreach ($tingkatKode as $kode) {
            $nilai = $state['nilai'][$kode] ?? self::DEFAULT_NILAI;
            $rows[] = [
                'kode' => $kode,
                'label' => 'Tingkat ' . $kode,
                'nilai' => round((float) $nilai, 2),
            ];
        }

        return [
            'jenjang' => $jenjang,
            'tingkat' => $rows,
            'default' => self::DEFAULT_NILAI,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input nilai per tingkat, mis. { "X": 75, "XI": 80 }
     * @param list<string> $kelasFromExcel
     * @return array{jenjang:list<string>,tingkat:list<array{kode:string,label:string,nilai:float}>,default:float,updated_at:?string}
     */
    public function save(array $input, array $kelasFromExcel): array
    {
        $jenjang = $this->detectJenjang($kelasFromExcel);
        $allowed = $this->tingkatForJenjang($jenjang);
        $allowedSet = array_fill_keys($allowed, true);

        $state = $this->read();
        $nilaiMap = is_array($state['nilai'] ?? null) ? $state['nilai'] : [];

        $raw = $input['nilai'] ?? $input;
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Data KKTP tidak valid.');
        }

        foreach ($raw as $kode => $v) {
            $kode = strtoupper(trim((string) $kode));
            if ($kode === '' || !isset($allowedSet[$kode])) {
                continue;
            }
            if ($v === null || $v === '') {
                $nilaiMap[$kode] = self::DEFAULT_NILAI;
                continue;
            }
            if (!is_numeric($v)) {
                throw new InvalidArgumentException("Nilai KKTP tingkat {$kode} harus angka.");
            }
            $n = (float) $v;
            if ($n < 0 || $n > 100) {
                throw new InvalidArgumentException("Nilai KKTP tingkat {$kode} harus antara 0–100.");
            }
            $nilaiMap[$kode] = round($n, 2);
        }

        // Pastikan semua tingkat jenjang punya nilai (default 75)
        foreach ($allowed as $kode) {
            if (!isset($nilaiMap[$kode]) || !is_numeric($nilaiMap[$kode])) {
                $nilaiMap[$kode] = self::DEFAULT_NILAI;
            }
        }

        $state = [
            'nilai' => $nilaiMap,
            'updated_at' => date('c'),
        ];
        $this->write($state);

        return $this->getForKelas($kelasFromExcel);
    }

    /**
     * Interval predikat mengikuti rumus RDM dari nilai batas KKTP.
     * Contoh KKTP 75 → Belum 0–74 · Cukup 75–82 · Baik 83–91 · Sangat Baik 92–100.
     *
     * @return array{
     *   kktp:float,
     *   belum:array{min:float,max:float,label:string},
     *   cukup:array{min:float,max:float,label:string},
     *   baik:array{min:float,max:float,label:string},
     *   sangat_baik:array{min:float,max:float,label:string}
     * }
     */
    public static function intervals(float $kktp): array
    {
        $kktp = round(max(0.0, min(100.0, $kktp)), 2);
        if ($kktp >= 100.0) {
            return [
                'kktp' => 100.0,
                'belum' => ['min' => 0.0, 'max' => 99.99, 'label' => 'Belum Tercapai'],
                'cukup' => ['min' => 100.0, 'max' => 100.0, 'label' => 'Cukup'],
                'baik' => ['min' => 100.0, 'max' => 100.0, 'label' => 'Baik'],
                'sangat_baik' => ['min' => 100.0, 'max' => 100.0, 'label' => 'Sangat Baik'],
            ];
        }

        $span = 100.0 - $kktp;
        $cukupMax = $kktp + (float) (int) round($span / 3) - 1.0;
        $baikMax = $kktp + (float) (int) round(2 * $span / 3) - 1.0;

        $cukupMax = max($kktp, min(99.0, $cukupMax));
        $baikMax = max($cukupMax + 1.0, min(99.0, $baikMax));
        $sbMin = min(100.0, $baikMax + 1.0);

        // Tampilkan max sebagai bilangan bulat yang “terlihat” di UI RDM (74 bukan 74.99)
        $belumMax = $kktp > 0 ? (float) ((int) ceil($kktp) - 1) : 0.0;
        if ($belumMax < 0) {
            $belumMax = 0.0;
        }

        return [
            'kktp' => $kktp,
            'belum' => [
                'min' => 0.0,
                'max' => $belumMax,
                'label' => 'Belum Tercapai',
            ],
            'cukup' => [
                'min' => $kktp,
                'max' => $cukupMax,
                'label' => 'Cukup',
            ],
            'baik' => [
                'min' => $cukupMax + 1.0,
                'max' => $baikMax,
                'label' => 'Baik',
            ],
            'sangat_baik' => [
                'min' => $sbMin,
                'max' => 100.0,
                'label' => 'Sangat Baik',
            ],
        ];
    }

    /**
     * Klasifikasi nilai terhadap KKTP: belum|cukup|baik|sangat_baik
     */
    public static function predikatBand(float $nilai, float $kktp): string
    {
        if ($nilai < $kktp) {
            return 'belum';
        }
        $iv = self::intervals($kktp);
        if ($nilai <= (float) $iv['cukup']['max']) {
            return 'cukup';
        }
        if ($nilai <= (float) $iv['baik']['max']) {
            return 'baik';
        }
        return 'sangat_baik';
    }

    /**
     * Deteksi kode tingkat dari nama kelas (I–XII / 1–12).
     */
    public static function parseTingkat(string $kelas): ?string
    {
        $nama = trim($kelas);
        if ($nama === '') {
            return null;
        }

        // Roman (panjang dulu)
        if (preg_match('/^(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/i', $nama, $m)) {
            return strtoupper($m[1]);
        }

        // "Kelas 7", "Kelas VII", dll.
        if (preg_match('/\bkelas\s*(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I|\d{1,2})\b/i', $nama, $m)) {
            return self::normalizeTingkatToken($m[1]);
        }

        // Angka di awal: 10A, 7-A, 12 IPA
        if (preg_match('/^(\d{1,2})\b/', $nama, $m)) {
            return self::normalizeTingkatToken($m[1]);
        }

        return null;
    }

    /**
     * @param list<string> $kelasFromExcel
     * @return list<string> mis. ['SMA'] atau ['SD','SMP']
     */
    public function detectJenjang(array $kelasFromExcel): array
    {
        $found = [];
        foreach ($kelasFromExcel as $nama) {
            $t = self::parseTingkat((string) $nama);
            if ($t !== null) {
                $found[$t] = true;
            }
        }

        $jenjang = [];
        foreach (self::TINGKAT_SD as $t) {
            if (isset($found[$t])) {
                $jenjang[] = 'SD';
                break;
            }
        }
        foreach (self::TINGKAT_SMP as $t) {
            if (isset($found[$t])) {
                $jenjang[] = 'SMP';
                break;
            }
        }
        foreach (self::TINGKAT_SMA as $t) {
            if (isset($found[$t])) {
                $jenjang[] = 'SMA';
                break;
            }
        }

        // Default aplikasi (madrasah/SMA) jika belum ada kelas terdeteksi
        if ($jenjang === []) {
            $jenjang[] = 'SMA';
        }

        return $jenjang;
    }

    /**
     * @param list<string> $jenjang
     * @return list<string>
     */
    public function tingkatForJenjang(array $jenjang): array
    {
        $out = [];
        $set = array_fill_keys($jenjang, true);
        if (isset($set['SD'])) {
            foreach (self::TINGKAT_SD as $t) {
                $out[] = $t;
            }
        }
        if (isset($set['SMP'])) {
            foreach (self::TINGKAT_SMP as $t) {
                $out[] = $t;
            }
        }
        if (isset($set['SMA'])) {
            foreach (self::TINGKAT_SMA as $t) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function normalizeTingkatToken(string $token): ?string
    {
        $token = strtoupper(trim($token));
        if (isset(self::ORDER[$token])) {
            return $token;
        }
        if (!ctype_digit($token)) {
            return null;
        }
        $n = (int) $token;
        $map = [
            1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
            7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
        ];
        return $map[$n] ?? null;
    }

    /** @return array{nilai:array<string,float>,updated_at?:string} */
    private function read(): array
    {
        if (!is_readable($this->jsonPath)) {
            return ['nilai' => []];
        }
        $json = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($json)) {
            return ['nilai' => []];
        }
        $nilai = [];
        foreach (($json['nilai'] ?? []) as $k => $v) {
            $kode = strtoupper(trim((string) $k));
            if ($kode === '' || !is_numeric($v)) {
                continue;
            }
            $nilai[$kode] = round((float) $v, 2);
        }
        return [
            'nilai' => $nilai,
            'updated_at' => isset($json['updated_at']) ? (string) $json['updated_at'] : null,
        ];
    }

    /** @param array{nilai:array<string,float>,updated_at?:string} $state */
    private function write(array $state): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        Security::writeJsonFile($this->jsonPath, $state);
    }
}
