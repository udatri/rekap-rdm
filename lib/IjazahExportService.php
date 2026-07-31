<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SekolahStore.php';
require_once __DIR__ . '/KktpStore.php';

/**
 * Export nilai ijazah ke Excel SpreadsheetML (.xls) dengan template visual.
 */
final class IjazahExportService
{
    private const WARNA = '#7A3E12';
    private const WARNA_SOFT = '#F3E4D2';
    private const WARNA_CREAM = '#FFF8F0';
    private const HIGH = '#0F766E';
    private const HIGH_BG = '#CCFBF1';
    private const MID = '#9A3412';
    private const MID_BG = '#FFEDD5';
    private const LOW = '#9F1239';
    private const LOW_BG = '#FFE4E6';

    /**
     * @param array $rekap hasil IjazahService::rekap()
     * @param array{kelas?:string,tahun_ajaran?:string,semester?:string} $filter
     */
    public function buildXls(array $rekap, array $filter = []): string
    {
        if (($rekap['mode'] ?? '') === 'detail') {
            return $this->buildDetailWorkbook($rekap, $filter);
        }
        return $this->buildDaftarWorkbook($rekap, $filter);
    }

    public function filename(array $rekap, array $filter = []): string
    {
        $parts = ['nilai-ijazah'];
        $kelas = trim((string) ($filter['kelas'] ?? ''));
        if ($kelas !== '') {
            $parts[] = preg_replace('/[^\w.\-]+/u', '-', $kelas) ?: 'kelas';
        }
        if (($rekap['mode'] ?? '') === 'detail') {
            $nisn = (string) ($rekap['siswa']['nisn'] ?? 'siswa');
            $parts[] = $nisn;
        }
        $parts[] = date('Ymd-His');
        return implode('_', $parts) . '.xls';
    }

    private function buildDaftarWorkbook(array $rekap, array $filter): string
    {
        $madrasah = (string) ((new SekolahStore())->active()['nama'] ?? '')
            ?: (string) Config::get('madrasah', 'MAN 4 Sleman');
        $bobot = $rekap['bobot'] ?? ['rataan' => 60, 'praktek' => 20, 'teori' => 20];
        $angkatanList = $rekap['angkatan'] ?? [];
        if ($angkatanList === [] && !empty($rekap['kelompok'])) {
            $angkatanList = [[
                'tahun' => '',
                'label' => 'Semua siswa',
                'total_siswa' => (int) ($rekap['total_siswa'] ?? 0),
                'total_kelompok' => count($rekap['kelompok']),
                'kelompok' => $rekap['kelompok'],
            ]];
        }

        $esc = $this->escaper();
        $cell = $this->cellFn($esc);
        $xml = $this->workbookHeader();
        $xml .= $this->stylesXml($esc);

        // —— Sheet Ringkasan ——
        $xml .= '<Worksheet ss:Name="Ringkasan">' . "\n";
        $xml .= '<Table ss:DefaultRowHeight="18">' . "\n";
        foreach ([220, 90, 90, 90, 110] as $w) {
            $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . $w . '"/>' . "\n";
        }

        $xml .= '<Row ss:Height="36">' . $cell('NILAI IJAZAH', 'Title', 4) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="22">' . $cell($madrasah, 'Subtitle', 4) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        $filterLabel = $this->filterLabel($filter);
        $xml .= '<Row ss:Height="20">'
            . $cell('Filter', 'MetaLabel')
            . $cell($filterLabel, 'MetaValue', 3)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Bobot', 'MetaLabel')
            . $cell(sprintf(
                'Rapor %s%% · Praktek %s%% · Teori %s%%',
                $bobot['rataan'] ?? 60,
                $bobot['praktek'] ?? 20,
                $bobot['teori'] ?? 20
            ), 'MetaValue', 3)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Diekspor', 'MetaLabel')
            . $cell(date('d/m/Y H:i'), 'MetaValue', 3)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        $xml .= '<Row ss:Height="22">'
            . $cell('Angkatan', 'Head')
            . $cell('Siswa', 'HeadCenter')
            . $cell('Kelompok', 'HeadCenter')
            . $cell('Keterangan', 'Head', 1)
            . '</Row>' . "\n";

        $ri = 0;
        foreach ($angkatanList as $a) {
            $style = $ri % 2 ? 'DataAlt' : 'Data';
            $styleC = $ri % 2 ? 'DataAltCenter' : 'DataCenter';
            $xml .= '<Row ss:Height="20">'
                . $cell((string) ($a['label'] ?? ''), $style)
                . $cell((string) ($a['total_siswa'] ?? 0), $styleC, null, 'Number')
                . $cell((string) ($a['total_kelompok'] ?? 0), $styleC, null, 'Number')
                . $cell('1 NISN = 1 baris · APHP/DKV/TB disamakan', $style, 1)
                . '</Row>' . "\n";
            $ri++;
        }

        $xml .= '<Row ss:Height="10"><Cell/></Row>' . "\n";
        $xml .= '<Row ss:Height="20">' . $cell('Legenda nilai', 'Section', 4) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Font hitam', 'ScoreCukup')
            . $cell('Nilai ≥ KKTP (dibulatkan)', 'Data', 3)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Font merah', 'ScoreLow')
            . $cell('Nilai di bawah KKTP', 'Data', 3)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Latar putih/biru/hijau', 'ScoreBaik')
            . $cell('Hanya jika ujian teori sudah ada (Cukup / Baik / Sangat Baik)', 'Data', 3)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Latar kuning tipis', 'ScorePending')
            . $cell('Teori belum ada (nilai sementara)', 'Data', 3)
            . '</Row>' . "\n";

        $xml .= '<Row ss:Height="10"><Cell/></Row>' . "\n";
        $xml .= '<Row ss:Height="18">'
            . $cell(
                'Total: ' . (int) ($rekap['total_siswa'] ?? 0) . ' siswa · '
                . (int) ($rekap['total_kelompok'] ?? 0) . ' kelompok · '
                . (int) ($rekap['total_angkatan'] ?? count($angkatanList)) . ' angkatan',
                'Hint',
                4
            )
            . '</Row>' . "\n";

        $xml .= '</Table>' . "\n";
        $xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup>'
            . '<Layout x:Orientation="Landscape"/><Header x:Margin="0.2"/><Footer x:Margin="0.2"/>'
            . '</PageSetup><FitToPage/><Print><FitHeight>0</FitHeight></Print></WorksheetOptions>' . "\n";
        $xml .= '</Worksheet>' . "\n";

        // —— Sheet per angkatan ——
        $usedNames = ['Ringkasan' => true];
        foreach ($angkatanList as $a) {
            $sheetName = $this->uniqueSheetName((string) ($a['label'] ?? 'Angkatan'), $usedNames);
            $xml .= $this->angkatanSheetXml(
                $a,
                $bobot,
                $filter,
                $madrasah,
                $sheetName,
                $cell,
                $esc,
                $this->kktpMap($rekap['kktp'] ?? [])
            );
        }

        $xml .= '</Workbook>';
        return $xml;
    }

    private function buildDetailWorkbook(array $rekap, array $filter): string
    {
        $s = $rekap['siswa'] ?? null;
        if (!is_array($s)) {
            throw new InvalidArgumentException('Data siswa tidak ditemukan.');
        }
        $madrasah = (string) ((new SekolahStore())->active()['nama'] ?? '')
            ?: (string) Config::get('madrasah', 'MAN 4 Sleman');
        $bobot = $rekap['bobot'] ?? $s['bobot'] ?? ['rataan' => 60, 'praktek' => 20, 'teori' => 20];
        $esc = $this->escaper();
        $cell = $this->cellFn($esc);
        $xml = $this->workbookHeader();
        $xml .= $this->stylesXml($esc);

        $xml .= '<Worksheet ss:Name="Detail siswa">' . "\n";
        $xml .= '<Table ss:DefaultRowHeight="18">' . "\n";
        foreach ([36, 72, 160, 56, 56, 56, 56, 56, 200] as $w) {
            $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . $w . '"/>' . "\n";
        }

        $xml .= '<Row ss:Height="36">' . $cell('DETAIL NILAI IJAZAH', 'Title', 8) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="22">' . $cell($madrasah . ' · ' . ($s['nama'] ?? ''), 'Subtitle', 8) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        $metaRows = [
            ['NISN', (string) ($s['nisn'] ?? '')],
            ['Nama', (string) ($s['nama'] ?? '')],
            ['JK', (string) ($s['jk'] ?? '')],
            ['Kelas', (string) ($s['kelas_akhir'] ?? '')],
            ['Rata rapor', $this->numStr($s['ringkasan']['rata_rataan'] ?? null)],
            ['Rata praktek', $this->numStr($s['ringkasan']['rata_praktek'] ?? null)],
            ['Rata teori', $this->numStr($s['ringkasan']['rata_teori'] ?? null)],
            ['Rata ijazah', $this->numStr($s['ringkasan']['rata_ijazah'] ?? null, 0)],
            ['Bobot', sprintf(
                'Rapor %s%% · Praktek %s%% · Teori %s%%',
                $bobot['rataan'] ?? 60,
                $bobot['praktek'] ?? 20,
                $bobot['teori'] ?? 20
            )],
        ];
        foreach ($metaRows as [$lab, $val]) {
            $xml .= '<Row ss:Height="20">'
                . $cell($lab, 'MetaLabel')
                . $cell($val, 'MetaValue', 7)
                . '</Row>' . "\n";
        }

        $xml .= '<Row ss:Height="10"><Cell/></Row>' . "\n";
        $xml .= '<Row ss:Height="22">'
            . $cell('#', 'HeadCenter')
            . $cell('Kode', 'HeadCenter')
            . $cell('Mata pelajaran', 'Head')
            . $cell('Rataan', 'HeadCenter')
            . $cell('Praktek', 'HeadCenter')
            . $cell('Teori', 'HeadCenter')
            . $cell('Ijazah', 'HeadCenter')
            . $cell('Smt', 'HeadCenter')
            . $cell('Keterangan', 'Head')
            . '</Row>' . "\n";

        foreach (array_values($s['mapel'] ?? []) as $i => $m) {
            $alt = $i % 2 === 1;
            $d = $alt ? 'DataAlt' : 'Data';
            $dc = $alt ? 'DataAltCenter' : 'DataCenter';
            $kelas = (string) ($s['kelas_akhir'] ?? '');
            $kktp = $this->kktpForKelas($kelas, $this->kktpMap($rekap['kktp'] ?? []));
            $hasTeori = ($m['ujian_teori'] ?? null) !== null && $m['ujian_teori'] !== '';
            $xml .= '<Row ss:Height="20">'
                . $cell((string) ($i + 1), $dc, null, 'Number')
                . $cell((string) ($m['kode'] ?? ''), $dc)
                . $cell((string) ($m['nama'] ?? ''), $d)
                . $this->scoreCellXml($cell, $m['rataan'] ?? null, $alt, false, false, $kktp, 1, false)
                . $this->scoreCellXml($cell, $m['ujian_praktek'] ?? null, $alt, false, false, $kktp, 1, false)
                . $this->scoreCellXml($cell, $m['ujian_teori'] ?? null, $alt, false, false, $kktp, 1, false)
                . $this->scoreCellXml($cell, $m['nilai_ijazah'] ?? null, $alt, true, false, $kktp, 0, $hasTeori)
                . $cell((string) ($m['semester_count'] ?? 0), $dc, null, 'Number')
                . $cell((string) ($m['keterangan'] ?? ''), $d)
                . '</Row>' . "\n";
        }

        $xml .= '</Table></Worksheet></Workbook>';
        return $xml;
    }

    /**
     * @param callable $cell
     * @param callable $esc
     */
    private function angkatanSheetXml(
        array $a,
        array $bobot,
        array $filter,
        string $madrasah,
        string $sheetName,
        callable $cell,
        callable $esc,
        array $kktpMap = []
    ): string {
        $kelompok = $a['kelompok'] ?? [];
        $maxCols = 5; // # NISN Nama Kelas Rata
        foreach ($kelompok as $g) {
            $maxCols = max($maxCols, 4 + count($g['mapel'] ?? []) + 1);
        }
        $merge = max(0, $maxCols - 1);

        $xml = '<Worksheet ss:Name="' . $esc($sheetName) . '">' . "\n";
        $xml .= '<Table ss:DefaultRowHeight="18">' . "\n";

        // Column widths
        $widths = [32, 78, 150, 72];
        $maxMapel = 0;
        foreach ($kelompok as $g) {
            $maxMapel = max($maxMapel, count($g['mapel'] ?? []));
        }
        for ($i = 0; $i < $maxMapel; $i++) {
            $widths[] = 52;
        }
        $widths[] = 52; // rata
        while (count($widths) < $maxCols) {
            $widths[] = 48;
        }
        foreach (array_slice($widths, 0, $maxCols) as $w) {
            $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . $w . '"/>' . "\n";
        }

        $xml .= '<Row ss:Height="34">'
            . $cell((string) ($a['label'] ?? 'Angkatan'), 'Title', $merge)
            . '</Row>' . "\n";
        $sub = $madrasah
            . ' · ' . (int) ($a['total_siswa'] ?? 0) . ' siswa'
            . ' · ' . (int) ($a['total_kelompok'] ?? 0) . ' kelompok'
            . ' · Bobot rapor ' . ($bobot['rataan'] ?? 60) . '%';
        $xml .= '<Row ss:Height="20">' . $cell($sub, 'Subtitle', $merge) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="18">'
            . $cell('Filter: ' . $this->filterLabel($filter) . ' · Diekspor ' . date('d/m/Y H:i'), 'Hint', $merge)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        foreach (array_values($kelompok) as $gi => $g) {
            $mapelCols = $g['mapel'] ?? [];
            $colCount = 4 + count($mapelCols) + 1;
            $gMerge = max(0, $colCount - 1);

            $xml .= '<Row ss:Height="24">'
                . $cell(sprintf(
                    'Kelompok %d · %d mapel · %d siswa',
                    $gi + 1,
                    (int) ($g['mapel_count'] ?? count($mapelCols)),
                    (int) ($g['total_siswa'] ?? 0)
                ), 'Section', $gMerge)
                . '</Row>' . "\n";

            // Header
            $head = '<Row ss:Height="28">'
                . $cell('#', 'HeadCenter')
                . $cell('NISN', 'Head')
                . $cell('Nama siswa', 'Head')
                . $cell('Kelas', 'Head');
            foreach ($mapelCols as $m) {
                $short = (string) ($m['short'] ?? $m['kode'] ?? '');
                $head .= $cell($short, 'HeadMapel');
            }
            $head .= $cell('Rata', 'HeadCenter') . '</Row>' . "\n";
            $xml .= $head;

            // Subheader nama mapel (opsional singkat di row kedua - skip for compactness)

            foreach (array_values($g['siswa'] ?? []) as $si => $s) {
                $alt = $si % 2 === 1;
                $d = $alt ? 'DataAlt' : 'Data';
                $dc = $alt ? 'DataAltCenter' : 'DataCenter';
                $nm = $s['nilai_mapel'] ?? [];
                $ht = $s['has_teori_mapel'] ?? [];
                $kktp = $this->kktpForKelas((string) ($s['kelas_akhir'] ?? ''), $kktpMap);
                $row = '<Row ss:Height="20">'
                    . $cell((string) ($s['rank'] ?? ($si + 1)), $dc, null, 'Number')
                    . $cell((string) ($s['nisn'] ?? ''), $dc)
                    . $cell((string) ($s['nama'] ?? ''), $d)
                    . $cell((string) ($s['kelas_akhir'] ?? ''), $dc);
                foreach ($mapelCols as $m) {
                    $kode = (string) ($m['kode'] ?? '');
                    $hasTeori = ($ht[$kode] ?? false) === true;
                    $row .= $this->scoreCellXml($cell, $nm[$kode] ?? null, $alt, false, false, $kktp, 0, $hasTeori);
                }
                $row .= $this->scoreCellXml($cell, $s['rata_ijazah'] ?? null, $alt, true, false, $kktp, 0, true);
                $row .= '</Row>' . "\n";
                $xml .= $row;
            }

            // Footer rata mapel
            $rataMapel = $g['rata_mapel'] ?? [];
            $foot = '<Row ss:Height="22">'
                . $cell('Rata-rata kelompok', 'FootLabel', 2)
                . $cell('', 'Foot');
            foreach ($mapelCols as $m) {
                $kode = (string) ($m['kode'] ?? '');
                $foot .= $this->scoreCellXml($cell, $rataMapel[$kode] ?? null, false, false, true, null, 0);
            }
            $avgIjazah = null;
            $vals = [];
            foreach ($g['siswa'] ?? [] as $s) {
                if (($s['rata_ijazah'] ?? null) !== null && is_numeric($s['rata_ijazah'])) {
                    $vals[] = (float) $s['rata_ijazah'];
                }
            }
            if ($vals !== []) {
                $avgIjazah = (float) round(array_sum($vals) / count($vals), 0);
            }
            $foot .= $this->scoreCellXml($cell, $avgIjazah, false, true, true, null, 0);
            $foot .= '</Row>' . "\n";
            $xml .= $foot;
            $xml .= '<Row ss:Height="12"><Cell/></Row>' . "\n";
        }

        $xml .= '</Table>' . "\n";
        $xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup>'
            . '<Layout x:Orientation="Landscape"/><Header x:Margin="0.2"/><Footer x:Margin="0.2"/>'
            . '</PageSetup><FitToPage/><Print><FitHeight>0</FitHeight></Print>'
            . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal>'
            . '<TopRowBottomPane>3</TopRowBottomPane><ActivePane>2</ActivePane>'
            . '</WorksheetOptions>' . "\n";
        $xml .= '</Worksheet>' . "\n";
        return $xml;
    }

    /** @param callable $cell */
    private function scoreCellXml(
        callable $cell,
        mixed $v,
        bool $alt = false,
        bool $bold = false,
        bool $foot = false,
        ?float $kktp = null,
        int $decimals = 1,
        bool $hasTeori = false
    ): string {
        if ($v === null || $v === '' || !is_numeric($v)) {
            $style = $foot ? 'FootEmpty' : ($alt ? 'ScoreEmptyAlt' : 'ScoreEmpty');
            return $cell('—', $style);
        }
        $n = round((float) $v, $decimals);

        // Font hitam; merah hanya di bawah KKTP. Latar interval hanya jika teori sudah ada.
        if ($kktp !== null && $n < $kktp) {
            $style = $foot
                ? 'FootLow'
                : ($bold ? ($alt ? 'ScoreLowAltBold' : 'ScoreLowBold') : ($alt ? 'ScoreLowAlt' : 'ScoreLow'));
        } elseif ($hasTeori && $kktp !== null) {
            $band = KktpStore::predikatBand($n, $kktp);
            if ($band === 'sangat_baik') {
                $style = $bold
                    ? ($alt ? 'ScoreHighAltBold' : 'ScoreHighBold')
                    : ($alt ? 'ScoreHighAlt' : 'ScoreHigh');
            } elseif ($band === 'baik') {
                $style = $bold
                    ? ($alt ? 'ScoreBaikAltBold' : 'ScoreBaikBold')
                    : ($alt ? 'ScoreBaikAlt' : 'ScoreBaik');
            } else {
                $style = $bold
                    ? ($alt ? 'ScoreCukupAltBold' : 'ScoreCukupBold')
                    : ($alt ? 'ScoreCukupAlt' : 'ScoreCukup');
            }
            if ($foot) {
                $style = 'Foot';
            }
        } elseif ($hasTeori === false && !$foot && $decimals === 0) {
            // Mapel ijazah tanpa teori: kuning tipis, font hitam
            $style = $bold
                ? ($alt ? 'ScorePendingAltBold' : 'ScorePendingBold')
                : ($alt ? 'ScorePendingAlt' : 'ScorePending');
        } else {
            $style = $foot
                ? 'Foot'
                : ($bold
                    ? ($alt ? 'ScorePlainAltBold' : 'ScorePlainBold')
                    : ($alt ? 'ScorePlainAlt' : 'ScorePlain'));
        }

        return $cell($this->numStr($n, $decimals), $style, null, 'Number');
    }

    private function numStr(mixed $v, int $decimals = 1): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '—';
        }
        return number_format((float) $v, $decimals, '.', '');
    }

    /** @param array<string,mixed> $kktp */
    private function kktpMap(array $kktp): array
    {
        $map = [];
        foreach ($kktp['tingkat'] ?? [] as $t) {
            $kode = strtoupper(trim((string) ($t['kode'] ?? '')));
            if ($kode !== '' && isset($t['nilai']) && is_numeric($t['nilai'])) {
                $map[$kode] = (float) $t['nilai'];
            }
        }
        return $map;
    }

    /** @param array<string,float> $kktpMap */
    private function kktpForKelas(string $kelas, array $kktpMap): ?float
    {
        if ($kelas === '' || $kktpMap === []) {
            return null;
        }
        $tingkat = KktpStore::parseTingkat($kelas);
        if ($tingkat !== null && isset($kktpMap[$tingkat])) {
            return $kktpMap[$tingkat];
        }
        $key = strtoupper(trim($kelas));
        return $kktpMap[$key] ?? null;
    }

    private function filterLabel(array $filter): string
    {
        $parts = [];
        $kelas = trim((string) ($filter['kelas'] ?? ''));
        if ($kelas !== '') {
            if (str_starts_with($kelas, 'tingkat:')) {
                $parts[] = 'Tingkat ' . substr($kelas, 8);
            } else {
                $parts[] = 'Kelas ' . $kelas;
            }
        }
        $ta = trim((string) ($filter['tahun_ajaran'] ?? ''));
        if ($ta !== '') {
            $parts[] = $ta;
        }
        $sem = trim((string) ($filter['semester'] ?? ''));
        if ($sem !== '') {
            $parts[] = $sem;
        }
        return $parts === [] ? 'Semua data' : implode(' · ', $parts);
    }

    private function uniqueSheetName(string $label, array &$used): string
    {
        $base = preg_replace('/[\\\\\/\*\?\:\[\]]+/', '-', $label) ?? 'Sheet';
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? 'Sheet');
        $base = trim($base, "- \t");
        if ($base === '') {
            $base = 'Sheet';
        }
        $base = mb_substr($base, 0, 28);
        $name = $base;
        $i = 2;
        while (isset($used[$name])) {
            $suffix = ' (' . $i . ')';
            $name = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
            $i++;
        }
        $used[$name] = true;
        return $name;
    }

    private function escaper(): callable
    {
        return static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function cellFn(callable $esc): callable
    {
        return static function (
            string $value,
            string $style = 'Default',
            ?int $mergeAcross = null,
            string $type = 'String'
        ) use ($esc): string {
            $attr = ' ss:StyleID="' . $esc($style) . '"';
            if ($mergeAcross !== null && $mergeAcross > 0) {
                $attr .= ' ss:MergeAcross="' . $mergeAcross . '"';
            }
            if ($type === 'Number' && $value !== '' && is_numeric($value)) {
                return '<Cell' . $attr . '><Data ss:Type="Number">' . $esc($value) . '</Data></Cell>';
            }
            return '<Cell' . $attr . '><Data ss:Type="String">' . $esc($value) . '</Data></Cell>';
        };
    }

    private function workbookHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?mso-application progid="Excel.Sheet"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n"
            . '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">'
            . '<Title>Nilai Ijazah</Title><Author>Rekap RDM</Author>'
            . '<Created>' . date('c') . '</Created></DocumentProperties>' . "\n";
    }

    private function stylesXml(callable $esc): string
    {
        $w = $esc(self::WARNA);
        $ws = $esc(self::WARNA_SOFT);
        $cream = $esc(self::WARNA_CREAM);
        $high = $esc(self::HIGH);
        $highBg = $esc(self::HIGH_BG);
        $mid = $esc(self::MID);
        $midBg = $esc(self::MID_BG);
        $low = $esc(self::LOW);
        $lowBg = $esc(self::LOW_BG);

        $border = static function (string $color = '#E8D2B8') use ($esc): string {
            $c = $esc($color);
            return '<Borders>'
                . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="' . $c . '"/>'
                . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="' . $c . '"/>'
                . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="' . $c . '"/>'
                . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="' . $c . '"/>'
                . '</Borders>';
        };

        $score = static function (
            string $id,
            string $fg,
            string $bg,
            bool $bold = false,
            string $numFmt = '0.0'
        ) use ($border, $esc): string {
            $b = $bold ? ' ss:Bold="1"' : '';
            return '<Style ss:ID="' . $esc($id) . '">'
                . '<Font ss:FontName="Calibri" ss:Size="10"' . $b . ' ss:Color="' . $esc($fg) . '"/>'
                . '<Interior ss:Color="' . $esc($bg) . '" ss:Pattern="Solid"/>'
                . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
                . $border('#D6C4B0')
                . '<NumberFormat ss:Format="' . $esc($numFmt) . '"/>'
                . '</Style>' . "\n";
        };

        $xml = '<Styles>' . "\n";
        $xml .= '<Style ss:ID="Default"><Font ss:FontName="Calibri" ss:Size="11"/><Alignment ss:Vertical="Center"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Title"><Font ss:FontName="Calibri" ss:Size="18" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $w . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Subtitle"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="' . $w . '"/>'
            . '<Interior ss:Color="' . $ws . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Section"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="#A65C2A" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="MetaLabel"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $w . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="MetaValue"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#3D2A1A"/>'
            . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . $border() . '</Style>' . "\n";
        $xml .= '<Style ss:ID="Hint"><Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#8A6A4A"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Head"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $w . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . $border('#FFFFFF') . '</Style>' . "\n";
        $xml .= '<Style ss:ID="HeadCenter" ss:Parent="Head"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>' . "\n";
        $xml .= '<Style ss:ID="HeadMapel"><Font ss:FontName="Calibri" ss:Size="8" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $w . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>'
            . $border('#FFFFFF') . '</Style>' . "\n";
        $xml .= '<Style ss:ID="Data"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#111111"/>'
            . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . $border() . '</Style>' . "\n";
        $xml .= '<Style ss:ID="DataCenter" ss:Parent="Data"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>' . "\n";
        $xml .= '<Style ss:ID="DataAlt" ss:Parent="Data"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '<Style ss:ID="DataAltCenter" ss:Parent="DataCenter"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";

        $black = '#111111';
        // Cukup: putih · Baik: biru tipis · Sangat Baik: hijau tipis · Belum: merah · Pending teori: kuning tipis
        $xml .= $score('ScoreCukup', $black, '#FFFFFF', false, '0');
        $xml .= $score('ScoreCukupAlt', $black, '#FFFFFF', false, '0');
        $xml .= $score('ScoreCukupBold', $black, '#FFFFFF', true, '0');
        $xml .= $score('ScoreCukupAltBold', $black, '#FFFFFF', true, '0');
        $xml .= $score('ScoreBaik', $black, '#DBEAFE', false, '0');
        $xml .= $score('ScoreBaikAlt', $black, '#BFDBFE', false, '0');
        $xml .= $score('ScoreBaikBold', $black, '#DBEAFE', true, '0');
        $xml .= $score('ScoreBaikAltBold', $black, '#BFDBFE', true, '0');
        $xml .= $score('ScoreHigh', $black, '#CCFBF1', false, '0');
        $xml .= $score('ScoreHighAlt', $black, '#99F6E4', false, '0');
        $xml .= $score('ScoreHighBold', $black, '#CCFBF1', true, '0');
        $xml .= $score('ScoreHighAltBold', $black, '#99F6E4', true, '0');
        $xml .= $score('ScorePending', $black, '#FEF9C3', false, '0');
        $xml .= $score('ScorePendingAlt', $black, '#FEF08A', false, '0');
        $xml .= $score('ScorePendingBold', $black, '#FEF9C3', true, '0');
        $xml .= $score('ScorePendingAltBold', $black, '#FEF08A', true, '0');
        $xml .= $score('ScorePlain', $black, '#FFFFFF', false, '0');
        $xml .= $score('ScorePlainAlt', $black, $cream, false, '0');
        $xml .= $score('ScorePlainBold', $black, '#FFFFFF', true, '0');
        $xml .= $score('ScorePlainAltBold', $black, $cream, true, '0');
        $xml .= $score('ScoreLow', self::LOW, self::LOW_BG, false, '0');
        $xml .= $score('ScoreLowAlt', self::LOW, '#FECDD3', false, '0');
        $xml .= $score('ScoreLowBold', self::LOW, self::LOW_BG, true, '0');
        $xml .= $score('ScoreLowAltBold', self::LOW, '#FECDD3', true, '0');
        $xml .= '<Style ss:ID="ScoreEmpty"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#A89888"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border() . '</Style>' . "\n";
        $xml .= '<Style ss:ID="ScoreEmptyAlt" ss:Parent="ScoreEmpty"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";

        $xml .= '<Style ss:ID="Foot"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#111111"/>'
            . '<Interior ss:Color="' . $ws . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border()
            . '<NumberFormat ss:Format="0"/></Style>' . "\n";
        $xml .= '<Style ss:ID="FootLabel" ss:Parent="Foot"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= $score('FootLow', self::LOW, self::LOW_BG, true, '0');
        $xml .= '<Style ss:ID="FootEmpty"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#A89888"/>'
            . '<Interior ss:Color="' . $ws . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border() . '</Style>' . "\n";

        $xml .= '</Styles>' . "\n";
        return $xml;
    }
}
