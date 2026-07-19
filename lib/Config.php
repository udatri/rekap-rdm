<?php

declare(strict_types=1);

/**
 * Loader konfigurasi aplikasi (siap hosting).
 */
final class Config
{
    private static ?array $config = null;

    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $base = dirname(__DIR__);
        $defaults = [
            'source_dir' => $base . '/semua',
            'data_dir' => $base . '/data',
            'db' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'rekap_rdm',
                'user' => 'root',
                'pass' => '',
                'charset' => 'utf8mb4',
            ],
            'upload_max_mb' => 20,
            'madrasah' => 'MAN 4 Sleman',
            'allow_cloud_import' => true,
        ];

        $file = $base . '/config.php';
        $example = $base . '/config.example.php';
        if (!is_readable($file) && is_readable($example)) {
            // Bootstrap config.php dari example agar siap pakai
            @copy($example, $file);
        }

        $custom = is_readable($file) ? (require $file) : [];
        if (!is_array($custom)) {
            $custom = [];
        }

        self::$config = array_replace_recursive($defaults, $custom);
        self::$config['base_dir'] = $base;

        foreach (['source_dir', 'data_dir'] as $key) {
            $path = self::$config[$key];
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            // Usahakan writable bagi user web server (XAMPP/shared hosting)
            if (is_dir($path) && !is_writable($path)) {
                @chmod($path, 0775);
            }
        }

        // Migrasi otomatis: jika source baru kosong tapi folder lama /semua masih berisi xlsx
        $source = rtrim((string) self::$config['source_dir'], '/\\');
        $legacy = dirname(__DIR__) . '/semua';
        $sourceCount = is_dir($source) ? count(glob($source . '/*.xlsx') ?: []) : 0;
        if ($sourceCount === 0 && is_dir($legacy)) {
            $legacyFiles = glob($legacy . '/*.xlsx') ?: [];
            if ($legacyFiles !== []) {
                if (!is_dir($source)) {
                    @mkdir($source, 0777, true);
                }
                foreach ($legacyFiles as $file) {
                    $dest = $source . '/' . basename($file);
                    if (!is_file($dest)) {
                        @copy($file, $dest);
                    }
                }
            }
        }

        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cfg = self::all();
        return $cfg[$key] ?? $default;
    }

    /** Folder Excel bersama lama (sebelum multi-sekolah). */
    public static function legacySourceDir(): string
    {
        return rtrim((string) self::get('source_dir'), '/\\');
    }

    /**
     * ID sekolah aktif untuk data akademik (context admin / active_id).
     * Baca ringan tanpa memanggil SekolahStore::state() (hindari rekursi).
     */
    public static function activeSekolahId(): string
    {
        require_once __DIR__ . '/SekolahStore.php';
        $ctx = SekolahStore::contextId();
        if ($ctx !== null && $ctx !== '') {
            return self::sanitizeSchoolId($ctx);
        }
        $path = self::dataDir() . '/sekolah.json';
        if (is_readable($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            $id = trim((string) ($json['active_id'] ?? ''));
            if ($id !== '') {
                return self::sanitizeSchoolId($id);
            }
        }
        return 'SCH01';
    }

    public static function sanitizeSchoolId(string $id): string
    {
        $id = strtoupper(trim($id));
        if (preg_match('/^SCH(\d+)$/', $id, $m)) {
            return sprintf('SCH%02d', (int) $m[1]);
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?: 'SCH01';
        return $safe;
    }

    /** Root data akademik per sekolah: data/schools/SCH01/ */
    public static function schoolDataDir(?string $sekolahId = null): string
    {
        $id = self::sanitizeSchoolId($sekolahId ?? self::activeSekolahId());
        $dir = self::dataDir() . '/schools/' . $id;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        self::migrateLegacySchoolDataOnce($id);
        return $dir;
    }

    /**
     * Salin sekali data bersama lama ke sekolah utama (SCH01).
     * Sekolah lain mulai kosong — upload Excel sendiri.
     */
    private static function migrateLegacySchoolDataOnce(string $forId): void
    {
        $flag = self::dataDir() . '/schools/.legacy_migrated';
        $primary = 'SCH01';

        // Jika migrasi lama ke sekolah lain, pindahkan ke SCH01 (utama) bila SCH01 masih kosong
        if (is_file($flag)) {
            self::rehomeLegacyToPrimary($primary, $flag);
            return;
        }

        if ($forId !== $primary) {
            return;
        }

        self::copyLegacyIntoSchool($primary);
        @file_put_contents($flag, $primary . "\n" . date('c') . "\n");
        @chmod($flag, 0666);
    }

    /** Pindahkan hasil migrasi lama (mis. SCH02) ke SCH01 jika SCH01 belum punya Excel. */
    private static function rehomeLegacyToPrimary(string $primary, string $flagPath): void
    {
        $prev = trim(explode("\n", (string) @file_get_contents($flagPath), 2)[0] ?? '');
        $prev = $prev !== '' ? self::sanitizeSchoolId($prev) : '';
        if ($prev === '' || $prev === $primary) {
            return;
        }

        $primarySemua = self::dataDir() . '/schools/' . $primary . '/semua';
        $primaryCount = is_dir($primarySemua) ? count(glob($primarySemua . '/*.xlsx') ?: []) : 0;
        if ($primaryCount > 0) {
            return;
        }

        $fromRoot = self::dataDir() . '/schools/' . $prev;
        $fromSemua = $fromRoot . '/semua';
        $fromCount = is_dir($fromSemua) ? count(glob($fromSemua . '/*.xlsx') ?: []) : 0;
        if ($fromCount === 0 && !is_file($fromRoot . '/cache.json')) {
            return;
        }

        $destRoot = self::dataDir() . '/schools/' . $primary;
        $destSemua = $destRoot . '/semua';
        if (!is_dir($destSemua)) {
            @mkdir($destSemua, 0777, true);
        }

        if (is_dir($fromSemua)) {
            foreach (glob($fromSemua . '/*.xlsx') ?: [] as $file) {
                $dest = $destSemua . '/' . basename($file);
                if (!is_file($dest)) {
                    @rename($file, $dest) || @copy($file, $dest);
                }
            }
        }
        foreach ([
            'cache.json',
            'ujian.json',
            'ijazah_settings.json',
            'konversi_nilai.json',
            'rapor_nilai.json',
            'kelas.json',
        ] as $name) {
            $src = $fromRoot . '/' . $name;
            $dest = $destRoot . '/' . $name;
            if (is_file($src)) {
                @rename($src, $dest) || @copy($src, $dest);
            }
        }

        @file_put_contents($flagPath, $primary . "\n" . date('c') . "\n");
        @chmod($flagPath, 0666);
    }

    private static function copyLegacyIntoSchool(string $target): void
    {
        $destRoot = self::dataDir() . '/schools/' . $target;
        $destSemua = $destRoot . '/semua';
        if (!is_dir($destSemua)) {
            @mkdir($destSemua, 0777, true);
        }

        $legacySemua = self::legacySourceDir();
        if (is_dir($legacySemua)) {
            foreach (glob($legacySemua . '/*.xlsx') ?: [] as $file) {
                $dest = $destSemua . '/' . basename($file);
                if (!is_file($dest)) {
                    @copy($file, $dest);
                }
            }
        }

        foreach ([
            'cache.json',
            'ujian.json',
            'ijazah_settings.json',
            'konversi_nilai.json',
            'rapor_nilai.json',
            'kelas.json',
        ] as $name) {
            $src = self::dataDir() . '/' . $name;
            $dest = $destRoot . '/' . $name;
            if (is_file($src) && !is_file($dest)) {
                @copy($src, $dest);
            }
        }
    }

    /** Folder Excel sekolah aktif (kosong sampai diunggah). */
    public static function sourceDir(?string $sekolahId = null): string
    {
        $dir = rtrim(self::schoolDataDir($sekolahId), '/\\') . '/semua';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && !is_writable($dir)) {
            @chmod($dir, 0777);
        }
        return $dir;
    }

    public static function dataDir(): string
    {
        return rtrim((string) self::get('data_dir'), '/\\');
    }

    public static function cachePath(?string $sekolahId = null): string
    {
        return rtrim(self::schoolDataDir($sekolahId), '/\\') . '/cache.json';
    }

    public static function dbDsn(): string
    {
        $db = self::get('db', []);
        $host = $db['host'] ?? '127.0.0.1';
        $port = (int) ($db['port'] ?? 3306);
        $name = $db['name'] ?? 'rekap_rdm';
        $charset = $db['charset'] ?? 'utf8mb4';
        return "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    }

    public static function dbBootDsn(): string
    {
        $db = self::get('db', []);
        $host = $db['host'] ?? '127.0.0.1';
        $port = (int) ($db['port'] ?? 3306);
        $charset = $db['charset'] ?? 'utf8mb4';
        return "mysql:host={$host};port={$port};charset={$charset}";
    }

    public static function dbUser(): string
    {
        return (string) (self::get('db')['user'] ?? 'root');
    }

    public static function dbPass(): string
    {
        return (string) (self::get('db')['pass'] ?? '');
    }

    public static function dbName(): string
    {
        return (string) (self::get('db')['name'] ?? 'rekap_rdm');
    }

    public static function pdo(bool $withDb = true): PDO
    {
        $dsn = $withDb ? self::dbDsn() : self::dbBootDsn();
        try {
            return new PDO($dsn, self::dbUser(), self::dbPass(), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            if ($withDb && str_contains($e->getMessage(), 'Unknown database')) {
                $boot = self::pdo(false);
                $name = str_replace('`', '``', self::dbName());
                $boot->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                return self::pdo(true);
            }
            throw $e;
        }
    }

    public static function health(): array
    {
        $sekolahId = self::activeSekolahId();
        $source = self::sourceDir($sekolahId);
        $data = self::schoolDataDir($sekolahId);
        $dbOk = false;
        $dbError = null;
        try {
            self::pdo();
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        $files = glob($source . '/*.xlsx') ?: [];

        return [
            'sekolah_id' => $sekolahId,
            'xlsx_count' => count($files),
            'source_writable' => is_writable($source),
            'data_writable' => is_writable($data),
            'db_ok' => $dbOk,
            'upload_max_mb' => (int) self::get('upload_max_mb', 20),
            'allow_cloud_import' => (bool) self::get('allow_cloud_import', true),
            'php_upload_max_filesize' => ini_get('upload_max_filesize'),
            'php_post_max_size' => ini_get('post_max_size'),
            'php_max_file_uploads' => (int) ini_get('max_file_uploads'),
        ];
    }
}
