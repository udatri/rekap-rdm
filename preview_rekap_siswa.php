<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';
require_once __DIR__ . '/lib/Config.php';

Auth::guardPage();

/**
 * Preview / cetak REKAP HASIL BELAJAR per siswa.
 * Query: id (NISN), tahun_ajaran, semester_ke, ujian_kolom, kelas, print=1 untuk auto-print
 */
try {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('Pilih siswa (NISN) terlebih dahulu.');
    }

    $q = [
        'id' => $id,
        'tahun_ajaran' => trim((string) ($_GET['tahun_ajaran'] ?? '')),
        'semester_ke' => trim((string) ($_GET['semester_ke'] ?? '')),
        'ujian_kolom' => trim((string) ($_GET['ujian_kolom'] ?? '')),
        'kelas' => trim((string) ($_GET['kelas'] ?? '')),
    ];

    $service = new RekapService();
    $data = $service->ensureData(false);
    $rekap = $service->rekapPerSiswa($data, $q);
    if (!empty($rekap['error']) || empty($rekap['siswa']['hasil_belajar'])) {
        throw new InvalidArgumentException($rekap['error'] ?? 'Data siswa tidak ditemukan.');
    }

    $s = $rekap['siswa'];
    $hb = $s['hasil_belajar'];
    $kktp = $rekap['kktp'] ?? $service->getKktp($data);
    $kktpMap = [];
    foreach ($kktp['tingkat'] ?? [] as $t) {
        $kode = strtoupper((string) ($t['kode'] ?? ''));
        if ($kode !== '' && isset($t['nilai']) && is_numeric($t['nilai'])) {
            $kktpMap[$kode] = (float) $t['nilai'];
        }
    }
    $slotLabels = [
        'x_ganjil' => ['X', 'Ganjil'],
        'x_genap' => ['X', 'Genap'],
        'xi_ganjil' => ['XI', 'Ganjil'],
        'xi_genap' => ['XI', 'Genap'],
        'xii_ganjil' => ['XII', 'Ganjil'],
        'xii_genap' => ['XII', 'Genap'],
    ];
    $allSlots = array_keys($slotLabels);
    $semesterKeFilter = trim((string) ($_GET['semester_ke'] ?? ($hb['semester_ke_filter'] ?? '')));
    $visibleSlots = RekapService::allowedHasilBelajarSlots($semesterKeFilter) ?? $allSlots;
    $filteredView = $semesterKeFilter !== '';
    $ujianKolomFilter = trim((string) ($_GET['ujian_kolom'] ?? ''));
    // Tidak dicentang = sembunyikan kolom praktek & teori
    $ujianKolom = [
        'praktek' => false,
        'teori' => false,
    ];
    if ($ujianKolomFilter !== '') {
        $parts = array_map(static fn ($s) => strtolower(trim($s)), explode(',', $ujianKolomFilter));
        $ujianKolom = [
            'praktek' => in_array('praktek', $parts, true),
            'teori' => in_array('teori', $parts, true),
        ];
    }
    $slotShort = [
        'x_ganjil' => 'S1',
        'x_genap' => 'S2',
        'xi_ganjil' => 'S3',
        'xi_genap' => 'S4',
        'xii_ganjil' => 'S5',
        'xii_genap' => 'S6',
    ];
    $tableCols = 2 + count($visibleSlots) + 1
        + ($ujianKolom['praktek'] ? 1 : 0)
        + ($ujianKolom['teori'] ? 1 : 0)
        + 1;
    $autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

    $fmt = static function ($v): string {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '';
        }
        return number_format((float) round((float) $v), 0, ',', '');
    };

    $fmt1 = static function ($v): string {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '';
        }
        return number_format((float) round((float) $v, 1), 1, ',', '');
    };

    $esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $scoreClass = static function ($v, ?string $tingkat) use ($kktpMap): string {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '';
        }
        $tingkat = $tingkat !== null ? strtoupper($tingkat) : '';
        $thr = ($tingkat !== '' && isset($kktpMap[$tingkat])) ? $kktpMap[$tingkat] : null;
        $n = (float) round((float) $v);
        if ($thr !== null && $n < $thr) {
            return ' nilai-excel nilai-bawah-kktp';
        }
        return ' nilai-excel';
    };

    $slotTingkat = [
        'x_ganjil' => 'X', 'x_genap' => 'X',
        'xi_ganjil' => 'XI', 'xi_genap' => 'XI',
        'xii_ganjil' => 'XII', 'xii_genap' => 'XII',
    ];
    $tingkatAkhir = 'XII';
    if (preg_match('/^(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/i', (string) ($hb['kelas_akhir'] ?? ''), $m)) {
        $tingkatAkhir = strtoupper($m[1]);
    }
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo '<!DOCTYPE html><html lang="id"><meta charset="utf-8"><title>Error</title>'
        . '<body style="font-family:sans-serif;padding:2rem">'
        . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="javascript:history.back()">Kembali</a></p></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rekap Hasil Belajar — <?= $esc($hb['nama']) ?></title>
  <style>
    :root {
      --ink: #1a1a1a;
      --line: #222;
      --muted: #555;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #e8e4dc;
      color: var(--ink);
      font-family: "Times New Roman", Times, serif;
      font-size: 11pt;
      line-height: 1.25;
    }
    .toolbar {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      gap: 0.5rem;
      justify-content: flex-end;
      padding: 0.65rem 1rem;
      background: #2c2118;
      color: #fff;
    }
    .toolbar a, .toolbar button {
      font-family: system-ui, sans-serif;
      font-size: 0.85rem;
      border: 0;
      border-radius: 6px;
      padding: 0.45rem 0.85rem;
      cursor: pointer;
      text-decoration: none;
      color: #2c2118;
      background: #f3e4d2;
    }
    .toolbar button.primary { background: #c9a227; color: #1a1208; font-weight: 600; }
    .sheet {
      width: 210mm;
      max-width: 100%;
      margin: 1rem auto 2rem;
      background: #fff;
      padding: 14mm 12mm;
      box-shadow: 0 8px 28px rgba(0,0,0,.12);
    }
    h1 {
      margin: 0 0 0.85rem;
      text-align: center;
      font-size: 16pt;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .kop {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.85rem;
      margin-bottom: 0.85rem;
      padding-bottom: 0.55rem;
      border-bottom: 2.5px solid #222;
      text-align: center;
    }
    .kop .logo {
      width: 64px;
      height: 64px;
      object-fit: contain;
      flex-shrink: 0;
    }
    .kop-text {
      text-align: center;
    }
    .kop-nama {
      font-size: 14pt;
      font-weight: 700;
      letter-spacing: 0.03em;
    }
    .kop-ket {
      font-size: 9pt;
      color: #444;
    }
    .identitas {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 0.85rem;
      font-size: 11pt;
    }
    .identitas td { padding: 0.1rem 0.25rem 0.1rem 0; vertical-align: top; }
    .identitas .lab { width: 5.2rem; white-space: nowrap; }
    .identitas .sep { width: 0.7rem; }
    table.rekap {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 9.5pt;
    }
    table.rekap th, table.rekap td {
      border: 1px solid var(--line);
      padding: 0.18rem 0.22rem;
      vertical-align: middle;
    }
    table.rekap thead th {
      text-align: center;
      font-weight: 700;
      background: #f7f7f7;
    }
    table.rekap .mapel { text-align: left; width: 28%; }
    table.rekap .num { text-align: center; }
    table.rekap .group td {
      font-weight: 700;
      background: #f0f0f0;
      text-align: left;
    }
    table.rekap .subhead td {
      font-style: italic;
      font-weight: 600;
      background: #fafafa;
    }
    table.rekap tbody tr:nth-child(even):not(.group):not(.subhead):not(.jumlah) td {
      background: #fcfcfc;
    }
    table.rekap .jumlah td {
      font-weight: 700;
      background: #eee;
    }
    table.rekap .col-akhir { background: #fff8e8; font-weight: 600; }
    table.rekap .col-akhir-pending { background: #fef3c7 !important; color: #92400e; font-weight: 700; }
    table.rekap .col-akhir-teori { background: #1e3a5f !important; color: #f8fafc; font-weight: 700; }
    table.rekap .nilai-excel { color: #111; font-weight: 600; }
    table.rekap .nilai-bawah-kktp { color: #c62828 !important; font-weight: 700; }
    table.rekap .slot-off { background: #f5f5f5 !important; color: #bbb; }
    thead .col-akhir { background: #f3e4d2; }
    .note {
      margin-top: 0.65rem;
      font-size: 8.5pt;
      color: var(--muted);
    }
    .ttd {
      display: flex;
      margin-top: 1.5rem;
      page-break-inside: avoid;
    }
    .ttd-spacer { flex: 1; }
    .ttd-box {
      width: 75mm;
      text-align: center;
      font-size: 10pt;
      margin-right: 2cm;
    }
    .ttd-box p { margin: 0.15rem 0; }
    .ttd-box .ttd-nama {
      white-space: nowrap;
    }
    .ttd-space { height: 22mm; }
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .sheet {
        width: auto;
        margin: 0;
        padding: 0;
        box-shadow: none;
      }
      @page { size: A4 landscape; margin: 10mm; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="javascript:history.back()">Kembali</a>
    <button type="button" class="primary" onclick="window.print()">Cetak / PDF</button>
  </div>

  <div class="sheet">
    <?php
      $logoUrl = (string) (($hb['sekolah']['logo_url'] ?? ''));
      $cetak = $hb['cetak'] ?? [];
    ?>
    <div class="kop">
      <?php if ($logoUrl !== ''): ?>
        <img class="logo" src="<?= $esc($logoUrl) ?>" alt="Logo">
      <?php endif; ?>
      <div class="kop-text">
        <div class="kop-nama"><?= $esc(strtoupper($hb['madrasah'])) ?></div>
        <?php
          $alamatKop = trim((string) ($hb['sekolah']['alamat'] ?? ''));
          if ($alamatKop === '') {
              $alamatKop = trim((string) ($hb['sekolah']['keterangan'] ?? ''));
          }
        ?>
        <?php if ($alamatKop !== ''): ?>
          <div class="kop-ket"><?= $esc($alamatKop) ?></div>
        <?php endif; ?>
        <?php if (!empty($hb['sekolah']['keterangan']) && $alamatKop !== trim((string) $hb['sekolah']['keterangan'])): ?>
          <div class="kop-ket"><?= $esc((string) $hb['sekolah']['keterangan']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <h1>Rekap Hasil Belajar</h1>

    <table class="identitas">
      <tr>
        <td class="lab">NAMA</td><td class="sep">:</td>
        <td><strong><?= $esc(strtoupper($hb['nama'])) ?></strong></td>
        <td class="lab">Madrasah</td><td class="sep">:</td>
        <td><strong><?= $esc(strtoupper($hb['madrasah'])) ?></strong></td>
      </tr>
      <tr>
        <td class="lab">NIS</td><td class="sep">:</td>
        <td><?= $esc($hb['nis'] !== '' ? $hb['nis'] : '—') ?></td>
        <td class="lab">NISN</td><td class="sep">:</td>
        <td><?= $esc($hb['nisn']) ?></td>
      </tr>
    </table>

    <table class="rekap">
      <colgroup>
        <col style="width:3.2%">
        <col class="mapel">
        <?php foreach ($visibleSlots as $_): ?>
          <col style="width:5.2%">
        <?php endforeach; ?>
        <col style="width:6.5%">
        <?php if ($ujianKolom['praktek']): ?>
        <col style="width:7%">
        <?php endif; ?>
        <?php if ($ujianKolom['teori']): ?>
        <col style="width:6.5%">
        <?php endif; ?>
        <col style="width:6.5%">
      </colgroup>
      <thead>
        <?php if ($filteredView): ?>
        <tr>
          <th rowspan="2">No</th>
          <th rowspan="2">Mata Pelajaran</th>
          <?php foreach ($visibleSlots as $slot): ?>
            <th rowspan="2"><?= $esc($slotShort[$slot] ?? $slot) ?></th>
          <?php endforeach; ?>
          <th rowspan="2">Rata-rata</th>
          <?php if ($ujianKolom['praktek']): ?>
          <th rowspan="2">Nilai Ujian Praktek</th>
          <?php endif; ?>
          <?php if ($ujianKolom['teori']): ?>
          <th rowspan="2">Nilai Ujian</th>
          <?php endif; ?>
          <th rowspan="2" class="col-akhir">Nilai Akhir</th>
        </tr>
        <?php else: ?>
        <tr>
          <th rowspan="2">No</th>
          <th rowspan="2">Mata Pelajaran</th>
          <th colspan="2">X</th>
          <th colspan="2">XI</th>
          <th colspan="2">XII</th>
          <th rowspan="2">Rata-rata</th>
          <?php if ($ujianKolom['praktek']): ?>
          <th rowspan="2">Nilai Ujian Praktek</th>
          <?php endif; ?>
          <?php if ($ujianKolom['teori']): ?>
          <th rowspan="2">Nilai Ujian</th>
          <?php endif; ?>
          <th rowspan="2" class="col-akhir">Nilai Akhir</th>
        </tr>
        <tr>
          <th>Ganjil</th><th>Genap</th>
          <th>Ganjil</th><th>Genap</th>
          <th>Ganjil</th><th>Genap</th>
        </tr>
        <?php endif; ?>
      </thead>
      <tbody>
        <?php
        $paiCodes = ['QH', 'AA', 'FIK', 'SKI'];
        $mapelNo = 0;
        foreach ($hb['kelompok'] as $g):
            echo '<tr class="group"><td colspan="' . $tableCols . '">' . $esc($g['judul']) . '</td></tr>';

            $rows = $g['rows'];
            $paiRows = [];
            $otherRows = [];
            foreach ($rows as $r) {
                if (in_array($r['kode'], $paiCodes, true) && str_contains($g['judul'], 'Umum')) {
                    $paiRows[] = $r;
                } else {
                    $otherRows[] = $r;
                }
            }

            if ($paiRows !== []) {
                echo '<tr class="subhead"><td colspan="' . $tableCols . '">Pendidikan Agama Islam dan Budi Pekerti</td></tr>';
                foreach ($paiRows as $r) {
                    $mapelNo++;
                    renderHasilRow($r, $fmt, $esc, $scoreClass, $slotTingkat, $tingkatAkhir, $visibleSlots, $ujianKolom, $mapelNo);
                }
            }
            foreach ($otherRows as $r) {
                $mapelNo++;
                renderHasilRow($r, $fmt, $esc, $scoreClass, $slotTingkat, $tingkatAkhir, $visibleSlots, $ujianKolom, $mapelNo);
            }
        endforeach;
        ?>
        <tr class="jumlah">
          <td></td>
          <td style="text-align:right">Jumlah</td>
          <?php foreach ($visibleSlots as $slot): ?>
            <td class="num"><?= $esc($fmt($hb['jumlah_slot'][$slot] ?? null)) ?></td>
          <?php endforeach; ?>
          <td class="num"><?= $esc($fmt($hb['jumlah_rataan'])) ?></td>
          <?php if ($ujianKolom['praktek']): ?>
          <td></td>
          <?php endif; ?>
          <?php if ($ujianKolom['teori']): ?>
          <td></td>
          <?php endif; ?>
          <td class="num col-akhir"><?= $esc($fmt($hb['jumlah_akhir'])) ?></td>
        </tr>
        <tr class="jumlah">
          <td></td>
          <td style="text-align:right">Rataan</td>
          <?php foreach ($visibleSlots as $slot): ?>
            <td class="num"><?= $esc($fmt1($hb['rataan_slot'][$slot] ?? null)) ?></td>
          <?php endforeach; ?>
          <td class="num"><?= $esc($fmt1($hb['rataan_rataan'] ?? null)) ?></td>
          <?php if ($ujianKolom['praktek']): ?>
          <td></td>
          <?php endif; ?>
          <?php if ($ujianKolom['teori']): ?>
          <td></td>
          <?php endif; ?>
          <td class="num col-akhir"><?= $esc($fmt1($hb['rataan_akhir'] ?? null)) ?></td>
        </tr>
      </tbody>
    </table>

    <?php if (!empty($ujianKolom['teori'])): ?>
    <p class="note">
      Nilai akhir = gabungan rataan rapor
      (<?= $esc((string) ($hb['bobot']['rataan'] ?? 60)) ?>%)
      + ujian praktek (<?= $esc((string) ($hb['bobot']['praktek'] ?? 20)) ?>%)
      + ujian (<?= $esc((string) ($hb['bobot']['teori'] ?? 20)) ?>%)
      sesuai bobot nilai ijazah. Kolom kosong = tidak ada nilai.
    </p>
    <?php endif; ?>

    <div class="ttd">
      <div class="ttd-spacer"></div>
      <div class="ttd-box">
        <p><?= $esc((string) ($cetak['tempat_tanggal'] ?? '')) ?></p>
        <p>Kepala Madrasah,</p>
        <div class="ttd-space"></div>
        <p class="ttd-nama"><strong><?= $esc((string) ($cetak['kepala_nama'] !== '' ? $cetak['kepala_nama'] : '……………………………')) ?></strong></p>
        <?php if (($cetak['kepala_nip'] ?? '') !== ''): ?>
          <p>NIP. <?= $esc((string) $cetak['kepala_nip']) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($autoPrint): ?>
  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
  <?php endif; ?>
</body>
</html>
<?php
function renderHasilRow(
    array $r,
    callable $fmt,
    callable $esc,
    callable $scoreClass,
    array $slotTingkat,
    string $tingkatAkhir,
    array $visibleSlots,
    array $ujianKolom = ['praktek' => false, 'teori' => false],
    int $no = 0
): void {
    echo '<tr>';
    echo '<td class="num">' . $esc((string) $no) . '</td>';
    echo '<td class="mapel">' . $esc($r['nama']) . '</td>';
    foreach ($visibleSlots as $slot) {
        $v = $r['nilai'][$slot] ?? null;
        $cls = $scoreClass($v, $slotTingkat[$slot] ?? null);
        echo '<td class="num' . $cls . '">' . $esc($fmt($v)) . '</td>';
    }
    $rataan = $r['rataan'] ?? null;
    echo '<td class="num' . $scoreClass($rataan, $tingkatAkhir) . '">' . $esc($fmt($rataan)) . '</td>';
    if (!empty($ujianKolom['praktek'])) {
        echo '<td class="num">' . $esc($fmt($r['ujian_praktek'] ?? null)) . '</td>';
    }
    if (!empty($ujianKolom['teori'])) {
        echo '<td class="num">' . $esc($fmt($r['ujian'] ?? null)) . '</td>';
    }
    $hasTeori = !empty($r['has_teori']) || (($r['ujian'] ?? null) !== null && $r['ujian'] !== '');
    $akhirClass = $hasTeori ? 'col-akhir col-akhir-teori' : 'col-akhir col-akhir-pending';
    echo '<td class="num ' . $akhirClass . '">' . $esc($fmt($r['nilai_akhir'] ?? null)) . '</td>';
    echo '</tr>';
}
