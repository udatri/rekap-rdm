<?php

declare(strict_types=1);

/**
 * Utilitas keamanan: CSRF, rate-limit, header, tulis JSON aman, SSRF.
 */
final class Security
{
    private const CSRF_KEY = 'rdm_csrf';
    private const LOGIN_ATTEMPTS_KEY = 'rdm_login_attempts';
    private const LOGIN_MAX_ATTEMPTS = 8;
    private const LOGIN_WINDOW_SEC = 900; // 15 menit

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $fwd === 'https';
    }

    /** Header keamanan dasar untuk respons HTML/JSON. */
    public static function sendHeaders(bool $json = false): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
            }
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public static function validateCsrf(?string $token): void
    {
        $expected = (string) ($_SESSION[self::CSRF_KEY] ?? '');
        $token = (string) $token;
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            throw new RuntimeException('CSRF');
        }
    }

    /** Rate-limit login per IP (+ username). */
    public static function assertLoginAllowed(string $username): void
    {
        $ip = self::clientIp();
        $key = $ip . '|' . strtolower(trim($username));
        $now = time();
        $store = $_SESSION[self::LOGIN_ATTEMPTS_KEY] ?? [];
        if (!is_array($store)) {
            $store = [];
        }
        // bersihkan entry lama
        foreach ($store as $k => $row) {
            if (!is_array($row) || ($now - (int) ($row['first'] ?? 0)) > self::LOGIN_WINDOW_SEC) {
                unset($store[$k]);
            }
        }
        $row = $store[$key] ?? ['first' => $now, 'count' => 0];
        if (($now - (int) $row['first']) > self::LOGIN_WINDOW_SEC) {
            $row = ['first' => $now, 'count' => 0];
        }
        if ((int) $row['count'] >= self::LOGIN_MAX_ATTEMPTS) {
            $_SESSION[self::LOGIN_ATTEMPTS_KEY] = $store;
            $wait = self::LOGIN_WINDOW_SEC - ($now - (int) $row['first']);
            throw new InvalidArgumentException(
                'Terlalu banyak percobaan login. Coba lagi dalam ' . max(1, (int) ceil($wait / 60)) . ' menit.'
            );
        }
        $_SESSION[self::LOGIN_ATTEMPTS_KEY] = $store;
    }

    public static function registerLoginFailure(string $username): void
    {
        $ip = self::clientIp();
        $key = $ip . '|' . strtolower(trim($username));
        $now = time();
        $store = $_SESSION[self::LOGIN_ATTEMPTS_KEY] ?? [];
        if (!is_array($store)) {
            $store = [];
        }
        $row = $store[$key] ?? ['first' => $now, 'count' => 0];
        if (($now - (int) $row['first']) > self::LOGIN_WINDOW_SEC) {
            $row = ['first' => $now, 'count' => 0];
        }
        $row['count'] = (int) $row['count'] + 1;
        $store[$key] = $row;
        $_SESSION[self::LOGIN_ATTEMPTS_KEY] = $store;
    }

    public static function clearLoginFailures(string $username): void
    {
        $ip = self::clientIp();
        $key = $ip . '|' . strtolower(trim($username));
        $store = $_SESSION[self::LOGIN_ATTEMPTS_KEY] ?? [];
        if (is_array($store) && isset($store[$key])) {
            unset($store[$key]);
            $_SESSION[self::LOGIN_ATTEMPTS_KEY] = $store;
        }
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
    }

    /**
     * Blokir SSRF: hanya http(s), tolak IP privat/metadata.
     */
    public static function assertSafeRemoteUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000) {
            throw new InvalidArgumentException('URL tidak valid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('URL tidak valid.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('URL harus http atau https.');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Host URL tidak diizinkan.');
        }
        // Tolak literal IP privat / metadata
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Alamat IP URL tidak diizinkan.');
            }
            if ($host === '169.254.169.254' || str_starts_with($host, '169.254.')) {
                throw new InvalidArgumentException('Alamat IP URL tidak diizinkan.');
            }
        } else {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                foreach ($resolved as $ip) {
                    if ($ip === '169.254.169.254' || str_starts_with($ip, '169.254.')) {
                        throw new InvalidArgumentException('Host URL tidak diizinkan.');
                    }
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        throw new InvalidArgumentException('Host URL tidak diizinkan.');
                    }
                }
            }
        }
    }

    /** Tulis JSON atomik (temp + rename) + flock. */
    public static function writeJsonFile(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Gagal encode JSON.');
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $fh = fopen($tmp, 'cb');
        if ($fh === false) {
            throw new RuntimeException('Gagal menulis data.');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Gagal mengunci file.');
            }
            $written = fwrite($fh, $json);
            fflush($fh);
            flock($fh, LOCK_UN);
            if ($written === false) {
                throw new RuntimeException('Gagal menulis data.');
            }
        } finally {
            fclose($fh);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Gagal menyimpan data.');
        }
        @chmod($path, 0664);
    }

    /** Aksi API yang hanya boleh GET (baca). */
    public static function isReadAction(string $action): bool
    {
        static $read = [
            'me' => true,
            'filters' => true,
            'per_semester' => true,
            'semua_semester' => true,
            'per_siswa' => true,
            'nilai_ijazah' => true,
            'ijazah_bobot' => true,
            'siswa_kelas' => true,
            'list_kelas' => true,
            'list_sekolah' => true,
            'list_import' => true,
            'health' => true,
            'list_ujian' => true,
            'get_ujian' => true,
            'ujian_templates' => true,
            'mapel_kelas' => true,
            'list_users' => true,
            'list_konversi' => true,
            'list_rapor_nilai' => true,
            'get_rapor_nilai' => true,
        ];
        return isset($read[$action]);
    }
}
