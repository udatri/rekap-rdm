-- =============================================================================
-- Rekap RDM — contoh skema MySQL/MariaDB
-- Sesuai tabel yang dibuat otomatis oleh aplikasi (lihat lib/*Store.php).
--
-- Cara pakai (XAMPP / hosting):
--   1. Sesuaikan nama database / user di config.php
--   2. Import file ini lewat phpMyAdmin, atau:
--        mysql -u root -p < sql/rekap_rdm.example.sql
--   3. Aplikasi juga bisa membuat tabel sendiri saat pertama kali dipakai
--      (CREATE TABLE IF NOT EXISTS). File ini berguna untuk setup manual.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE DATABASE IF NOT EXISTS `rekap_rdm`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `rekap_rdm`;

-- -----------------------------------------------------------------------------
-- Master kelas (opsional). Kelas dari Excel tetap digabung di daftar aplikasi.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rdm_kelas` (
  `id` VARCHAR(32) NOT NULL,
  `nama` VARCHAR(40) NOT NULL,
  `tingkat` VARCHAR(10) NOT NULL DEFAULT '',
  `tahun_ajaran` VARCHAR(9) NOT NULL DEFAULT '',
  `keterangan` VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rdm_kelas_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Sesi ujian (payload JSON berisi daftar siswa & nilai per mapel)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rdm_ujian` (
  `id` VARCHAR(32) NOT NULL,
  `jenis` VARCHAR(20) NOT NULL,
  `kelas` VARCHAR(40) NOT NULL,
  `tahun_ajaran` VARCHAR(9) NOT NULL DEFAULT '',
  `semester` VARCHAR(20) NOT NULL DEFAULT '',
  `judul` VARCHAR(160) NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `payload` LONGTEXT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rdm_ujian_jenis` (`jenis`),
  KEY `idx_rdm_ujian_kelas` (`kelas`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Pengaturan aplikasi (mis. bobot nilai ijazah)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rdm_settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` LONGTEXT NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DATA CONTOH (aman dihapus / diganti)
-- =============================================================================

INSERT INTO `rdm_kelas` (`id`, `nama`, `tingkat`, `tahun_ajaran`, `keterangan`, `created_at`)
VALUES
  ('KLS01', 'X.A', 'X', '2025/2026', 'Contoh kelas X', NOW()),
  ('KLS02', 'XI.A', 'XI', '2025/2026', 'Contoh kelas XI', NOW()),
  ('KLS03', 'XII.A', 'XII', '2025/2026', 'Contoh kelas XII', NOW()),
  ('KLS04', '1 A', '', '2025/2026', 'Contoh nama kelas numerik dari Excel', NOW()),
  ('KLS05', '8 C', '', '2025/2026', 'Contoh nama kelas numerik dari Excel', NOW())
ON DUPLICATE KEY UPDATE
  `tingkat` = VALUES(`tingkat`),
  `tahun_ajaran` = VALUES(`tahun_ajaran`),
  `keterangan` = VALUES(`keterangan`);

INSERT INTO `rdm_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES
  (
    'ijazah_bobot',
    '{"rataan":60,"praktek":20,"teori":20}',
    NOW()
  )
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `updated_at` = VALUES(`updated_at`);

-- Contoh sesi ujian (payload disederhanakan)
INSERT INTO `rdm_ujian` (
  `id`, `jenis`, `kelas`, `tahun_ajaran`, `semester`, `judul`, `updated_at`, `payload`
) VALUES (
  'UJN_CONTOH_01',
  'sumatif',
  'X.A',
  '2025/2026',
  'ganjil',
  'Contoh Ujian Sumatif X.A — BTAQ & PRA',
  NOW(),
  '{
    "id": "UJN_CONTOH_01",
    "jenis": "sumatif",
    "kelas": "X.A",
    "tahun_ajaran": "2025/2026",
    "semester": "ganjil",
    "judul": "Contoh Ujian Sumatif X.A — BTAQ & PRA",
    "mapel": ["BTAQ", "PRA", "P5", "KO"],
    "siswa": [
      {
        "nisn": "0012345678",
        "nama": "Ahmad Contoh",
        "nilai": {"BTAQ": 88, "PRA": 85, "P5": 90, "KO": 87}
      },
      {
        "nisn": "0012345679",
        "nama": "Siti Contoh",
        "nilai": {"BTAQ": 92, "PRA": 80, "P5": 88, "KO": 91}
      }
    ]
  }'
)
ON DUPLICATE KEY UPDATE
  `judul` = VALUES(`judul`),
  `updated_at` = VALUES(`updated_at`),
  `payload` = VALUES(`payload`);

-- Selesai.
-- Catatan: data akademik utama (rapor Excel, users, sekolah) tetap di folder data/
-- sebagai JSON; MySQL di sini terutama untuk kelas, ujian, dan settings.
