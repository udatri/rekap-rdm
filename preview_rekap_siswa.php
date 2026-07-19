<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';
require_once __DIR__ . '/lib/Config.php';

Auth::guardPage();

/**
 * Preview / cetak REKAP HASIL BELAJAR per siswa.
 * Query: id (NISN), print=1 untuk auto-print
 */
try {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('Pilih siswa (NISN) terlebih dahulu.');
    }

    $service = new RekapService();
    $data = $service->ensureData(false);
    $rekap = $service->rekapPerSiswa($data, ['id' => $id]);
    if (!empty($rekap['error']) || empty($rekap['siswa']['hasil_belajar'])) {
        throw new InvalidArgumentException($rekap['error'] ?? 'Data siswa tidak ditemukan.');
    }

    $s = $rekap['siswa'];
    $hb = $s['hasil_belajar'];
    $autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

    $fmt = static function ($v): string {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '';
        }
        return number_format((float) $v, 1, ',', '');
    };

    $esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $slotLabels = [
        'x_ganjil' => ['X', 'Ganjil'],
        'x_genap' => ['X', 'Genap'],
        'xi_ganjil' => ['XI', 'Ganjil'],
        'xi_genap' => ['XI', 'Genap'],
        'xii_ganjil' => ['XII', 'Ganjil'],
        'xii_genap' => ['XII', 'Genap'],
    ];
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
        <col class="mapel">
        <?php foreach ($slotLabels as $_): ?>
          <col style="width:5.2%">
        <?php endforeach; ?>
        <col style="width:6.5%">
        <col style="width:7%">
        <col style="width:6.5%">
        <col style="width:6.5%">
      </colgroup>
      <thead>
        <tr>
          <th rowspan="2">Mata Pelajaran</th>
          <th colspan="2">X</th>
          <th colspan="2">XI</th>
          <th colspan="2">XII</th>
          <th rowspan="2">Rata-rata</th>
          <th rowspan="2">Nilai Ujian Praktek</th>
          <th rowspan="2">Nilai Ujian</th>
          <th rowspan="2" class="col-akhir">Nilai Akhir</th>
        </tr>
        <tr>
          <th>Ganjil</th><th>Genap</th>
          <th>Ganjil</th><th>Genap</th>
          <th>Ganjil</th><th>Genap</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $paiCodes = ['QH', 'AA', 'FIK', 'SKI'];
        foreach ($hb['kelompok'] as $g):
            echo '<tr class="group"><td colspan="11">' . $esc($g['judul']) . '</td></tr>';

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
                echo '<tr class="subhead"><td colspan="11">Pendidikan Agama Islam dan Budi Pekerti</td></tr>';
                foreach ($paiRows as $r) {
                    renderHasilRow($r, $fmt, $esc);
                }
            }
            foreach ($otherRows as $r) {
                renderHasilRow($r, $fmt, $esc);
            }
        endforeach;
        ?>
        <tr class="jumlah">
          <td colspan="7" style="text-align:right">Jumlah</td>
          <td class="num"><?= $esc($fmt($hb['jumlah_rataan'])) ?></td>
          <td></td>
          <td></td>
          <td class="num col-akhir"><?= $esc($fmt($hb['jumlah_akhir'])) ?></td>
        </tr>
      </tbody>
    </table>

    <p class="note">
      Nilai akhir = gabungan rataan rapor
      (<?= $esc((string) ($hb['bobot']['rataan'] ?? 60)) ?>%)
      + ujian praktek (<?= $esc((string) ($hb['bobot']['praktek'] ?? 20)) ?>%)
      + ujian (<?= $esc((string) ($hb['bobot']['teori'] ?? 20)) ?>%)
      sesuai bobot nilai ijazah. Kolom kosong = tidak ada nilai.
    </p>

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
function renderHasilRow(array $r, callable $fmt, callable $esc): void
{
    $slots = ['x_ganjil', 'x_genap', 'xi_ganjil', 'xi_genap', 'xii_ganjil', 'xii_genap'];
    echo '<tr>';
    echo '<td class="mapel">' . $esc($r['nama']) . '</td>';
    foreach ($slots as $slot) {
        echo '<td class="num">' . $esc($fmt($r['nilai'][$slot] ?? null)) . '</td>';
    }
    echo '<td class="num">' . $esc($fmt($r['rataan'] ?? null)) . '</td>';
    echo '<td class="num">' . $esc($fmt($r['ujian_praktek'] ?? null)) . '</td>';
    echo '<td class="num">' . $esc($fmt($r['ujian'] ?? null)) . '</td>';
    echo '<td class="num col-akhir">' . $esc($fmt($r['nilai_akhir'] ?? null)) . '</td>';
    echo '</tr>';
}
