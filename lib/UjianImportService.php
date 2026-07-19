<?php

declare(strict_types=1);

require_once __DIR__ . '/UjianStore.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SekolahStore.php';

/**
 * Template Excel ujian per kelas: semua mapel (kode/nama seperti rapor) dalam 1 file.
 */
final class UjianImportService
{
    private const META_COLS = ['no', 'nis', 'nisn', 'nama', 'jk', 'kelas', 'nama siswa', 'nama_siswa'];

    public function __construct(private readonly UjianStore $store)
    {
    }

    /**
     * Daftar kode mapel untuk kelas, mengikuti urutan rapor/MAPEL.
     *
     * @return list<string>
     */
    public function mapelForKelas(array $data, string $kelas, string $tahunAjaran = '', string $semester = ''): array
    {
        $kelas = trim($kelas);
        $codes = [];
        foreach ($data['records'] ?? [] as $row) {
            $rowKelas = (string) ($row['kelas'] ?? '');
            if ($kelas !== '') {
                // Exact kelas, atau tingkat:X / XI / XII jika filter tingkat
                if (str_starts_with($kelas, 'tingkat:')) {
                    $tingkat = strtoupper(substr($kelas, strlen('tingkat:')));
                    if (!preg_match('/^(XII|XI|X)\b/i', $rowKelas, $m) || strtoupper($m[1]) !== $tingkat) {
                        continue;
                    }
                } elseif (strcasecmp($rowKelas, $kelas) !== 0) {
                    continue;
                }
            }
            if ($tahunAjaran !== '' && (string) ($row['tahun_ajaran'] ?? '') !== $tahunAjaran) {
                continue;
            }
            if ($semester !== '' && strcasecmp((string) ($row['semester'] ?? ''), $semester) !== 0) {
                continue;
            }
            foreach ($row['scores'] ?? [] as $kode => $nilai) {
                // Hanya mapel yang benar-benar ada nilai di rapor
                if ($nilai === null || $nilai === '') {
                    continue;
                }
                if (!is_numeric($nilai)) {
                    continue;
                }
                $codes[(string) $kode] = true;
            }
        }

        if ($codes === []) {
            return array_keys(UjianStore::MAPEL);
        }

        return $this->sortMapel(array_keys($codes));
    }

    /**
     * @param list<array{nis:string,nisn:string,nama:string,jk:string}> $siswa
     * @param list<string> $mapelCodes
     * @param array|null $data cache rekap (untuk menandai mapel kosong di rapor, khusus teori)
     */
    public function buildSpreadsheetXml(array $meta, array $siswa, array $mapelCodes, ?array $data = null): string
    {
        $jenis = (string) ($meta['jenis'] ?? UjianStore::JENIS_PRAKTEK);
        $tpl = UjianStore::TEMPLATES[$jenis] ?? UjianStore::TEMPLATES[UjianStore::JENIS_PRAKTEK];
        $jenisLabel = $tpl['nama'] ?? $jenis;
        $warna = strtoupper(ltrim((string) ($tpl['warna'] ?? '#0F5C45'), '#'));
        $warnaSoft = strtoupper(ltrim((string) ($tpl['warna_soft'] ?? '#E7F3EC'), '#'));
        if (strlen($warna) === 6) {
            $warna = '#' . $warna;
        }
        if (strlen($warnaSoft) === 6) {
            $warnaSoft = '#' . $warnaSoft;
        }

        $kelas = (string) ($meta['kelas'] ?? '');
        $kelasLabel = self::kelasLabel($kelas);
        $tahun = (string) ($meta['tahun_ajaran'] ?? '');
        $semester = (string) ($meta['semester'] ?? '');
        $tanggal = (string) ($meta['tanggal'] ?? date('Y-m-d'));
        $ket = (string) ($meta['keterangan'] ?? '');
        $madrasah = (string) ((new SekolahStore())->active()['nama'] ?? '')
            ?: (string) Config::get('madrasah', 'MAN 4 Sleman');

        // Map NISN → set kode mapel yang ada nilainya di rapor (untuk highlight teori)
        $raporMapelByNisn = [];
        if ($jenis === UjianStore::JENIS_TEORI && is_array($data)) {
            $raporMapelByNisn = $this->buildRaporMapelIndex($data, $kelas, $tahun, $semester);
        }

        $colCount = 6 + count($mapelCodes);
        $mergeAcross = max(0, $colCount - 1);

        // Lebar kolom (SpreadsheetML points ≈ karakter Excel × 7)
        // NIS 7, NISN 10, NAMA 35, JK 4, KELAS 6, mapel 6 (satuan karakter Excel)
        $widths = [
            4 * 7,   // No
            7 * 7,   // NIS
            10 * 7,  // NISN
            35 * 7,  // Nama
            4 * 7,   // JK
            6 * 7,   // Kelas
        ];
        foreach ($mapelCodes as $kode) {
            $widths[] = 6 * 7; // mata pelajaran
        }

        $esc = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };

        $cell = static function (
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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

        $xml .= '<Styles>' . "\n";
        $xml .= '<Style ss:ID="Default"><Font ss:FontName="Calibri" ss:Size="11"/><Alignment ss:Vertical="Center"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Title"><Font ss:FontName="Calibri" ss:Size="18" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $esc($warna) . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Subtitle"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="' . $esc($warna) . '"/>'
            . '<Interior ss:Color="' . $esc($warnaSoft) . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="MetaLabel"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $esc($warna) . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . '<Borders>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '<Style ss:ID="MetaValue"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#1A2E26"/>'
            . '<Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . '<Borders>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D5E5DC"/>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D5E5DC"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D5E5DC"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '<Style ss:ID="Hint"><Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#5A7268"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1" ss:Indent="1"/></Style>' . "\n";
        $xml .= '<Style ss:ID="HeadKode"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="' . $esc($warna) . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
            . '<Borders>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>'
            . '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '<Style ss:ID="HeadNama"><Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="' . $esc($warna) . '"/>'
            . '<Interior ss:Color="' . $esc($warnaSoft) . '" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>'
            . '<Borders>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#C5D9CE"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#C5D9CE"/>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#C5D9CE"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '<Style ss:ID="Data"><Font ss:FontName="Calibri" ss:Size="10"/>'
            . '<Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>'
            . '<Borders>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DCE8E1"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DCE8E1"/>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DCE8E1"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '<Style ss:ID="DataCenter" ss:Parent="Data"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>' . "\n";
        $xml .= '<Style ss:ID="DataAlt" ss:Parent="Data"><Interior ss:Color="#F7FBF8" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '<Style ss:ID="DataAltCenter" ss:Parent="DataCenter"><Interior ss:Color="#F7FBF8" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '<Style ss:ID="Nilai"><Font ss:FontName="Calibri" ss:Size="10"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
            . '<Borders>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DCE8E1"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DCE8E1"/>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DCE8E1"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '<Style ss:ID="NilaiAlt" ss:Parent="Nilai"><Interior ss:Color="#F7FBF8" ss:Pattern="Solid"/></Style>' . "\n";
        // Mapel tidak ada di rapor siswa (khusus template teori) — merah muda kalem
        $xml .= '<Style ss:ID="NilaiMissing"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#9F1239"/>'
            . '<Interior ss:Color="#FCE7EB" ss:Pattern="Solid"/>'
            . '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>'
            . '<Borders>'
            . '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F5C2C7"/>'
            . '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F5C2C7"/>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F5C2C7"/>'
            . '</Borders></Style>' . "\n";
        $xml .= '</Styles>' . "\n";

        $sheetName = $jenis === UjianStore::JENIS_TEORI ? 'Ujian Teori' : 'Ujian Praktek';
        $xml .= '<Worksheet ss:Name="' . $esc($sheetName) . '">' . "\n";
        $xml .= '<Table ss:DefaultRowHeight="18">' . "\n";

        foreach ($widths as $w) {
            $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . round($w, 1) . '"/>' . "\n";
        }

        // Judul
        $xml .= '<Row ss:Height="32">' . $cell($jenisLabel . ' — Template Nilai', 'Title', $mergeAcross) . '</Row>' . "\n";
        $sub = $madrasah . ' · ' . $kelasLabel
            . ($tahun !== '' ? ' · ' . $tahun : '')
            . ($semester !== '' ? ' · ' . $semester : '')
            . ' · ' . count($mapelCodes) . ' mapel · ' . count($siswa) . ' siswa';
        $xml .= '<Row ss:Height="22">' . $cell($sub, 'Subtitle', $mergeAcross) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";

        // Header kode (meta JENIS/KELAS/dll ada di sheet 2)
        $xml .= '<Row ss:Height="22">';
        foreach (['No', 'NIS', 'NISN', 'Nama', 'JK', 'Kelas'] as $h) {
            $xml .= $cell($h, 'HeadKode');
        }
        foreach ($mapelCodes as $kode) {
            $xml .= $cell($kode, 'HeadKode');
        }
        $xml .= '</Row>' . "\n";

        // Data siswa
        $no = 1;
        foreach ($siswa as $i => $s) {
            $alt = ($i % 2) === 1;
            $stCenter = $alt ? 'DataAltCenter' : 'DataCenter';
            $stData = $alt ? 'DataAlt' : 'Data';
            $stNilai = $alt ? 'NilaiAlt' : 'Nilai';
            $nisnKey = $this->normalizeNisnKey((string) (($s['nisn'] ?? '') !== '' ? $s['nisn'] : ($s['nis'] ?? '')));
            $punyaMapel = $raporMapelByNisn[$nisnKey] ?? [];
            $siswaKelas = trim((string) ($s['kelas'] ?? ''));
            if ($siswaKelas === '' && !str_starts_with($kelas, 'tingkat:')) {
                $siswaKelas = $kelas;
            }
            $xml .= '<Row ss:Height="20">';
            $xml .= $cell((string) $no++, $stCenter, null, 'Number');
            $xml .= $cell((string) ($s['nis'] ?? ''), $stCenter);
            $xml .= $cell((string) ($s['nisn'] ?? ''), $stCenter);
            $xml .= $cell((string) ($s['nama'] ?? ''), $stData);
            $xml .= $cell((string) ($s['jk'] ?? ''), $stCenter);
            $xml .= $cell($siswaKelas, $stCenter);
            foreach ($mapelCodes as $kode) {
                $adaDiRapor = $jenis !== UjianStore::JENIS_TEORI
                    || $raporMapelByNisn === []
                    || isset($punyaMapel[$kode]);
                $xml .= $cell('', $adaDiRapor ? $stNilai : 'NilaiMissing');
            }
            $xml .= '</Row>' . "\n";
        }

        $xml .= '</Table>' . "\n";
        // Beku: judul + sub + spacer + 1 header = 4
        $xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
            . '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>4</SplitHorizontal>'
            . '<TopRowBottomPane>4</TopRowBottomPane><ActivePane>2</ActivePane>'
            . '</WorksheetOptions>' . "\n";
        $xml .= '</Worksheet>' . "\n";

        // Sheet 2: meta + keterangan + petunjuk
        // KELAS tetap nilai mesin (X.A / tingkat:XII) agar impor akurat
        $metaRows = [
            ['JENIS', $jenis],
            ['KELAS', $kelas],
            ['TAHUN_AJARAN', $tahun],
            ['SEMESTER', $semester],
            ['TANGGAL', $tanggal],
            ['KETERANGAN', $ket !== '' ? $ket : '—'],
        ];
        $xml .= '<Worksheet ss:Name="Keterangan">' . "\n";
        $xml .= '<Table ss:DefaultRowHeight="18">' . "\n";
        $xml .= '<Column ss:AutoFitWidth="0" ss:Width="120"/>' . "\n";
        $xml .= '<Column ss:AutoFitWidth="0" ss:Width="420"/>' . "\n";
        $xml .= '<Row ss:Height="32">' . $cell('Data template', 'Title', 1) . '</Row>' . "\n";
        $xml .= '<Row ss:Height="22">'
            . $cell($jenisLabel . ' · ' . $kelasLabel, 'Subtitle', 1)
            . '</Row>' . "\n";
        $xml .= '<Row ss:Height="8"><Cell/></Row>' . "\n";
        foreach ($metaRows as $mr) {
            $xml .= '<Row ss:Height="22">'
                . $cell($mr[0], 'MetaLabel')
                . $cell($mr[1], 'MetaValue')
                . '</Row>' . "\n";
        }
        $xml .= '<Row ss:Height="10"><Cell/></Row>' . "\n";
        $xml .= '<Row ss:Height="22">' . $cell('Petunjuk pengisian', 'Subtitle', 1) . '</Row>' . "\n";
        $petunjuk = [
            '1. Isi nilai di kolom mapel pada sheet pertama (kosongkan jika tidak diujikan).',
            '2. Jangan ubah baris kode mapel (baris header kode) dan jangan menghapus kolom identitas.',
            '3. NIS / NISN / Nama siswa biarkan sesuai unduhan.',
            '4. Jangan ubah baris JENIS–KETERANGAN di sheet ini (dipakai saat impor).',
            '5. Setelah diisi, impor kembali file ini lewat menu Ujian praktek / teori.',
            '6. Satu file berisi beberapa mapel: setiap kolom mapel menjadi sesi ujian terpisah saat impor.',
        ];
        if ($jenis === UjianStore::JENIS_TEORI) {
            $petunjuk[] = '7. Cell merah muda kalem = mapel tersebut tidak ada nilai di rapor siswa (tidak perlu diisi).';
        }
        foreach ($petunjuk as $line) {
            $xml .= '<Row ss:Height="22">' . $cell($line, 'Hint', 1) . '</Row>' . "\n";
        }
        $xml .= '</Table>' . "\n";
        $xml .= '</Worksheet></Workbook>';

        return $xml;
    }

    /**
     * Index NISN → mapel kode yang punya nilai numerik di rapor.
     *
     * @return array<string, array<string, true>>
     */
    private function buildRaporMapelIndex(array $data, string $kelas, string $tahunAjaran = '', string $semester = ''): array
    {
        $index = [];
        foreach ($data['records'] ?? [] as $row) {
            $rowKelas = (string) ($row['kelas'] ?? '');
            if ($kelas !== '' && !$this->matchKelasFilter($rowKelas, $kelas)) {
                continue;
            }
            if ($tahunAjaran !== '' && (string) ($row['tahun_ajaran'] ?? '') !== $tahunAjaran) {
                continue;
            }
            if ($semester !== '' && strcasecmp((string) ($row['semester'] ?? ''), $semester) !== 0) {
                continue;
            }
            $nisn = $this->normalizeNisnKey((string) (
                ($row['nisn'] ?? '') !== '' ? $row['nisn'] : ($row['nis'] ?? $row['id'] ?? '')
            ));
            if ($nisn === '') {
                continue;
            }
            if (!isset($index[$nisn])) {
                $index[$nisn] = [];
            }
            foreach ($row['scores'] ?? [] as $kode => $nilai) {
                if ($nilai === null || $nilai === '' || !is_numeric($nilai)) {
                    continue;
                }
                // Nilai 0 di rapor biasanya dianggap tidak ada / diabaikan
                if ((float) $nilai == 0.0) {
                    continue;
                }
                $index[$nisn][(string) $kode] = true;
            }
        }
        return $index;
    }

    private function matchKelasFilter(string $rowKelas, string $filter): bool
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
        return strcasecmp($rowKelas, $filter) === 0;
    }

    /** Label tampilan kelas / tingkat untuk judul template. */
    public static function kelasLabel(string $kelas): string
    {
        $kelas = trim($kelas);
        if (str_starts_with($kelas, 'tingkat:')) {
            return 'Semua kelas ' . strtoupper(substr($kelas, strlen('tingkat:')));
        }
        return $kelas !== '' ? $kelas : '—';
    }

    private function normalizeNisnKey(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        // Samakan 007… dengan 7…
        if (preg_match('/^\d+$/', $id)) {
            return ltrim($id, '0') ?: '0';
        }
        return strtolower($id);
    }

    public function suggestedFilename(array $meta): string
    {
        $jenis = (string) ($meta['jenis'] ?? 'ujian');
        $kelasRaw = (string) ($meta['kelas'] ?? 'kelas');
        if (str_starts_with($kelasRaw, 'tingkat:')) {
            $kelas = 'tingkat_' . strtoupper(substr($kelasRaw, strlen('tingkat:')));
        } else {
            $kelas = preg_replace('/[^\w.\-]+/u', '_', $kelasRaw) ?: 'kelas';
        }
        $ta = preg_replace('/[^\d\/]+/', '', (string) ($meta['tahun_ajaran'] ?? '')) ?: '';
        $ta = str_replace('/', '-', $ta);
        $parts = ['template', $jenis, $kelas];
        if ($ta !== '') {
            $parts[] = $ta;
        }
        return implode('_', $parts) . '.xls';
    }

    /**
     * Impor file Excel template → sesi ujian per mapel.
     *
     * @return array{created:list<array>,updated:list<array>,skipped:list<string>,meta:array}
     */
    public function importFile(string $path, array $override = []): array
    {
        $grid = $this->readGrid($path);
        if ($grid === []) {
            throw new InvalidArgumentException('File Excel kosong atau tidak dapat dibaca.');
        }

        $parsed = $this->parseTemplateGrid($grid);
        foreach (['jenis', 'kelas', 'tahun_ajaran', 'semester', 'tanggal', 'penguji', 'keterangan'] as $key) {
            if (isset($override[$key]) && trim((string) $override[$key]) !== '') {
                $parsed['meta'][$key] = trim((string) $override[$key]);
            }
        }

        $meta = $parsed['meta'];
        $jenis = (string) ($meta['jenis'] ?? '');
        if (!isset(UjianStore::TEMPLATES[$jenis])) {
            throw new InvalidArgumentException('Jenis ujian pada file tidak valid (praktek/teori).');
        }
        $kelas = trim((string) ($meta['kelas'] ?? ''));
        if ($kelas === '') {
            throw new InvalidArgumentException('Kelas pada file / form wajib diisi.');
        }

        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($parsed['mapel'] as $kode => $colInfo) {
            $siswaRows = [];
            $hasNilai = false;
            foreach ($parsed['siswa'] as $s) {
                $nilai = $s['nilai'][$kode] ?? null;
                if ($nilai !== null) {
                    $hasNilai = true;
                }
                $siswaRows[] = [
                    'nis' => $s['nis'],
                    'nisn' => $s['nisn'],
                    'nama' => $s['nama'],
                    'jk' => $s['jk'],
                    'nilai_akhir' => $nilai,
                    'catatan' => '',
                ];
            }

            // Tetap buat sesi meskipun nilai masih kosong (template terisi identitas)
            if ($siswaRows === []) {
                $skipped[] = $kode . ': tidak ada baris siswa';
                continue;
            }

            $mapelNama = UjianStore::MAPEL[$kode] ?? ($colInfo['nama'] ?? $kode);
            $payload = [
                'jenis' => $jenis,
                'mapel' => $kode,
                'mapel_nama' => $mapelNama,
                'judul' => (UjianStore::TEMPLATES[$jenis]['nama'] ?? 'Ujian') . ' — ' . $mapelNama,
                'kelas' => $kelas,
                'tahun_ajaran' => (string) ($meta['tahun_ajaran'] ?? ''),
                'semester' => (string) ($meta['semester'] ?? ''),
                'tanggal' => (string) ($meta['tanggal'] ?? date('Y-m-d')),
                'penguji' => (string) ($meta['penguji'] ?? ''),
                'keterangan' => trim((string) ($meta['keterangan'] ?? '') . ($hasNilai ? '' : ' (nilai belum diisi)')),
                'siswa' => $siswaRows,
            ];

            $existing = $this->store->findSession(
                $jenis,
                $kelas,
                $kode,
                (string) ($meta['tahun_ajaran'] ?? ''),
                (string) ($meta['semester'] ?? '')
            );

            if ($existing !== null) {
                $row = $this->store->update((string) $existing['id'], $payload);
                $updated[] = [
                    'id' => $row['id'],
                    'mapel' => $kode,
                    'mapel_nama' => $mapelNama,
                ];
            } else {
                $row = $this->store->create($payload);
                $created[] = [
                    'id' => $row['id'],
                    'mapel' => $kode,
                    'mapel_nama' => $mapelNama,
                ];
            }
        }

        if ($created === [] && $updated === []) {
            throw new RuntimeException(
                'Tidak ada sesi ujian yang dibuat. ' . implode(' ', $skipped)
            );
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'meta' => $meta,
            'mapel_count' => count($parsed['mapel']),
            'siswa_count' => count($parsed['siswa']),
        ];
    }

    /**
     * @param list<array<int, string|null>> $grid 0-index rows/cols
     * @return array{meta:array,mapel:array<string,array{nama:string}>,siswa:list<array>}
     */
    private function parseTemplateGrid(array $grid): array
    {
        $meta = [
            'jenis' => '',
            'kelas' => '',
            'tahun_ajaran' => '',
            'semester' => '',
            'tanggal' => date('Y-m-d'),
            'penguji' => '',
            'keterangan' => '',
        ];

        $headerRow = null;
        $namaRow = null;
        $mapelCols = []; // colIndex => kode

        foreach ($grid as $r => $cols) {
            $c0 = strtoupper(trim((string) ($cols[0] ?? '')));
            $c1 = trim((string) ($cols[1] ?? ''));
            if (in_array($c0, ['JENIS', 'KELAS', 'TAHUN_AJARAN', 'TAHUN AJARAN', 'SEMESTER', 'TANGGAL', 'PENGUJI', 'KETERANGAN'], true)) {
                $key = strtolower(str_replace(' ', '_', $c0));
                if ($key === 'tahun_ajaran' || $key === 'tahun_ajaran') {
                    $meta['tahun_ajaran'] = $c1;
                } elseif ($key === 'jenis') {
                    $meta['jenis'] = strtolower($c1);
                } else {
                    $meta[$key] = $c1;
                }
                continue;
            }

            // Deteksi baris header kode: No + NIS + NISN
            $joined = [];
            foreach ($cols as $i => $v) {
                $joined[$i] = strtolower(trim((string) $v));
            }
            if (
                isset($joined[0], $joined[1], $joined[2], $joined[3])
                && $joined[0] === 'no'
                && $joined[1] === 'nis'
                && ($joined[2] === 'nisn')
                && ($joined[3] === 'nama' || $joined[3] === 'nama siswa')
            ) {
                $headerRow = $r;
                // Template baru: No NIS NISN Nama JK Kelas | mapel…
                // Template lama: No NIS NISN Nama JK | mapel…
                $mapelStart = (isset($joined[5]) && $joined[5] === 'kelas') ? 6 : 5;
                for ($i = $mapelStart; $i < count($cols); $i++) {
                    $label = trim((string) ($cols[$i] ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    // Lewati header identitas jika ada
                    if (in_array(strtolower($label), ['jk', 'kelas'], true)) {
                        continue;
                    }
                    $kode = $this->resolveMapelKode($label);
                    if ($kode === null) {
                        continue;
                    }
                    $mapelCols[$i] = $kode;
                }
                // Baris berikutnya = nama mapel (template lama) atau langsung data siswa
                if (isset($grid[$r + 1])) {
                    $next = $grid[$r + 1];
                    $nextNis = trim((string) ($next[1] ?? ''));
                    $nextNisn = trim((string) ($next[2] ?? ''));
                    $nextNama = trim((string) ($next[3] ?? ''));
                    $looksLikeNameRow = $nextNis === '' && $nextNisn === '' && $nextNama === '';
                    if ($looksLikeNameRow) {
                        $namaRow = $r + 1;
                    }
                }
                break;
            }
        }

        if ($headerRow === null || $mapelCols === []) {
            throw new InvalidArgumentException(
                'Format template tidak dikenali. Unduh template resmi lalu isi nilai tanpa mengubah baris kode mapel.'
            );
        }

        $mapel = [];
        foreach ($mapelCols as $col => $kode) {
            $nama = '';
            if ($namaRow !== null) {
                $nama = trim((string) ($grid[$namaRow][$col] ?? ''));
            }
            if ($nama === '' || $this->resolveMapelKode($nama) === $kode) {
                $nama = UjianStore::MAPEL[$kode] ?? $kode;
            }
            $mapel[$kode] = ['nama' => $nama];
        }

        $dataStart = ($namaRow !== null ? $namaRow : $headerRow) + 1;
        $siswa = [];
        for ($r = $dataStart; $r < count($grid); $r++) {
            $cols = $grid[$r];
            $nis = trim((string) ($cols[1] ?? ''));
            $nisn = trim((string) ($cols[2] ?? ''));
            $nama = trim((string) ($cols[3] ?? ''));
            $jk = trim((string) ($cols[4] ?? ''));
            if ($nis === '' && $nisn === '' && $nama === '') {
                continue;
            }
            // Lewati baris yang masih berupa header nama mapel
            if ($nama === '' && $this->resolveMapelKode($nis) !== null) {
                continue;
            }

            $nilai = [];
            foreach ($mapelCols as $col => $kode) {
                $raw = $cols[$col] ?? null;
                $nilai[$kode] = $this->toNilai($raw);
            }

            $siswa[] = [
                'nis' => $nis,
                'nisn' => $nisn,
                'nama' => $nama,
                'jk' => $jk,
                'nilai' => $nilai,
            ];
        }

        return compact('meta', 'mapel', 'siswa');
    }

    private function resolveMapelKode(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        if (isset(UjianStore::MAPEL[$label])) {
            return $label;
        }
        // case-insensitive kode
        foreach (UjianStore::MAPEL as $kode => $nama) {
            if (strcasecmp($kode, $label) === 0) {
                return $kode;
            }
        }
        $norm = $this->normalizeName($label);
        if (in_array($norm, self::META_COLS, true)) {
            return null;
        }
        foreach (UjianStore::MAPEL as $kode => $nama) {
            if ($this->normalizeName($nama) === $norm) {
                return $kode;
            }
        }
        return null;
    }

    private function normalizeName(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(['\'', '"'], '', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    private function toNilai(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '' || strtoupper($s) === 'NULL') {
            return null;
        }
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) {
            return null;
        }
        $n = (float) $s;
        return $n > 0 ? $n : null; // 0 diabaikan seperti rapor
    }

    /** @param list<string> $codes */
    private function sortMapel(array $codes): array
    {
        $order = array_flip(array_keys(UjianStore::MAPEL));
        usort($codes, static function ($a, $b) use ($order) {
            $ia = $order[$a] ?? 1000;
            $ib = $order[$b] ?? 1000;
            if ($ia === $ib) {
                return strcasecmp($a, $b);
            }
            return $ia <=> $ib;
        });
        return $codes;
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function readGrid(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return $this->readCsv($path);
        }
        if ($ext === 'xls') {
            $raw = file_get_contents($path) ?: '';
            if (str_contains($raw, 'urn:schemas-microsoft-com:office:spreadsheet') || str_contains($raw, '<Workbook')) {
                return $this->readSpreadsheetMl($raw);
            }
        }
        if (in_array($ext, ['xlsx', 'xlsm'], true) || $this->isZip($path)) {
            return $this->readXlsx($path);
        }
        // Coba SpreadsheetML
        $raw = file_get_contents($path) ?: '';
        if (str_contains($raw, '<Workbook')) {
            return $this->readSpreadsheetMl($raw);
        }
        throw new InvalidArgumentException('Format file tidak didukung. Gunakan .xls template unduhan, .xlsx, atau .csv.');
    }

    private function isZip(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 2);
        fclose($fh);
        return $magic === 'PK';
    }

    /** @return list<array<int, string|null>> */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Tidak dapat membuka CSV.');
        }
        // skip BOM
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $grid = [];
        while (($row = fgetcsv($fh)) !== false) {
            $grid[] = array_map(static fn ($v) => $v === null ? null : (string) $v, $row);
        }
        fclose($fh);
        return $grid;
    }

    /** @return list<array<int, string|null>> */
    private function readSpreadsheetMl(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new InvalidArgumentException('File SpreadsheetML tidak valid.');
        }

        $doc->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
        $rows = $doc->xpath('//ss:Worksheet[1]//ss:Row') ?: $doc->xpath('//Worksheet[1]//Row') ?: [];
        $grid = [];
        foreach ($rows as $row) {
            $line = [];
            $col = 0;
            foreach ($row->children('urn:schemas-microsoft-com:office:spreadsheet') as $name => $cell) {
                if ($name !== 'Cell') {
                    // try default ns
                    continue;
                }
                $attrs = $cell->attributes('urn:schemas-microsoft-com:office:spreadsheet');
                if ($attrs && isset($attrs['Index'])) {
                    $idx = ((int) $attrs['Index']) - 1;
                    while ($col < $idx) {
                        $line[$col++] = null;
                    }
                }
                $data = $cell->children('urn:schemas-microsoft-com:office:spreadsheet')->Data
                    ?? $cell->Data
                    ?? null;
                $line[$col++] = $data !== null ? trim((string) $data) : null;
            }
            // Fallback tanpa namespace
            if ($line === []) {
                foreach ($row->Cell as $cell) {
                    $attrs = $cell->attributes();
                    if ($attrs && isset($attrs['Index'])) {
                        $idx = ((int) $attrs['Index']) - 1;
                        while ($col < $idx) {
                            $line[$col++] = null;
                        }
                    }
                    $line[$col++] = isset($cell->Data) ? trim((string) $cell->Data) : null;
                }
            }
            $grid[] = $line;
        }

        // Meta ada di sheet 2 — sisipkan ke grid agar parseTemplateGrid tetap membaca
        $metaKeys = ['JENIS', 'KELAS', 'TAHUN_AJARAN', 'SEMESTER', 'TANGGAL', 'KETERANGAN', 'PENGUJI'];
        $prepend = [];
        foreach ($metaKeys as $key) {
            $val = $this->extractMetaFromSheet($doc, 2, $key);
            if ($val === null) {
                continue;
            }
            $found = false;
            foreach ($grid as $line) {
                if (strtoupper(trim((string) ($line[0] ?? ''))) === $key) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $prepend[] = [$key, $val];
            }
        }
        if ($prepend !== []) {
            $grid = array_merge($prepend, $grid);
        }

        return $grid;
    }

    /** Ambil nilai meta KEY dari worksheet ke-n (1-based). */
    private function extractMetaFromSheet(\SimpleXMLElement $doc, int $sheetIndex, string $key): ?string
    {
        $doc->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
        $rows = $doc->xpath('//ss:Worksheet[' . $sheetIndex . ']//ss:Row')
            ?: $doc->xpath('//Worksheet[' . $sheetIndex . ']//Row')
            ?: [];
        $want = strtoupper($key);
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row->children('urn:schemas-microsoft-com:office:spreadsheet') as $name => $cell) {
                if ($name !== 'Cell') {
                    continue;
                }
                $data = $cell->children('urn:schemas-microsoft-com:office:spreadsheet')->Data
                    ?? $cell->Data
                    ?? null;
                $vals[] = $data !== null ? trim((string) $data) : '';
            }
            if ($vals === []) {
                foreach ($row->Cell as $cell) {
                    $vals[] = isset($cell->Data) ? trim((string) $cell->Data) : '';
                }
            }
            if (isset($vals[0]) && strtoupper($vals[0]) === $want) {
                return $vals[1] ?? '';
            }
        }
        return null;
    }

    /** @return list<array<int, string|null>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Tidak dapat membuka file .xlsx.');
        }

        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $sx = @simplexml_load_string($ss);
            if ($sx !== false) {
                $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach ($sx->xpath('//m:si') ?: [] as $si) {
                    $texts = $si->xpath('.//m:t') ?: [];
                    $shared[] = implode('', array_map(static fn ($t) => (string) $t, $texts));
                }
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            // ambil sheet pertama dari workbook
            $wb = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            $sheet = false;
            if ($wb !== false && $rels !== false) {
                $rx = @simplexml_load_string($rels);
                if ($rx !== false) {
                    foreach ($rx->Relationship as $rel) {
                        $target = (string) $rel['Target'];
                        if (str_contains($target, 'worksheets/')) {
                            $sheet = $zip->getFromName('xl/' . ltrim($target, '/'));
                            break;
                        }
                    }
                }
            }
        }
        $zip->close();
        if ($sheet === false) {
            throw new InvalidArgumentException('Sheet Excel tidak ditemukan.');
        }

        $sx = @simplexml_load_string($sheet);
        if ($sx === false) {
            throw new InvalidArgumentException('Sheet Excel rusak.');
        }
        $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $gridMap = [];
        $maxCol = 0;
        $maxRow = 0;
        foreach ($sx->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $rIdx = ((int) $row['r']) - 1;
            $maxRow = max($maxRow, $rIdx);
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $cIdx = $this->colIndex($m[1]);
                $maxCol = max($maxCol, $cIdx);
                $type = (string) ($c['t'] ?? '');
                $v = isset($c->v) ? (string) $c->v : '';
                if ($type === 's') {
                    $v = $shared[(int) $v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $v = isset($c->is->t) ? (string) $c->is->t : '';
                }
                $gridMap[$rIdx][$cIdx] = $v;
            }
        }

        $grid = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $line = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $line[$c] = $gridMap[$r][$c] ?? null;
            }
            $grid[] = $line;
        }
        return $grid;
    }

    private function colIndex(string $col): int
    {
        $n = 0;
        $col = strtoupper($col);
        for ($i = 0; $i < strlen($col); $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n - 1;
    }
}
