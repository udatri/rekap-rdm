<?php

declare(strict_types=1);

require_once __DIR__ . '/UjianStore.php';
require_once __DIR__ . '/KktpStore.php';

/**
 * Helper preview / cetak transkrip nilai ijazah (format Inggris).
 */
final class IjazahPreviewHelper
{
    /** @var array<string, string> */
    public const MAPEL_EN = [
        'QH' => "Al-Qur'an Hadis",
        'AA' => 'Moral Beliefs',
        'FIK' => 'Fikih',
        'SKI' => 'History of Islamic Culture',
        'PP' => 'Pancasila Education',
        'BINDO' => 'Indonesia',
        'MTK' => 'Math',
        'IPAT' => 'Natural Sciences',
        'IPST' => 'Social Sciences',
        'BING' => 'English',
        'BAR' => 'Arabic',
        'PJOK' => 'Physical Education, Sports and Health',
        'INFO' => 'Computer Science',
        'INFOP' => 'Computer Science',
        'SEJ' => 'History',
        'SejL' => 'Further History',
        'SB' => 'Arts and Crafts',
        'PRA' => 'Crafts',
        'Jawa' => 'Javanese',
        'Tahfi' => 'Tahfidz',
        'BTAQ' => 'Reading and Writing Al-Qur\'an',
        'P5' => 'Pancasila Student Profile Project',
        'KO' => 'Co-curricular',
        'riset' => 'Research',
        'SOS' => 'Sociology',
        'EKO' => 'Economy',
        'GEO' => 'Geography',
        'ANT' => 'Anthropology',
        'BIO' => 'Biology',
        'KIM' => 'Chemistry',
        'FIS' => 'Physics',
        'MTL' => 'Advanced Mathematics',
        'BIDTL' => 'Advanced Indonesian',
        'ABAR' => 'Advanced Arabic',
        'BARTL' => 'Advanced English',
        'BIGTL' => 'Advanced English',
        'BKOR' => 'Korean Language',
        'BMAND' => 'Mandarin Language',
        'BJEP' => 'Japanese Language',
        'IHad' => 'Hadith Studies',
        'ITaf' => 'Tafsir Studies',
        'UFiq' => 'Usul Fiqh',
        'APHP' => 'Agribusiness Processing',
        'DKV' => 'Visual Communication Design',
        'TB' => 'Fashion Design',
    ];

    /** @var list<string> */
    private const PAI_CODES = ['QH', 'AA', 'FIK', 'SKI'];

    /** @var list<string> */
    private const GENERAL_CODES = [
        'PP', 'BINDO', 'MTK', 'IPAT', 'IPST', 'BING', 'BAR', 'PJOK', 'INFO', 'SEJ', 'SB', 'PRA',
    ];

    /** @var list<string> */
    private const SELECTED_CODES = [
        'INFOP', 'SOS', 'EKO', 'GEO', 'SejL', 'ANT', 'BIO', 'KIM', 'FIS', 'MTL',
        'BIDTL', 'ABAR', 'BARTL', 'BIGTL', 'BKOR', 'BMAND', 'BJEP', 'IHad', 'ITaf', 'UFiq',
        'APHP', 'DKV', 'TB',
    ];

    /** @var list<string> */
    private const LOCAL_CODES = ['Jawa', 'Tahfi', 'riset', 'BTAQ', 'P5', 'KO'];

    /** @var list<string> */
    private const PAI_LETTERS = ['A', 'B', 'C', 'D'];

    /**
     * @param array<string, array{kode:string,nama:string,nilai_ijazah:?float}> $mapelByCode
     * @return list<array{title:string,rows:list<array{label:string,name:string,score:?int,score_words:string}>}>
     */
    public static function buildTranscriptGroups(array $mapelByCode): array
    {
        $groups = [];

        $generalRows = [];
        $paiScored = [];
        foreach (self::PAI_CODES as $code) {
            if (!isset($mapelByCode[$code])) {
                continue;
            }
            $score = self::roundScore($mapelByCode[$code]['nilai_ijazah'] ?? null);
            if ($score === null) {
                continue;
            }
            $paiScored[] = $code;
        }
        if ($paiScored !== []) {
            $generalRows[] = [
                'kind' => 'heading',
                'label' => '1',
                'name' => 'Islamic Religious Education',
                'score' => null,
                'score_words' => '',
            ];
            foreach ($paiScored as $i => $code) {
                $m = $mapelByCode[$code];
                $score = self::roundScore($m['nilai_ijazah'] ?? null);
                $generalRows[] = [
                    'kind' => 'sub',
                    'label' => self::PAI_LETTERS[$i] ?? (string) ($i + 1),
                    'name' => self::mapelEnglish($code, (string) ($m['nama'] ?? '')),
                    'score' => $score,
                    'score_words' => self::digitsToEnglish((int) $score),
                ];
            }
        }

        $num = $paiScored !== [] ? 2 : 1;
        foreach (self::GENERAL_CODES as $code) {
            if (!isset($mapelByCode[$code])) {
                continue;
            }
            $m = $mapelByCode[$code];
            $score = self::roundScore($m['nilai_ijazah'] ?? null);
            if ($score === null) {
                continue;
            }
            $generalRows[] = [
                'kind' => 'item',
                'label' => (string) $num,
                'name' => self::mapelEnglish($code, (string) ($m['nama'] ?? '')),
                'score' => $score,
                'score_words' => self::digitsToEnglish($score),
            ];
            $num++;
        }
        if ($generalRows !== []) {
            $groups[] = ['title' => 'General Subject Groups', 'rows' => $generalRows];
        }

        $selectedRows = [];
        $selNum = 1;
        foreach (self::SELECTED_CODES as $code) {
            if (!isset($mapelByCode[$code])) {
                continue;
            }
            $m = $mapelByCode[$code];
            $score = self::roundScore($m['nilai_ijazah'] ?? null);
            if ($score === null) {
                continue;
            }
            $selectedRows[] = [
                'kind' => 'item',
                'label' => (string) $selNum,
                'name' => self::mapelEnglish($code, (string) ($m['nama'] ?? '')),
                'score' => $score,
                'score_words' => self::digitsToEnglish($score),
            ];
            $selNum++;
        }
        foreach ($mapelByCode as $code => $m) {
            if (in_array($code, self::PAI_CODES, true)
                || in_array($code, self::GENERAL_CODES, true)
                || in_array($code, self::SELECTED_CODES, true)
                || in_array($code, self::LOCAL_CODES, true)
            ) {
                continue;
            }
            $score = self::roundScore($m['nilai_ijazah'] ?? null);
            if ($score === null) {
                continue;
            }
            $selectedRows[] = [
                'kind' => 'item',
                'label' => (string) $selNum,
                'name' => self::mapelEnglish($code, (string) ($m['nama'] ?? '')),
                'score' => $score,
                'score_words' => self::digitsToEnglish($score),
            ];
            $selNum++;
        }
        if ($selectedRows !== []) {
            $groups[] = ['title' => 'Selected Subject Groups', 'rows' => $selectedRows];
        }

        $localRows = [];
        $locNum = 1;
        foreach (self::LOCAL_CODES as $code) {
            if (!isset($mapelByCode[$code])) {
                continue;
            }
            $m = $mapelByCode[$code];
            $score = self::roundScore($m['nilai_ijazah'] ?? null);
            if ($score === null) {
                continue;
            }
            $localRows[] = [
                'kind' => 'item',
                'label' => (string) $locNum,
                'name' => self::mapelEnglish($code, (string) ($m['nama'] ?? '')),
                'score' => $score,
                'score_words' => self::digitsToEnglish($score),
            ];
            $locNum++;
        }
        if ($localRows !== []) {
            $groups[] = ['title' => 'Local Contents', 'rows' => $localRows];
        }

        return $groups;
    }

    public static function mapelEnglish(string $code, string $fallback = ''): string
    {
        $raw = trim($code);
        if ($raw !== '' && isset(self::MAPEL_EN[$raw])) {
            return self::MAPEL_EN[$raw];
        }
        $upper = strtoupper($raw);
        foreach (self::MAPEL_EN as $k => $label) {
            if (strtoupper((string) $k) === $upper) {
                return $label;
            }
        }
        if ($fallback !== '') {
            if (strcasecmp(trim($fallback), 'Bahasa Jawa') === 0) {
                return 'Javanese';
            }
            return $fallback;
        }
        foreach (UjianStore::MAPEL as $k => $nama) {
            if (strtoupper((string) $k) === $upper) {
                return self::mapelEnglish((string) $k, (string) $nama);
            }
        }
        return $raw;
    }

    public static function roundScore(mixed $v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        return (int) round((float) $v, 0);
    }

    public static function digitsToEnglish(int $n): string
    {
        $map = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];
        $out = [];
        foreach (str_split((string) abs($n)) as $d) {
            $out[] = $map[(int) $d] ?? $d;
        }
        return implode(' ', $out);
    }

    public static function averageToEnglish(float $avg): string
    {
        $formatted = number_format($avg, 2, '.', '');
        $parts = explode('.', $formatted);
        $words = [self::digitsToEnglish((int) $parts[0])];
        if (isset($parts[1])) {
            $words[] = 'Point';
            $words[] = self::digitsToEnglish((int) $parts[1]);
        }
        return implode(' ', $words);
    }

    public static function formatAverage(float $avg): string
    {
        return number_format($avg, 2, '.', '');
    }

    /** @param list<array{nilai_ijazah:?float}> $mapel */
    public static function computeAverage(array $mapel): ?float
    {
        $vals = [];
        foreach ($mapel as $m) {
            $s = self::roundScore($m['nilai_ijazah'] ?? null);
            if ($s !== null) {
                $vals[] = $s;
            }
        }
        if ($vals === []) {
            return null;
        }
        return round(array_sum($vals) / count($vals), 2);
    }

    public static function formatDateEnglish(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $months = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        $d = (int) date('j', $ts);
        $m = $months[(int) date('n', $ts)] ?? date('F', $ts);
        $y = date('Y', $ts);
        return sprintf('%02d %s %s', $d, $m, $y);
    }

    public static function formatDateEnglishLong(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $months = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        $d = (int) date('j', $ts);
        $m = $months[(int) date('n', $ts)] ?? date('F', $ts);
        $y = date('Y', $ts);
        return $m . ' ' . $d . ', ' . $y;
    }

    /**
     * Ambil tahun ajaran dari data Excel pada tingkat akhir siswa (XII / IX / VI, dll.).
     *
     * @param list<array{kelas?:string,tahun_ajaran?:string,semester_ke?:int|string,id?:string,nisn?:string}> $records
     */
    public static function schoolYearFromRecords(array $records, string $studentId, string $kelasAkhir = ''): string
    {
        $studentKeys = self::studentMatchKeys($studentId);
        $rows = array_values(array_filter(
            $records,
            static function ($r) use ($studentKeys) {
                foreach (self::studentMatchKeys((string) ($r['id'] ?? '')) as $k) {
                    if (isset($studentKeys[$k])) {
                        return true;
                    }
                }
                foreach (self::studentMatchKeys((string) ($r['nisn'] ?? '')) as $k) {
                    if (isset($studentKeys[$k])) {
                        return true;
                    }
                }
                return false;
            }
        ));
        if ($rows === []) {
            return self::defaultSchoolYear();
        }

        $finalTingkat = KktpStore::parseTingkat($kelasAkhir) ?? self::highestTingkatFromRows($rows);
        $years = [];
        if ($finalTingkat !== null) {
            foreach ($rows as $r) {
                $ta = trim((string) ($r['tahun_ajaran'] ?? ''));
                if ($ta === '') {
                    continue;
                }
                $kelas = (string) ($r['kelas'] ?? '');
                $tingkat = KktpStore::parseTingkat($kelas);
                if ($tingkat !== null && strtoupper($tingkat) === strtoupper($finalTingkat)) {
                    $years[$ta] = true;
                }
            }
        }

        if ($years === []) {
            foreach ($rows as $r) {
                $ta = trim((string) ($r['tahun_ajaran'] ?? ''));
                if ($ta !== '') {
                    $years[$ta] = true;
                }
            }
        }

        if ($years === []) {
            return self::defaultSchoolYear();
        }

        $pool = array_keys($years);
        rsort($pool, SORT_STRING);

        return $pool[0];
    }

    /** @return array<string, true> */
    private static function studentMatchKeys(string $raw): array
    {
        $keys = [];
        $id = trim($raw);
        if ($id !== '') {
            $keys[$id] = true;
        }
        $digits = preg_replace('/\D+/', '', $id) ?? '';
        if ($digits !== '') {
            $keys[$digits] = true;
            $stripped = ltrim($digits, '0');
            if ($stripped !== '') {
                $keys[$stripped] = true;
                $keys[str_pad($stripped, 10, '0', STR_PAD_LEFT)] = true;
            }
        }
        return $keys;
    }

    /**
     * @param list<array{kelas?:string}> $rows
     */
    private static function highestTingkatFromRows(array $rows): ?string
    {
        $order = [
            'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6,
            'VII' => 7, 'VIII' => 8, 'IX' => 9,
            'X' => 10, 'XI' => 11, 'XII' => 12,
        ];
        $best = null;
        $bestOrd = -1;
        foreach ($rows as $r) {
            $t = KktpStore::parseTingkat((string) ($r['kelas'] ?? ''));
            if ($t === null) {
                continue;
            }
            $ord = $order[strtoupper($t)] ?? 0;
            if ($ord > $bestOrd) {
                $bestOrd = $ord;
                $best = strtoupper($t);
            }
        }
        return $best;
    }

    private static function defaultSchoolYear(): string
    {
        $year = (int) date('Y');
        return $year . '/' . ($year + 1);
    }

    public static function madrasahLevelLabel(string $schoolName): string
    {
        $u = strtoupper($schoolName);
        if (str_contains($u, ' MI ') || str_starts_with($u, 'MI ') || str_contains($u, 'MIS ')) {
            return 'MADRASAH IBTIDAIYAH';
        }
        if (str_contains($u, ' MT') || str_contains($u, 'MTS')) {
            return 'MADRASAH TSANAWIYAH';
        }
        return 'MADRASAH ALIYAH';
    }

    /**
     * Judul ijazah Inggris: SMA/MA = HIGH, SMP/MTs = SECONDARY.
     */
    public static function certificateTitleEnglish(string $schoolName): string
    {
        return self::isJuniorSecondarySchool($schoolName)
            ? 'SENIOR SECONDARY SCHOOL CERTIFICATE'
            : 'SENIOR HIGH SCHOOL CERTIFICATE';
    }

    public static function isJuniorSecondarySchool(string $schoolName): bool
    {
        $u = strtoupper(preg_replace('/\s+/', ' ', trim($schoolName)) ?? $schoolName);
        if (str_contains($u, 'TSANAWIYAH')) {
            return true;
        }
        if (preg_match('/\b(SMP|SMPS|SMPN|MTSN|MTSS|MTS)\b/', $u) === 1) {
            return true;
        }
        return false;
    }

    public static function placeholder(string $value, string $fallback = '……………………………'): string
    {
        $v = trim($value);
        return $v !== '' ? $v : $fallback;
    }
}
