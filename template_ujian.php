<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';
require_once __DIR__ . '/lib/UjianStore.php';
require_once __DIR__ . '/lib/Config.php';

Auth::guardPage();
if (!Auth::can('ujian')) {
    http_response_code(403);
    echo 'Tidak berwenang.';
    exit;
}

$base = __DIR__;
$service = new RekapService();

$id = trim((string) ($_GET['id'] ?? ''));
$blank = isset($_GET['blank']) && $_GET['blank'] === '1';
$jenis = trim((string) ($_GET['jenis'] ?? 'praktek'));

$ujian = null;
if ($id !== '') {
    $ujian = $service->ujianStore()->get($id);
    if ($ujian === null) {
        http_response_code(404);
        echo 'Ujian tidak ditemukan.';
        exit;
    }
    $jenis = $ujian['jenis'];
} else {
    $templates = UjianStore::TEMPLATES;
    if (!isset($templates[$jenis])) {
        $jenis = 'praktek';
    }
    $tpl = $templates[$jenis];
    $mapelKode = trim((string) ($_GET['mapel'] ?? ''));
    $mapelNama = UjianStore::MAPEL[$mapelKode] ?? $mapelKode;
    $ujian = [
        'jenis' => $jenis,
        'judul' => $tpl['nama'] . ($mapelNama !== '' ? ' — ' . $mapelNama : ''),
        'mapel' => $mapelKode,
        'mapel_nama' => $mapelNama,
        'kelas' => $_GET['kelas'] ?? '',
        'tahun_ajaran' => $_GET['tahun_ajaran'] ?? '',
        'semester' => $_GET['semester'] ?? '',
        'tanggal' => $_GET['tanggal'] ?? date('Y-m-d'),
        'penguji' => $_GET['penguji'] ?? '',
        'keterangan' => '',
        'warna' => $tpl['warna'],
        'warna_soft' => $tpl['warna_soft'],
        'siswa' => [],
    ];

    $kelas = trim((string) ($ujian['kelas'] ?? ''));
    if ($kelas !== '') {
        try {
            $data = $service->ensureData();
            $siswa = $service->siswaByKelas($data, [
                'kelas' => $kelas,
                'tahun_ajaran' => $ujian['tahun_ajaran'],
                'semester' => $ujian['semester'],
            ]);
            foreach ($siswa as $s) {
                $ujian['siswa'][] = [
                    'nis' => $s['nis'],
                    'nisn' => $s['nisn'],
                    'nama' => $s['nama'],
                    'jk' => $s['jk'],
                    'nilai_akhir' => null,
                    'catatan' => '',
                ];
            }
        } catch (Throwable) {
        }
    }

    if ($ujian['siswa'] === []) {
        for ($i = 0; $i < 30; $i++) {
            $ujian['siswa'][] = [
                'nis' => '',
                'nisn' => '',
                'nama' => '',
                'jk' => '',
                'nilai_akhir' => null,
                'catatan' => '',
            ];
        }
    }
}

$tplMeta = UjianStore::TEMPLATES[$jenis] ?? UjianStore::TEMPLATES['praktek'];
$warna = $ujian['warna'] ?? $tplMeta['warna'];
$warnaSoft = $ujian['warna_soft'] ?? $tplMeta['warna_soft'];
$jenisLabel = $tplMeta['nama'];
$mapelLabel = $ujian['mapel_nama'] ?? ($ujian['mapel'] ?? '');
$siswa = $ujian['siswa'] ?? [];
$hideNilai = $blank;

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function fmtVal($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($jenisLabel) ?><?= $mapelLabel !== '' ? ' — ' . h($mapelLabel) : '' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --ink: #15241d;
      --muted: #5a6b63;
      --line: #c5d2cb;
      --warna: <?= h($warna) ?>;
      --warna-soft: <?= h($warnaSoft) ?>;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "DM Sans", system-ui, sans-serif;
      color: var(--ink);
      background:
        radial-gradient(900px 400px at 0% 0%, color-mix(in srgb, var(--warna) 18%, white), transparent 60%),
        #eef2f0;
    }
    .toolbar {
      width: min(210mm, calc(100% - 24px));
      margin: 14px auto 0;
      display: flex;
      gap: 8px;
      justify-content: flex-end;
    }
    .toolbar button {
      border: 1px solid #ccd6d1;
      background: #fff;
      padding: 8px 14px;
      border-radius: 10px;
      cursor: pointer;
      font: inherit;
      font-weight: 600;
    }
    .toolbar button.primary {
      background: var(--warna);
      color: #fff;
      border-color: var(--warna);
    }
    .sheet {
      width: min(210mm, calc(100% - 24px));
      min-height: 297mm;
      margin: 12px auto 24px;
      background: #fff;
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 18px 40px rgba(20, 40, 30, 0.12);
    }
    .hero {
      background: linear-gradient(135deg, var(--warna), color-mix(in srgb, var(--warna) 70%, #0a2030));
      color: #fff;
      padding: 22px 26px 20px;
      position: relative;
    }
    .hero::after {
      content: "";
      position: absolute;
      right: -40px;
      top: -40px;
      width: 160px;
      height: 160px;
      border-radius: 50%;
      background: rgba(255,255,255,0.12);
    }
    .hero-kicker {
      margin: 0 0 6px;
      font-size: 0.78rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      opacity: 0.9;
      font-weight: 700;
    }
    .hero h1 {
      margin: 0;
      font-family: "Source Serif 4", Georgia, serif;
      font-size: 1.85rem;
      font-weight: 700;
      line-height: 1.15;
      position: relative;
      z-index: 1;
    }
    .hero-mapel {
      display: inline-block;
      margin-top: 10px;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,0.18);
      font-weight: 700;
      font-size: 0.92rem;
      position: relative;
      z-index: 1;
    }
    .body {
      padding: 18px 22px 28px;
    }
    .meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 18px;
      margin-bottom: 16px;
      padding: 14px;
      border-radius: 14px;
      background: var(--warna-soft);
      border: 1px solid color-mix(in srgb, var(--warna) 18%, white);
    }
    .meta-item {
      display: grid;
      gap: 2px;
    }
    .meta-item span {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--muted);
      font-weight: 700;
    }
    .meta-item strong {
      font-size: 0.98rem;
      font-weight: 700;
      min-height: 1.2em;
      border-bottom: 1px dashed color-mix(in srgb, var(--warna) 35%, #ccc);
    }
    table.grid {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
    }
    table.grid th, table.grid td {
      border: 1px solid var(--line);
      padding: 7px 8px;
    }
    table.grid th {
      background: var(--warna);
      color: #fff;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    table.grid tbody tr:nth-child(even) {
      background: color-mix(in srgb, var(--warna-soft) 70%, white);
    }
    .c { text-align: center; }
    .n { text-align: right; font-variant-numeric: tabular-nums; }
    .nilai-col {
      width: 72px;
      background: color-mix(in srgb, var(--warna-soft) 80%, white) !important;
      font-weight: 700;
    }
    th.nilai-col { background: color-mix(in srgb, var(--warna) 85%, #000) !important; }
    .sign {
      margin-top: 28px;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 28px;
      text-align: center;
      font-size: 0.92rem;
    }
    .sign .space { height: 68px; }
    .sign .name {
      font-weight: 700;
      color: var(--warna);
    }
    .footer-note {
      margin-top: 14px;
      font-size: 0.78rem;
      color: var(--muted);
    }
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .sheet {
        margin: 0;
        width: auto;
        min-height: auto;
        border-radius: 0;
        box-shadow: none;
      }
      .hero { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      table.grid th, table.grid tbody tr:nth-child(even), .nilai-col, .meta {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button type="button" class="primary" onclick="window.print()">Cetak / PDF</button>
  </div>

  <div class="sheet">
    <header class="hero">
      <p class="hero-kicker">MAN 4 Sleman · Lembar Penilaian</p>
      <h1><?= h($jenisLabel) ?></h1>
      <?php if ($mapelLabel !== ''): ?>
        <div class="hero-mapel"><?= h($mapelLabel) ?><?= ($ujian['mapel'] ?? '') !== '' && ($ujian['mapel'] ?? '') !== $mapelLabel ? ' (' . h($ujian['mapel']) . ')' : '' ?></div>
      <?php endif; ?>
    </header>

    <div class="body">
      <div class="meta">
        <div class="meta-item">
          <span>Kelas</span>
          <strong><?php
            $kelasTampil = RekapService::kelasFilterLabel((string) ($ujian['kelas'] ?? ''));
            echo h($kelasTampil !== '—' ? $kelasTampil : '………………');
          ?></strong>
        </div>
        <div class="meta-item">
          <span>Tahun ajaran</span>
          <strong><?= h($ujian['tahun_ajaran'] ?: '………………') ?></strong>
        </div>
        <div class="meta-item">
          <span>Semester</span>
          <strong><?= h($ujian['semester'] ?: '………………') ?></strong>
        </div>
        <div class="meta-item">
          <span>Tanggal ujian</span>
          <strong><?= h($ujian['tanggal'] ?: '………………') ?></strong>
        </div>
        <div class="meta-item">
          <span>Penguji / Guru mapel</span>
          <strong><?= h($ujian['penguji'] ?: '………………') ?></strong>
        </div>
        <div class="meta-item">
          <span>Jumlah siswa</span>
          <strong><?= count($siswa) ?></strong>
        </div>
      </div>

      <table class="grid">
        <thead>
          <tr>
            <th class="c" style="width:36px">No</th>
            <th style="width:78px">NIS</th>
            <th style="width:100px">NISN</th>
            <th>Nama siswa</th>
            <th class="c" style="width:36px">JK</th>
            <th class="c" style="width:56px">Kelas</th>
            <th class="c nilai-col">Nilai akhir</th>
            <th style="width:120px">Catatan</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($siswa as $i => $s): ?>
            <tr>
              <td class="c"><?= $i + 1 ?></td>
              <td><?= h($s['nis'] ?? '') ?></td>
              <td><?= h($s['nisn'] ?? '') ?></td>
              <td><?= h($s['nama'] ?? '') ?></td>
              <td class="c"><?= h($s['jk'] ?? '') ?></td>
              <td class="c"><?php
                $sk = trim((string) ($s['kelas'] ?? ''));
                if ($sk === '') {
                    $sk = !str_starts_with((string) ($ujian['kelas'] ?? ''), 'tingkat:')
                        ? (string) ($ujian['kelas'] ?? '')
                        : '';
                }
                echo h($sk);
              ?></td>
              <td class="c nilai-col"><?= $hideNilai ? '' : fmtVal($s['nilai_akhir'] ?? null) ?></td>
              <td><?= $hideNilai ? '' : h($s['catatan'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!empty($ujian['keterangan'])): ?>
        <p class="footer-note"><?= h($ujian['keterangan']) ?></p>
      <?php endif; ?>

      <div class="sign">
        <div>
          <div>Mengetahui,</div>
          <div>Kepala Madrasah</div>
          <div class="space"></div>
          <div class="name">________________________</div>
        </div>
        <div>
          <div>Sleman, ................</div>
          <div>Penguji / Guru Mapel</div>
          <div class="space"></div>
          <div class="name"><?= ($ujian['penguji'] ?? '') !== '' ? h($ujian['penguji']) : '________________________' ?></div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
