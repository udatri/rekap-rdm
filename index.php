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
  <link rel="stylesheet" href="assets/style.css?v=20260801w" />
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
          </div>
          <button type="button" class="btn ghost" id="btnRefresh" title="Muat ulang dari Excel">Sinkronkan Excel</button>
          <form method="post" action="logout.php" class="logout-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
            <button type="submit" class="btn btn-logout" title="Keluar dari aplikasi">Keluar</button>
          </form>
        </div>
      </header>

      <nav class="tabs" role="tablist" aria-label="Menu utama">
        <div class="tabs-main">
          <button type="button" class="tab" data-mode="impor_data" role="tab" aria-selected="false" data-cap="impor">Impor data</button>
          <button type="button" class="tab" data-mode="kktp" role="tab" aria-selected="false" data-cap="view_rekap">KKTP</button>
          <button type="button" class="tab active" data-mode="per_semester" role="tab" aria-selected="true" data-cap="view_rekap">Rekap per semester</button>
          <button type="button" class="tab" data-mode="semua_semester" role="tab" aria-selected="false" data-cap="view_rekap">Semua semester</button>
          <button type="button" class="tab" data-mode="per_siswa" role="tab" aria-selected="false" data-cap="view_rekap">Per siswa</button>
          <button type="button" class="tab" data-mode="ujian_praktek" role="tab" aria-selected="false" data-cap="ujian">Ujian praktek</button>
          <button type="button" class="tab" data-mode="ujian_teori" role="tab" aria-selected="false" data-cap="ujian">Ujian teori</button>
          <button type="button" class="tab" data-mode="nilai_ijazah" role="tab" aria-selected="false" data-cap="view_rekap">Nilai ijazah</button>
          <button type="button" class="tab" data-mode="kelola_kelas" role="tab" aria-selected="false" data-cap="kelas">Kelola kelas</button>
          <button type="button" class="tab" data-mode="pengaturan_sekolah" role="tab" aria-selected="false" data-cap="sekolah">Pengaturan sekolah</button>
          <button type="button" class="tab" data-mode="kelola_user" role="tab" aria-selected="false" data-cap="users">Kelola pengguna</button>
        </div>
        <button type="button" class="tab tab-help" id="btnPetunjuk" title="Petunjuk penggunaan aplikasi">Petunjuk Penggunaan</button>
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
        <button type="button" class="btn ghost" id="btnCariSiswa" title="Cari siswa by nama / NISN">Cari siswa</button>
        <button type="button" class="btn primary" id="btnApply">Tampilkan</button>
        <button type="button" class="btn ghost" id="btnReset">Reset</button>
      </div>
    </section>

    <div id="siswaSearchModal" class="modal" hidden>
      <div class="modal-backdrop" data-siswa-search-close></div>
      <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="siswaSearchTitle">
        <div class="modal-head">
          <h2 id="siswaSearchTitle">Cari siswa</h2>
          <button type="button" class="btn ghost btn-sm" data-siswa-search-close aria-label="Tutup">✕</button>
        </div>
        <label class="siswa-search-field">
          <span>Nama / NISN / NIS</span>
          <input type="search" id="siswaSearchInput" placeholder="Ketik minimal 1 huruf…" autocomplete="off" />
        </label>
        <div id="siswaSearchMeta" class="muted siswa-search-meta">Ketikan untuk mencari.</div>
        <ul id="siswaSearchResults" class="siswa-search-results" role="listbox" aria-label="Hasil pencarian"></ul>
      </div>
    </div>

    <div id="petunjukModal" class="modal" hidden>
      <div class="modal-backdrop" data-petunjuk-close></div>
      <div class="modal-card modal-card-wide" role="dialog" aria-modal="true" aria-labelledby="petunjukTitle">
        <div class="modal-head">
          <h2 id="petunjukTitle">Petunjuk Penggunaan</h2>
          <button type="button" class="btn ghost btn-sm" data-petunjuk-close aria-label="Tutup">✕</button>
        </div>
        <div class="petunjuk-body">
          <section>
            <h3>Tentang aplikasi</h3>
            <p>Aplikasi olah nilai ijazah dari leger RDM. Siapkan file Excel leger RDM seangkatan dari kelas X sampai kelas XII dalam 1 folder (untuk MI kelas I–VI, MTs kelas VII–IX).</p>
          </section>
          <section>
            <h3>1. Persiapan data</h3>
            <ul>
              <li>Unggah file Excel rapor lewat menu <strong>Impor data</strong>, atau letakkan di folder <code>semua/</code>.</li>
              <li>Klik <strong>Sinkronkan Excel</strong> di header agar data terbaru terbaca.</li>
              <li>Atur nama &amp; logo sekolah di <strong>Pengaturan sekolah</strong>.</li>
            </ul>
          </section>
          <section>
            <h3>2. Filter &amp; pencarian</h3>
            <ul>
              <li>Pilih <strong>tahun ajaran</strong>, <strong>semester</strong>, dan <strong>kelas/tingkat</strong> sebelum menampilkan rekap agar aplikasi tetap cepat.</li>
              <li>Gunakan tombol <strong>Cari siswa</strong> untuk mencari nama / NISN / NIS, lalu langsung buka rekap per siswa.</li>
            </ul>
          </section>
          <section>
            <h3>3. Menu utama</h3>
            <ul>
              <li><strong>KKTP</strong> — isi kriteria ketuntasan per tingkat (default 75). Interval predikat menyesuaikan otomatis.</li>
              <li><strong>Rekap per semester</strong> — nilai mapel per kelas. Urutkan dengan tombol ↑↓ di judul kolom.</li>
              <li><strong>Semua semester</strong> — ringkasan lintas semester (pilih tahun atau kelas dulu).</li>
              <li><strong>Per siswa</strong> — detail satu siswa, termasuk preview hasil belajar &amp; cetak.</li>
              <li><strong>Ujian praktek / teori</strong> — buat sesi ujian, isi nilai, atau impor dari template Excel.</li>
              <li><strong>Nilai ijazah</strong> — gabungan rataan rapor + praktek + teori. Font nilai hitam; merah jika di bawah KKTP.</li>
            </ul>
          </section>
          <section>
            <h3>4. Warna nilai</h3>
            <ul>
              <li><strong>Hitam</strong> — Cukup (≥ KKTP).</li>
              <li><strong>Merah</strong> — Belum Tercapai (di bawah KKTP).</li>
              <li><strong>Biru</strong> — Baik · <strong>Hijau</strong> — Sangat Baik (interval mengikuti KKTP).</li>
              <li>Di nilai ijazah: <strong>hitam</strong> ≥ KKTP, <strong>merah</strong> di bawah KKTP. Latar kuning/biru tipis = status teori.</li>
            </ul>
          </section>
          <section>
            <h3>5. Akun login</h3>
            <ul>
              <li>Admin sekolah: <code>admin1</code>, <code>admin2</code>, … dengan password default <code>Admin123</code>.</li>
              <li>Kelola akun di menu <strong>Kelola pengguna</strong> (admin / superadmin).</li>
            </ul>
          </section>
          <p class="muted petunjuk-foot">Aplikasi Rekap RDM dibuat oleh <strong>DIAL</strong>.</p>
        </div>
      </div>
    </div>

    <main class="content">
      <div id="status" class="status" hidden></div>
      <div id="view" class="view"></div>
    </main>

    <footer class="footer">
      <p class="footer-credit">Aplikasi ini dibuat oleh <strong>DIAL</strong></p>
      <p>Sumber data: folder <code>semua/</code> · Filter: tahun ajaran, semester, kelas, ID siswa</p>
    </footer>
  </div>
  <script src="assets/app.js?v=20260801w" defer></script>
</body>
</html>
