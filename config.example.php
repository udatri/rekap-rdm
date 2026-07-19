<?php

declare(strict_types=1);

/**
 * Contoh konfigurasi untuk hosting.
 * Salin file ini menjadi config.php lalu sesuaikan nilainya.
 */
return [
    // Folder sumber Excel legger (upload/impor cloud).
    // Disarankan di dalam data/ agar mudah writable di hosting/XAMPP.
    'source_dir' => __DIR__ . '/data/semua',

    // Folder data aplikasi (cache, settings, impor) — harus writable
    'data_dir' => __DIR__ . '/data',

    // Database (kosongkan user/pass sesuai hosting)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'rekap_rdm',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    // Batas unggah (MB)
    'upload_max_mb' => 20,

    // Nama madrasah default
    'madrasah' => 'MAN 4 Sleman',

    // Izinkan impor dari URL cloud
    'allow_cloud_import' => true,
];
