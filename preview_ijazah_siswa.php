<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/RekapService.php';
require_once __DIR__ . '/lib/IjazahPreviewHelper.php';
require_once __DIR__ . '/lib/Config.php';

Auth::guardPage();

/**
 * Preview / cetak transkrip nilai ijazah (format Inggris, 2 halaman).
 * Layout disesuaikan blanko bordir — area konten di dalam margin aman.
 */
try {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('Pilih siswa (NISN) terlebih dahulu.');
    }

    $service = new RekapService();
    $data = $service->ensureData(false);
    $rekap = $service->ijazahService()->rekap($data, ['id' => $id]);
    if (($rekap['mode'] ?? '') !== 'detail' || empty($rekap['siswa'])) {
        throw new InvalidArgumentException('Data siswa tidak ditemukan.');
    }

    $s = $rekap['siswa'];
    $sekolah = $service->sekolahStore()->activeForApi();
    $cetak = $service->sekolahStore()->blokCetak();
    $madrasah = trim((string) ($sekolah['nama'] ?? ''));
    if ($madrasah === '') {
        $madrasah = trim((string) Config::get('madrasah', 'MAN 4 SLEMAN'));
    }
    $madrasahUpper = strtoupper($madrasah);
    $certTitle = IjazahPreviewHelper::certificateTitleEnglish($madrasah);
    $autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

    $mapelByCode = [];
    foreach ($s['mapel'] ?? [] as $m) {
        $mapelByCode[(string) $m['kode']] = $m;
    }
    $groups = IjazahPreviewHelper::buildTranscriptGroups($mapelByCode);
    $avg = IjazahPreviewHelper::computeAverage($s['mapel'] ?? []);
    $schoolYear = IjazahPreviewHelper::schoolYearFromRecords(
        $data['records'] ?? [],
        (string) ($s['id'] ?? $id),
        (string) ($s['kelas_akhir'] ?? '')
    );
    $q = static fn (string $k): string => trim((string) ($_GET[$k] ?? ''));
    $noIjazah = IjazahPreviewHelper::placeholder($q('no_ijazah'));
    $npsn = IjazahPreviewHelper::placeholder($q('npsn'));
    $ttl = IjazahPreviewHelper::placeholder($q('ttl'));
    $tglLulus = $q('tgl_lulus');
    $tglLulusLabel = $tglLulus !== ''
        ? IjazahPreviewHelper::formatDateEnglish($tglLulus)
        : IjazahPreviewHelper::placeholder('');
    $skNomor = IjazahPreviewHelper::placeholder($q('sk_nomor'), '……');
    $skTanggal = $q('sk_tanggal');
    $skTanggalLabel = $skTanggal !== ''
        ? IjazahPreviewHelper::formatDateEnglishLong($skTanggal)
        : IjazahPreviewHelper::placeholder('');
    $serial = IjazahPreviewHelper::placeholder($q('serial'));

    $printDate = IjazahPreviewHelper::formatDateEnglishLong((string) ($cetak['tanggal'] ?? date('Y-m-d')));
    $printPlace = trim((string) ($cetak['tempat'] ?? 'Sleman'));
    $kepala = trim((string) ($cetak['kepala_nama'] ?? ''));
    $nip = trim((string) ($cetak['kepala_nip'] ?? ''));
    $alamatRaw = trim((string) ($sekolah['alamat'] ?? $sekolah['keterangan'] ?? ''));
    $alamatLines = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $alamatRaw) ?: [])));
    if ($alamatLines === [] && $alamatRaw !== '') {
        $alamatLines = [$alamatRaw];
    }
    $alamatStreet = $alamatLines !== [] ? strtoupper($alamatLines[0]) : '';
    $alamatLoc = count($alamatLines) > 1
        ? implode(', ', array_slice($alamatLines, 1))
        : '';

    $skYear = $skTanggal !== ''
        ? date('Y', strtotime($skTanggal) ?: time())
        : '……';

    $ttlPlace = '';
    $ttlDate = '';
    $ttlIsPlaceholder = $ttl === '……………………………';
    if (!$ttlIsPlaceholder && str_contains($ttl, ',')) {
        [$ttlPlace, $ttlDate] = array_map('trim', explode(',', $ttl, 2));
    } elseif (!$ttlIsPlaceholder) {
        $ttlPlace = $ttl;
    }

    $garudaUrl = 'assets/ijazah/garuda-2.jpeg';
    $kemenagUrl = 'assets/ijazah/kemenag-emblem.png';
    $bordirUrl = 'assets/ijazah/bordir-mandala.png';

    $esc = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Transcript — <?= $esc(strtoupper($s['nama'])) ?></title>
  <style>
    :root {
      --ink: #111;
      --line: #222;
      --page-w: 210mm;
      --page-h: 297mm;
      /* Margin aman untuk blanko bordir (area dekoratif di luar kotak ini) */
      --safe-top: 26mm;
      --safe-right: 24mm;
      --safe-bottom: 22mm;
      --safe-left: 24mm;
      --font-sans: Arial, Helvetica, sans-serif;
      --font-serif: "Times New Roman", Times, serif;

      /* Halaman 1 — margin blanko bordir */
      --cert-pad-top: 35mm;
      --cert-pad-x: 35mm;
      --cert-pad-bottom: 35mm;
      --cert-garuda-w: 24mm;

      /* Halaman 2 — margin konten */
      --trans-pad-top: 15mm;
      --trans-pad-x: 20mm;
      --trans-pad-bottom: 15mm;
      --trans-logo-shift: 20mm;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #e8e4dc;
      color: var(--ink);
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10pt;
      line-height: 1.28;
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
      width: var(--page-w);
      height: var(--page-h);
      max-width: 100%;
      margin: 1rem auto;
      background: #fff;
      padding: var(--safe-top) var(--safe-right) var(--safe-bottom) var(--safe-left);
      box-shadow: 0 8px 28px rgba(0,0,0,.12);
      page-break-after: always;
      overflow: hidden;
      position: relative;
    }
    .sheet:last-child { page-break-after: auto; margin-bottom: 2rem; }

    .sheet-cert {
      padding: 0;
    }
    .sheet.sheet-transcript {
      height: auto;
      min-height: var(--page-h);
      padding: var(--trans-pad-top) var(--trans-pad-x) var(--trans-pad-bottom);
      overflow: visible;
      box-sizing: border-box;
    }

    .page-inner {
      display: flex;
      flex-direction: column;
      height: calc(var(--page-h) - var(--safe-top) - var(--safe-bottom));
      min-height: 0;
    }

    .sheet-cert .cert-canvas {
      position: relative;
      width: var(--page-w);
      height: var(--page-h);
      box-sizing: border-box;
      font-family: var(--font-sans);
      color: var(--ink);
    }
    .cert-bordir {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: fill;
      pointer-events: none;
      user-select: none;
      z-index: 0;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .sheet-cert .cert-canvas > :not(.cert-bordir) {
      z-index: 1;
    }

    /* Halaman 1: koordinat mm dari tepi halaman (padding tidak menggeser absolute) */
    .cert-abs {
      position: absolute;
      margin: 0;
      left: var(--cert-pad-x);
      right: var(--cert-pad-x);
    }
    .cert-abs-center {
      position: absolute;
      margin: 0;
      left: var(--cert-pad-x);
      right: var(--cert-pad-x);
      text-align: center;
    }

    .cert-garuda {
      position: absolute;
      top: var(--cert-pad-top);
      left: 50%;
      transform: translateX(-50%);
      width: var(--cert-garuda-w);
      height: auto;
      display: block;
      margin: 0;
    }

    .cert-t-ministry,
    .cert-t-republik {
      font-size: 12pt;
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.05;
      letter-spacing: 0.02em;
    }
    .cert-t-ministry {
      top: calc(var(--cert-pad-top) + 26.5mm);
    }
    .cert-t-republik {
      top: calc(var(--cert-pad-top) + 33mm);
    }
    .cert-t-ijazah {
      top: calc(var(--cert-pad-top) + 39.5mm);
      font-size: 13pt;
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.05;
      letter-spacing: 0.02em;
    }
    .cert-t-year {
      top: calc(var(--cert-pad-top) + 46mm);
      font-size: 10pt;
      font-weight: 700;
      text-transform: uppercase;
    }
    .cert-t-degree {
      top: calc(var(--cert-pad-top) + 55mm);
      font-size: 10pt;
      font-weight: 400;
    }
    .cert-t-degree strong { font-weight: 700; }
    .cert-t-declares {
      top: calc(var(--cert-pad-top) + 75.5mm);
      font-size: 10pt;
      font-weight: 400;
      text-transform: none;
    }
    .cert-t-name {
      top: calc(var(--cert-pad-top) + 83mm);
      font-size: 15pt;
      font-weight: 700;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      line-height: 1.1;
    }
    .cert-t-pass {
      top: calc(var(--cert-pad-top) + 128.5mm);
      font-size: 12pt;
      font-weight: 700;
      letter-spacing: 0.05em;
    }
    .cert-t-from {
      top: calc(var(--cert-pad-top) + 133.5mm);
      font-size: 10pt;
      font-weight: 400;
    }

    .cert-fields-block {
      position: absolute;
      left: var(--cert-pad-x);
      width: calc(var(--page-w) - 2 * var(--cert-pad-x));
      font-size: 10pt;
      line-height: 1.38;
    }
    .cert-fields-block-a { top: calc(var(--cert-pad-top) + 94mm); }
    .cert-fields-block-b { top: calc(var(--cert-pad-top) + 144mm); }

    .cert-fields {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .cert-fields td {
      padding: 0.35mm 0;
      vertical-align: top;
    }
    .cert-fields .label {
      width: 63mm;
      text-align: left;
      white-space: nowrap;
      padding-right: 1mm;
    }
    .cert-fields .sep {
      width: 4mm;
      text-align: left;
      padding-left: 0;
    }
    .cert-fields .value { text-align: left; }
    .cert-fields col.label-col { width: 63mm; }
    .cert-fields col.sep-col { width: 4mm; }
    .cert-fields .value-strong {
      font-weight: 700;
      text-transform: uppercase;
    }

    .cert-legal {
      top: calc(var(--cert-pad-top) + 167mm);
      font-size: 10pt;
      font-weight: 400;
      line-height: 1.42;
      text-align: justify;
    }
    .cert-legal strong { font-weight: 700; }

    .cert-photo {
      position: absolute;
      left: calc(var(--cert-pad-x) + 30mm);
      top: calc(var(--cert-pad-top) + 182mm);
      width: 24mm;
      height: 32mm;
      border: 0.35pt solid #222;
      background: #fff;
    }

    .cert-ttd {
      position: absolute;
      left: calc(var(--cert-pad-x) + 68mm);
      top: calc(var(--cert-pad-top) + 179mm);
      width: calc(var(--page-w) - 2 * var(--cert-pad-x) - 68mm);
      font-size: 10pt;
      line-height: 1.34;
      text-align: left;
    }
    .cert-ttd p { margin: 0; }
    .cert-ttd .ttd-nama {
      margin-top: calc(11mm + 2.68em);
      font-weight: 700;
      white-space: nowrap;
    }
    .cert-ttd .ttd-nip {
      margin-top: 1.5mm;
    }

    /* —— Halaman 2 —— */
    .sheet-transcript .page-inner {
      display: block;
      width: 100%;
      max-width: 100%;
      min-height: 0;
    }

    .trans-body {
      display: block;
      width: 100%;
      max-width: 100%;
    }

    .trans-kop {
      margin-bottom: 1.5mm;
      width: 100%;
      max-width: 100%;
    }
    .trans-kop-row {
      display: flex;
      align-items: center;
      gap: 3mm;
      margin-bottom: 1mm;
    }
    .emblem-kemenag {
      display: block;
      width: 16mm;
      height: auto;
      flex: 0 0 16mm;
      margin: 0 0 0 var(--trans-logo-shift);
    }
    .trans-kop-text {
      flex: 1 1 auto;
      min-width: 0;
      text-align: center;
      padding-top: 0;
    }
    .trans-ministry {
      font-size: 10pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      line-height: 1.1;
      margin-bottom: 0.4mm;
    }
    .trans-school {
      font-size: 11pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      line-height: 1.1;
      margin-bottom: 0.4mm;
    }
    .trans-addr {
      font-size: 9.5pt;
      font-style: italic;
      line-height: 1.15;
    }
    .trans-addr-street {
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .trans-addr-loc {
      text-transform: none;
      margin-top: 0.2mm;
    }

    .trans-rule {
      border: 0;
      border-top: 0.75pt solid var(--ink);
      border-bottom: 0.35pt solid var(--ink);
      height: 1.6pt;
      margin: 0 0 2mm;
    }

    .trans-head-title {
      text-align: center;
      font-size: 11pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      line-height: 1.05;
      margin-bottom: 0.3mm;
    }
    .trans-head-year {
      text-align: center;
      font-size: 10pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      line-height: 1.05;
      margin-bottom: 2mm;
    }

    table.meta {
      width: 100%;
      max-width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      margin-bottom: 2.5mm;
      font-size: 10pt;
      line-height: 1.28;
    }
    table.meta td {
      padding: 0.35mm 0;
      vertical-align: top;
    }
    table.meta col.meta-lab { width: 48%; }
    table.meta col.meta-sep { width: 4mm; }
    table.meta col.meta-val { width: auto; }
    table.meta .lab {
      text-align: left;
      padding-right: 1mm;
      white-space: nowrap;
    }
    table.meta .sep {
      text-align: left;
      padding: 0;
    }
    table.meta .val {
      text-align: left;
      word-break: break-word;
    }
    table.meta .val strong {
      font-weight: 700;
      text-transform: uppercase;
    }

    table.grades {
      width: 100%;
      max-width: 100%;
      border-collapse: collapse;
      font-size: 9pt;
      table-layout: fixed;
      line-height: 1.2;
    }
    table.grades col.col-g-idx { width: 7%; }
    table.grades col.col-g-subject { width: 49%; }
    table.grades col.col-g-num { width: 10%; }
    table.grades col.col-g-letters { width: 34%; }
    table.grades th,
    table.grades td {
      border: 0.35pt solid var(--line);
      padding: 0.45mm 1.2mm;
      vertical-align: middle;
    }
    table.grades thead th {
      text-align: center;
      font-weight: 700;
      background: #fff;
      padding-top: 0.75mm;
      padding-bottom: 0.75mm;
    }
    table.grades .col-idx {
      text-align: center;
      white-space: nowrap;
    }
    table.grades .col-subject {
      text-align: left;
    }
    table.grades .col-num {
      text-align: center;
      white-space: nowrap;
    }
    table.grades .col-letters {
      text-align: left;
      font-style: italic;
    }
    table.grades .group-title td {
      font-weight: 700;
      text-align: left;
      padding-top: 0.9mm;
      padding-bottom: 0.45mm;
      background: #fff;
    }
    table.grades tr.subhead td {
      font-weight: 700;
      background: #fff;
    }
    table.grades tr.subhead .col-subject {
      padding-left: 0.5mm;
    }
    table.grades tr.sub td.col-subject {
      padding-left: 5mm;
    }
    table.grades tr.sub td.col-idx {
      padding-left: 0.5mm;
    }
    table.grades tr.avg td {
      font-weight: 700;
    }
    table.grades tr.avg .col-subject {
      text-align: right;
      padding-right: 2mm;
      font-style: normal;
    }
    table.grades tr.avg .col-letters {
      font-style: italic;
      font-weight: 400;
    }

    .trans-footer {
      display: flex;
      justify-content: flex-end;
      align-items: flex-end;
      margin-top: 3mm;
      padding-top: 0;
      clear: both;
    }
    .trans-footer .ttd-box {
      width: 82mm;
      text-align: left;
      font-size: 10pt;
      line-height: 1.25;
    }
    .trans-footer .ttd-box p { margin: 0 0 0.5mm; }
    .trans-footer .ttd-space { height: 11mm; }

    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .sheet {
        width: var(--page-w);
        height: var(--page-h);
        margin: 0;
        box-shadow: none;
        page-break-after: always;
      }
      .sheet-cert { padding: 0; }
      .sheet.sheet-transcript {
        height: auto;
        min-height: var(--page-h);
        padding: var(--trans-pad-top) var(--trans-pad-x) var(--trans-pad-bottom);
        overflow: visible;
      }
      .sheet:last-child { page-break-after: auto; }
      @page {
        size: A4 portrait;
        margin: 0;
      }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="javascript:history.back()">Kembali</a>
    <button type="button" class="primary" onclick="window.print()">Cetak / PDF</button>
  </div>

  <!-- Halaman 1: Ijazah (layout mm dari PDF blanko) -->
  <div class="sheet sheet-cert">
    <div class="cert-canvas">
      <img class="cert-bordir" src="<?= $esc($bordirUrl) ?>" alt="" aria-hidden="true">
      <img class="cert-garuda" src="<?= $esc($garudaUrl) ?>" alt="Garuda Pancasila">

      <p class="cert-abs-center cert-t-ministry">Ministry of Religious Affairs</p>
      <p class="cert-abs-center cert-t-republik">Republik Indonesia</p>
      <p class="cert-abs-center cert-t-ijazah"><?= $esc($certTitle) ?></p>
      <p class="cert-abs-center cert-t-year">School Year <?= $esc($schoolYear) ?></p>
      <p class="cert-abs-center cert-t-degree">Degree No.: <strong><?= $esc($noIjazah) ?></strong></p>
      <p class="cert-abs-center cert-t-declares">It hereby declares that:</p>
      <p class="cert-abs-center cert-t-name"><?= $esc(strtoupper((string) $s['nama'])) ?></p>

      <div class="cert-fields-block cert-fields-block-a">
        <table class="cert-fields">
          <colgroup>
            <col class="label-col">
            <col class="sep-col">
            <col class="value-col">
          </colgroup>
          <tr>
            <td class="label">Place and date of birth</td>
            <td class="sep">:</td>
            <td class="value">
              <?php if ($ttlIsPlaceholder): ?>
                <?= $esc($ttl) ?>
              <?php else: ?>
                <?php if ($ttlPlace !== ''): ?><span class="value-strong"><?= $esc(strtoupper($ttlPlace)) ?></span><?php endif; ?><?= ($ttlPlace !== '' && $ttlDate !== '') ? ', ' : '' ?><?= $esc($ttlDate) ?>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="label">National student identification number</td>
            <td class="sep">:</td>
            <td class="value"><?= $esc((string) $s['nisn']) ?></td>
          </tr>
        </table>
      </div>

      <p class="cert-abs-center cert-t-pass">PASS</p>
      <p class="cert-abs-center cert-t-from">from,</p>

      <div class="cert-fields-block cert-fields-block-b">
        <table class="cert-fields">
          <colgroup>
            <col class="label-col">
            <col class="sep-col">
            <col class="value-col">
          </colgroup>
          <tr>
            <td class="label">Educational unit</td>
            <td class="sep">:</td>
            <td class="value value-strong"><?= $esc($madrasahUpper) ?></td>
          </tr>
          <tr>
            <td class="label">National school principal number</td>
            <td class="sep">:</td>
            <td class="value value-strong"><?= $esc($npsn) ?></td>
          </tr>
        </table>
      </div>

      <p class="cert-abs cert-legal">
        based on the decision of the head of <strong><?= $esc($madrasahUpper) ?></strong> Number <?= $esc($skNomor) ?> of
        <?= $esc($skYear) ?> dated <?= $esc($skTanggalLabel) ?> after meeting all criterias in accordance with laws and regulations
      </p>

      <div class="cert-photo" aria-hidden="true"></div>

      <div class="cert-ttd">
        <p><?= $esc($printPlace . ', ' . $printDate) ?></p>
        <p>Head of Madrasah</p>
        <p class="ttd-nama"><?= $esc($kepala !== '' ? $kepala : '……………………………') ?></p>
        <?php if ($nip !== ''): ?>
          <p class="ttd-nip">NIP. <?= $esc($nip) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Halaman 2: Transcript -->
  <div class="sheet sheet-transcript">
    <div class="page-inner">
      <div class="trans-kop">
        <div class="trans-kop-row">
          <img class="emblem-kemenag" src="<?= $esc($kemenagUrl) ?>" alt="Kementerian Agama">
          <div class="trans-kop-text">
            <div class="trans-ministry">Ministry of Religion of the Republic of Indonesia</div>
            <div class="trans-school"><?= $esc($madrasahUpper) ?></div>
            <?php if ($alamatStreet !== ''): ?>
              <div class="trans-addr trans-addr-street"><?= $esc($alamatStreet) ?></div>
            <?php endif; ?>
            <?php if ($alamatLoc !== ''): ?>
              <div class="trans-addr trans-addr-loc"><?= $esc($alamatLoc) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <hr class="trans-rule">
        <div class="trans-head-title">Transcript</div>
        <div class="trans-head-year">School Year <?= $esc($schoolYear) ?></div>
      </div>

      <div class="trans-body">
        <table class="meta">
          <colgroup>
            <col class="meta-lab">
            <col class="meta-sep">
            <col class="meta-val">
          </colgroup>
          <tr>
            <td class="lab">Education Unit</td><td class="sep">:</td>
            <td class="val"><?= $esc($madrasahUpper) ?></td>
          </tr>
          <tr>
            <td class="lab">National School Identification Number</td><td class="sep">:</td>
            <td class="val"><?= $esc($npsn) ?></td>
          </tr>
          <tr>
            <td class="lab">Full Name</td><td class="sep">:</td>
            <td class="val"><strong><?= $esc(strtoupper((string) $s['nama'])) ?></strong></td>
          </tr>
          <tr>
            <td class="lab">Place and Date of Birth</td><td class="sep">:</td>
            <td class="val"><?= $esc($ttl) ?></td>
          </tr>
          <tr>
            <td class="lab">National Student Identification Number</td><td class="sep">:</td>
            <td class="val"><?= $esc((string) $s['nisn']) ?></td>
          </tr>
          <tr>
            <td class="lab">Diploma Number</td><td class="sep">:</td>
            <td class="val"><?= $esc($noIjazah) ?></td>
          </tr>
          <tr>
            <td class="lab">Graduation Date</td><td class="sep">:</td>
            <td class="val"><?= $esc($tglLulusLabel) ?></td>
          </tr>
        </table>

        <table class="grades">
          <colgroup>
            <col class="col-g-idx">
            <col class="col-g-subject">
            <col class="col-g-num">
            <col class="col-g-letters">
          </colgroup>
          <thead>
            <tr>
              <th class="col-idx" rowspan="2"></th>
              <th class="col-subject" rowspan="2">Subjects</th>
              <th class="col-value" colspan="2">Value</th>
            </tr>
            <tr>
              <th class="col-num">Numbers</th>
              <th class="col-letters">Letters</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $g): ?>
              <tr class="group-title">
                <td colspan="4"><?= $esc($g['title']) ?></td>
              </tr>
              <?php foreach ($g['rows'] as $row): ?>
                <?php
                  $kind = (string) ($row['kind'] ?? 'item');
                  $trClass = $kind === 'sub' ? 'sub' : ($kind === 'heading' ? 'subhead' : '');
                  $label = (string) ($row['label'] ?? '');
                  if ($kind === 'sub') {
                      $labelText = $label . '.';
                  } else {
                      $labelText = $label;
                  }
                  $showScore = $kind !== 'heading';
                ?>
                <tr class="<?= $esc($trClass) ?>">
                  <td class="col-idx"><?= $esc($labelText) ?></td>
                  <td class="col-subject"><?= $esc((string) ($row['name'] ?? '')) ?></td>
                  <td class="col-num"><?= $showScore && ($row['score'] ?? null) !== null ? $esc((string) $row['score']) : '' ?></td>
                  <td class="col-letters"><?= $showScore ? $esc((string) ($row['score_words'] ?? '')) : '' ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($avg !== null): ?>
              <tr class="avg">
                <td class="col-idx"></td>
                <td class="col-subject">Average</td>
                <td class="col-num"><?= $esc(IjazahPreviewHelper::formatAverage($avg)) ?></td>
                <td class="col-letters"><?= $esc(IjazahPreviewHelper::averageToEnglish($avg)) ?></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="trans-footer">
          <div class="ttd-box">
            <p><?= $esc($printPlace . ', ' . $printDate) ?></p>
            <p>Head of Madrasah</p>
            <div class="ttd-space"></div>
            <p><strong><?= $esc($kepala !== '' ? $kepala : '……………………………') ?></strong></p>
            <?php if ($nip !== ''): ?>
              <p>NIP. <?= $esc($nip) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($autoPrint): ?>
  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
  <?php endif; ?>
</body>
</html>
