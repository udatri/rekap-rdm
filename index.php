<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Security.php';
Auth::guardPage();
Security::sendHeaders(false);
$csrf = Security::csrfToken();
?><!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
  <title>Rekap RDM — MAN 4 Sleman</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css?v=20260719c" />
</head>
<body>
  <div class="app">
    <div class="app-chrome">
      <header class="topbar">
        <div class="brand">
          <img id="sekolahLogo" class="brand-logo" src="" alt="" hidden />
          <div class="brand-mark" id="brandMark" aria-hidden="true">R</div>
          <div>
            <p class="brand-kicker">Rekap Nilai RDM</p>
            <h1 id="madrasahName">MAN 4 Sleman</h1>
          </div>
        </div>
        <div class="topbar-actions">
          <label class="sekolah-switch" id="sekolahSwitchWrap" hidden>
            <span>Sekolah</span>
            <select id="fSekolahAktif" title="Pilih sekolah aktif"></select>
          </label>
          <span class="meta" id="importMeta">Memuat data…</span>
          <div class="user-chip" id="userChip" hidden>
            <span id="userChipName">—</span>
            <span class="badge" id="userChipRole">—</span>
            <form method="post" action="logout.php" style="display:inline;margin:0">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
              <button type="submit" class="btn ghost btn-sm">Keluar</button>
            </form>
          </div>
          <button type="button" class="btn ghost" id="btnRefresh" title="Muat ulang dari Excel">Sinkronkan Excel</button>
        </div>
      </header>

      <nav class="tabs" role="tablist" aria-label="Menu utama">
        <button type="button" class="tab active" data-mode="per_semester" role="tab" aria-selected="true" data-cap="view_rekap">Rekap per semester</button>
        <button type="button" class="tab" data-mode="semua_semester" role="tab" aria-selected="false" data-cap="view_rekap">Semua semester</button>
        <button type="button" class="tab" data-mode="per_siswa" role="tab" aria-selected="false" data-cap="view_rekap">Per siswa</button>
        <button type="button" class="tab" data-mode="ujian_praktek" role="tab" aria-selected="false" data-cap="ujian">Ujian praktek</button>
        <button type="button" class="tab" data-mode="ujian_teori" role="tab" aria-selected="false" data-cap="ujian">Ujian teori</button>
        <button type="button" class="tab" data-mode="nilai_ijazah" role="tab" aria-selected="false" data-cap="view_rekap">Nilai ijazah</button>
        <button type="button" class="tab" data-mode="impor_data" role="tab" aria-selected="false" data-cap="impor">Impor data</button>
        <button type="button" class="tab" data-mode="kelola_kelas" role="tab" aria-selected="false" data-cap="kelas">Kelola kelas</button>
        <button type="button" class="tab" data-mode="pengaturan_sekolah" role="tab" aria-selected="false" data-cap="sekolah">Pengaturan sekolah</button>
        <button type="button" class="tab" data-mode="kelola_user" role="tab" aria-selected="false" data-cap="users">Kelola pengguna</button>
      </nav>
    </div>

    <section class="filters" id="filterPanel" aria-label="Filter rekap">
      <label>
        <span>Tahun ajaran</span>
        <select id="fTahun">
          <option value="">Semua</option>
        </select>
      </label>
      <label>
        <span>Semester</span>
        <select id="fSemester">
          <option value="">Semua</option>
          <option value="Ganjil">Ganjil</option>
          <option value="Genap">Genap</option>
        </select>
      </label>
      <label>
        <span>Kelas</span>
        <select id="fKelas">
          <option value="">Semua</option>
        </select>
      </label>
      <label class="grow">
        <span>ID siswa (NISN)</span>
        <select id="fSiswa">
          <option value="">Semua siswa</option>
        </select>
      </label>
      <div class="filter-actions">
        <button type="button" class="btn primary" id="btnApply">Tampilkan</button>
        <button type="button" class="btn ghost" id="btnReset">Reset</button>
      </div>
    </section>

    <main class="content">
      <div id="status" class="status" hidden></div>
      <div id="view" class="view"></div>
    </main>

    <footer class="footer">
      <p class="footer-credit">Aplikasi ini dibuat oleh <strong>DIAL</strong></p>
      <p>Sumber data: folder <code>semua/</code> · Filter: tahun ajaran, semester, kelas, ID siswa</p>
    </footer>
  </div>
  <script src="assets/app.js?v=20260719e"></script>
</body>
</html>
