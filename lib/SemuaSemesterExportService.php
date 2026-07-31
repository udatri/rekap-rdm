<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SekolahStore.php';
require_once __DIR__ . '/KktpStore.php';

/**
 * Export rekap semua semester ke Excel SpreadsheetML (.xls) — judul berwarna, kolom menyesuaikan.
 */
final class SemuaSemesterExportService
{
    private const WARNA = '#0F5C45';
    private const WARNA_SOFT = '#D8EDE4';
    private const WARNA_MID = '#1A7A5C';
    private const CREAM = '#F4FBF8';
    private const HIGH = '#0F766E';
    private const HIGH_BG = '#CCFBF1';
    private const MID = '#9A3412';
    private const MID_BG = '#FFEDD5';
    private const LOW = '#9F1239';
    private const LOW_BG = '#FFE4E6';

    /**
     * @param array $rekap hasil RekapService::rekapSemuaSemester()
     * @param array{tahun_ajaran?:string,semester?:string,kelas?:string,id?:string} $filter
     */
    public function buildXls(array $rekap, array $filter = []): string
    {
        $madrasah = (string) ((new SekolahStore())->active()['nama'] ?? '')
            ?: (string) Config::get('madrasah', 'MAN 4 Sleman');

        $siswa = $rekap['siswa'] ?? [];
        usort($siswa, static function ($a, $b) {
            $ra = (int) ($a['rank_keseluruhan'] ?? 9999);
            $rb = (int) ($b['rank_keseluruhan'] ?? 9999);
            if ($ra === $rb) {
                return strcasecmp((string) ($a['nama'] ?? ''), (string) ($b['nama'] ?? ''));
            }
            return $ra <=> $rb;
        });

        $semKeys = [];
        foreach ($siswa as $s) {
            foreach ($s['semesters'] ?? [] as $sem) {
                $ke = (int) ($sem['semester_ke'] ?? 0);
                if ($ke > 0) {
                    $semKeys[$ke] = true;
                }
            }
        }
        $semKeys = array_keys($semKeys);
        sort($semKeys);

        $kktpMap = $this->kktpMap($rekap['kktp'] ?? []);
        $ringkasan = $rekap['ringkasan'] ?? [];

        $esc = $this->escaper();
        $cell = $this->cellFn($esc);

        $colCount = 5 + count($semKeys) + 2; // rank,nisn,nama,kelas,#sem + SEM* + total + rata
        $mergeTitle = max(0, $colCount - 1);

        $xml = $this->workbookHeader();
        $xml .= $this->stylesXml($esc);

        $xml .= '<Worksheet ss:Name="Rekap semua semester">' . "\n";
        $xml .= '<Table ss:DefaultRowHeight="18">' . "\n";

        // Lebar kolom menyesuaikan isi
        $widths = [42, 88, 168, 132, 42];
        foreach ($semKeys as $_) {
            $widths[] = 52;
        }
        $widths[] = 72;
        $widths[] = 62;
        foreach ($widths as $w) {
            $xml .= '<Column ss:AutoFitWidth="1" ss:Width="' . $w . '"/>' . "\n";
        }

        $xml .= '<Row ss:Height="36">' . $cell('RATA-RATA SEMUA SEMESTER (DIBULATKAN)', 'Title', $mergeTitle) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="22">' . $cell($madrasah, 'Subtitle', $mergeTitle) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        $xml .= '<Row ss:Height="20">'
            . $cell('Filter', 'MetaLabel')
            . $cell($this->filterLabel($filter), 'MetaValue', min(3, $mergeTitle - 1))
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="20">'
            . $cell('Jumlah siswa', 'MetaLabel')
            . $cell((string) count($siswa), 'MetaValue', null, 'Number')
            . $cell('Diekspor', 'MetaLabel')
            . $cell(date('d/m/Y H:i'), 'MetaValue')
            . '</Row>' . "\n";

        if ($ringkasan !== []) {
            $xml .= '<Row ss:Height="20">'
                . $cell('Rata-rata', 'MetaLabel')
                . $cell($this->fmtNum(isset($ringkasan['rata_avg']) ? round((float) $ringkasan['rata_avg']) : null, 0), 'MetaValue', null, 'Number')
                . $cell('Rata max / min', 'MetaLabel')
                . $cell(
                    $this->fmtNum(isset($ringkasan['rata_max']) ? round((float) $ringkasan['rata_max']) : null, 0)
                    . ' / '
                    . $this->fmtNum(isset($ringkasan['rata_min']) ? round((float) $ringkasan['rata_min']) : null, 0),
                    'MetaValue'
                )
                . '</Row>' . "\n";
        }

        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        // Header tabel berwarna
        $xml .= '<Row ss:Height="24">'
            . $cell('Rank', 'HeadCenter')
            . $cell('NISN', 'HeadCenter')
            . $cell('Nama', 'Head')
            . $cell('Kelas', 'Head')
            . $cell('#Sem', 'HeadCenter');
        foreach ($semKeys as $ke) {
            $xml .= $cell('SEM ' . $ke, 'HeadCenter');
        }
        $xml .= $cell('Total jumlah', 'HeadCenter')
            . $cell('Rata semua', 'HeadCenter')
            . '</Row>' . "\n";

        $ri = 0;
        foreach ($siswa as $s) {
            $alt = $ri % 2 === 1;
            $data = $alt ? 'DataAlt' : 'Data';
            $dataC = $alt ? 'DataAltCenter' : 'DataCenter';
            $map = [];
            foreach ($s['semesters'] ?? [] as $sem) {
                $map[(int) ($sem['semester_ke'] ?? 0)] = $sem;
            }
            $kelasLabel = (string) ($s['kelas'] ?? '');
            $kktp = $this->kktpForKelas($kelasLabel, $kktpMap);

            $xml .= '<Row ss:Height="20">'
                . $cell('#' . (string) ($s['rank_keseluruhan'] ?? ''), $alt ? 'RankAlt' : 'Rank')
                . $cell((string) ($s['nisn'] ?? ''), $dataC)
                . $cell((string) ($s['nama'] ?? ''), $data)
                . $cell($kelasLabel, $data)
                . $cell((string) ($s['semester_count'] ?? 0), $dataC, null, 'Number');

            foreach ($semKeys as $ke) {
                $row = $map[$ke] ?? null;
                $val = $row['rata_rata'] ?? null;
                $xml .= $this->scoreCell($cell, $val, $kktp, $alt, false);
            }

            $xml .= $this->scoreCell($cell, $s['total_jumlah'] ?? null, null, $alt, false, 0, true);
            $xml .= $this->scoreCell($cell, $s['rata_rata_semua'] ?? null, $kktp, $alt, true);
            $xml .= '</Row>' . "\n";
            $ri++;
        }

        if ($siswa === []) {
            $xml .= '<Row ss:Height="22">'
                . $cell('Tidak ada data untuk filter ini.', 'Hint', $mergeTitle)
                . '</Row>' . "\n";
        }

        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";
        $xml .= '<Row ss:Height="18">'
            . $cell(
                'Dihasilkan oleh Rekap RDM · font hitam (merah &lt; KKTP) · sel: putih Cukup · biru Baik · hijau Sangat Baik · mengikuti interval KKTP',
                'Hint',
                $mergeTitle
            )
            . '</Row>' . "\n";

        $xml .= '</Table>' . "\n";
        $xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<PageSetup><Layout x:Orientation="Landscape"/><Header x:Margin="0.3"/><Footer x:Margin="0.3"/>'
            . '<PageMargins x:Bottom="0.4" x:Left="0.4" x:Right="0.4" x:Top="0.5"/></PageSetup>'
            . '<FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><ValidPrinterInfo/>'
            . '<PaperSizeIndex>9</PaperSizeIndex><HorizontalResolution>600</HorizontalResolution>'
            . '<VerticalResolution>600</VerticalResolution></Print>'
            . '<Selected/><FreezePanes/><FrozenNoSplit/>'
            . '<SplitHorizontal>8</SplitHorizontal><TopRowBottomPane>8</TopRowBottomPane>'
            . '<SplitVertical>3</SplitVertical><LeftColumnRightPane>3</LeftColumnRightPane>'
            . '<ActivePane>0</ActivePane>'
            . '</WorksheetOptions>' . "\n";
        $xml .= '</Worksheet>' . "\n";
        $xml .= '</Workbook>';

        return $xml;
    }

    /**
     * @param array{tahun_ajaran?:string,semester?:string,kelas?:string,id?:string} $filter
     */
    public function filename(array $filter = []): string
    {
        $parts = ['rekap-semua-semester'];
        foreach (['tahun_ajaran', 'kelas', 'semester'] as $k) {
            $v = trim((string) ($filter[$k] ?? ''));
            if ($v !== '') {
                $parts[] = preg_replace('/[^\w.\-]+/u', '-', $v) ?: $k;
            }
        }
        $parts[] = date('Ymd-His');
        return implode('_', $parts) . '.xls';
    }

    /** @param array{tahun_ajaran?:string,semester?:string,kelas?:string,id?:string} $filter */
    private function filterLabel(array $filter): string
    {
        $bits = [];
        if (($filter['tahun_ajaran'] ?? '') !== '') {
            $bits[] = 'TA ' . $filter['tahun_ajaran'];
        }
        if (($filter['semester'] ?? '') !== '') {
            $bits[] = 'Semester ' . $filter['semester'];
        }
        if (($filter['kelas'] ?? '') !== '') {
            $bits[] = 'Kelas ' . $filter['kelas'];
        }
        if (($filter['id'] ?? '') !== '') {
            $bits[] = 'Siswa ' . $filter['id'];
        }
        return $bits !== [] ? implode(' · ', $bits) : 'Semua (tanpa filter)';
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
    private function kktpForKelas(string $kelasLabel, array $kktpMap): ?float
    {
        if ($kelasLabel === '' || $kktpMap === []) {
            return null;
        }
        // Ambil kelas terakhir pada jalur "X.F → XI.F → XII.F"
        $parts = preg_split('/\s*→\s*/u', $kelasLabel) ?: [$kelasLabel];
        $last = trim((string) end($parts));
        $tingkat = $this->parseTingkat($last);
        if ($tingkat !== null && isset($kktpMap[$tingkat])) {
            return $kktpMap[$tingkat];
        }
        return null;
    }

    private function parseTingkat(string $kelas): ?string
    {
        $nama = trim($kelas);
        if ($nama === '') {
            return null;
        }
        if (preg_match('/^(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/i', $nama, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^(\d{1,2})\b/', $nama, $m)) {
            $n = (int) $m[1];
            $map = [
                1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
                7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
            ];
            return $map[$n] ?? null;
        }
        return null;
    }

    private function scoreCell(
        callable $cell,
        mixed $val,
        ?float $kktp,
        bool $alt,
        bool $bold,
        int $digits = 0,
        bool $asTotal = false
    ): string {
        if ($val === null || $val === '' || !is_numeric($val)) {
            return $cell('—', $alt ? 'ScoreEmptyAlt' : 'ScoreEmpty');
        }
        // Pembulatan bilangan bulat untuk tampilan & pewarnaan interval
        $n = (float) round((float) $val, $asTotal || $digits === 0 ? 0 : $digits);
        $text = $digits === 0 || $asTotal
            ? (string) (int) $n
            : number_format($n, $digits, '.', '');

        if ($asTotal) {
            return $cell($text, $alt ? 'TotalAlt' : 'Total', null, 'Number');
        }

        if ($kktp === null) {
            $style = $bold
                ? ($alt ? 'ScoreCukupAltBold' : 'ScoreCukupBold')
                : ($alt ? 'ScoreCukupAlt' : 'ScoreCukup');
            return $cell($text, $style, null, 'Number');
        }

        $band = KktpStore::predikatBand($n, $kktp);
        if ($band === 'belum') {
            $style = $bold
                ? ($alt ? 'ScoreLowAltBold' : 'ScoreLowBold')
                : ($alt ? 'ScoreLowAlt' : 'ScoreLow');
        } elseif ($band === 'sangat_baik') {
            $style = $bold
                ? ($alt ? 'ScoreHighAltBold' : 'ScoreHighBold')
                : ($alt ? 'ScoreHighAlt' : 'ScoreHigh');
        } elseif ($band === 'baik') {
            $style = $bold
                ? ($alt ? 'ScoreBaikAltBold' : 'ScoreBaikBold')
                : ($alt ? 'ScoreBaikAlt' : 'ScoreBaik');
        } else {
            // Cukup: font hitam, sel putih
            $style = $bold
                ? ($alt ? 'ScoreCukupAltBold' : 'ScoreCukupBold')
                : ($alt ? 'ScoreCukupAlt' : 'ScoreCukup');
        }

        return $cell($text, $style, null, 'Number');
    }

    private function fmtNum(mixed $v, int $digits): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '—';
        }
        return number_format((float) $v, $digits, '.', '');
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
            if ($type === 'Number' && $value !== '' && $value !== '—' && is_numeric($value)) {
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
            . '<Title>Rekap Semua Semester</Title><Author>Rekap RDM</Author>'
            . '<Created>' . date('c') . '</Created></DocumentProperties>' . "\n";
    }

    private function stylesXml(callable $esc): string
    {
        $w = $esc(self::WARNA);
        $ws = $esc(self::WARNA_SOFT);
        $wm = $esc(self::WARNA_MID);
        $cream = $esc(self::CREAM);

        $border = static function (string $color = '#C5DED4') use ($esc): string {
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
            string $numFmt = '0'
        ) use ($border, $esc): string {
            $b = $bold ? ' ss:Bold="1"' : '';
            return '<Style ss:ID="' . $esc($id) . '">'
                . '<Font ss:FontName="Calibri" ss:Size="10"' . $b . ' ss:Color="' . $esc($fg) . '"/>'
                . '<Interior ss:Color="' . $esc($bg) . '" ss:Pattern="Solid"/>'
                . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
                . $border('#B7D4C8')
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
        $xml .= '<Style ss:ID="MetaLabel"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $wm . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="MetaValue"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#1A3D32"/>'
            . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . $border() . '</Style>' . "\n";
        $xml .= '<Style ss:ID="Hint"><Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#5A7A6E"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Head"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $w . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1" ss:WrapText="1"/>'
            . $border('#FFFFFF') . '</Style>' . "\n";
        $xml .= '<Style ss:ID="HeadCenter" ss:Parent="Head"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Data"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#111111"/>'
            . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . $border() . '</Style>' . "\n";
        $xml .= '<Style ss:ID="DataCenter" ss:Parent="Data"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>' . "\n";
        $xml .= '<Style ss:ID="DataAlt" ss:Parent="Data"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '<Style ss:ID="DataAltCenter" ss:Parent="DataCenter"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Rank"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#9A3412"/>'
            . '<Interior ss:Color="#FFEDD5" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border('#FDBA74') . '</Style>' . "\n";
        $xml .= '<Style ss:ID="RankAlt" ss:Parent="Rank"><Interior ss:Color="#FED7AA" ss:Pattern="Solid"/></Style>' . "\n";

        // Font hitam; latar: putih Cukup · biru Baik · hijau Sangat Baik · merah Belum (< KKTP)
        $black = '#111111';
        $xml .= $score('ScoreCukup', $black, '#FFFFFF');
        $xml .= $score('ScoreCukupAlt', $black, '#FFFFFF');
        $xml .= $score('ScoreCukupBold', $black, '#FFFFFF', true);
        $xml .= $score('ScoreCukupAltBold', $black, '#FFFFFF', true);
        $xml .= $score('ScoreBaik', $black, '#DBEAFE');
        $xml .= $score('ScoreBaikAlt', $black, '#BFDBFE');
        $xml .= $score('ScoreBaikBold', $black, '#DBEAFE', true);
        $xml .= $score('ScoreBaikAltBold', $black, '#BFDBFE', true);
        $xml .= $score('ScoreHigh', $black, '#CCFBF1');
        $xml .= $score('ScoreHighAlt', $black, '#99F6E4');
        $xml .= $score('ScoreHighBold', $black, '#CCFBF1', true);
        $xml .= $score('ScoreHighAltBold', $black, '#99F6E4', true);
        $xml .= $score('ScoreLow', self::LOW, self::LOW_BG);
        $xml .= $score('ScoreLowAlt', self::LOW, '#FECDD3');
        $xml .= $score('ScoreLowBold', self::LOW, self::LOW_BG, true);
        $xml .= $score('ScoreLowAltBold', self::LOW, '#FECDD3', true);
        $xml .= '<Style ss:ID="ScoreEmpty"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#8AA89A"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border() . '</Style>' . "\n";
        $xml .= '<Style ss:ID="ScoreEmptyAlt" ss:Parent="ScoreEmpty"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Total"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="' . $esc($black) . '"/>'
            . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . $border()
            . '<NumberFormat ss:Format="0"/></Style>' . "\n";
        $xml .= '<Style ss:ID="TotalAlt" ss:Parent="Total"><Interior ss:Color="' . $cream . '" ss:Pattern="Solid"/></Style>' . "\n";

        $xml .= '</Styles>' . "\n";
        return $xml;
    }
}
