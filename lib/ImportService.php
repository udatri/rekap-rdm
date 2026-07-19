<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Impor file Excel dari komputer (upload) atau cloud (URL).
 */
final class ImportService
{
    private string $dir;

    public function __construct(?string $sourceDir = null)
    {
        $dir = $sourceDir !== null && $sourceDir !== '' ? $sourceDir : Config::sourceDir();
        $this->dir = rtrim($dir, '/\\');
        $this->ensureWritableDir();
    }

    /** Pastikan folder sumber ada dan dapat ditulis web server. */
    private function ensureWritableDir(): void
    {
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0777, true) && !is_dir($this->dir)) {
                throw new RuntimeException('Tidak dapat membuat folder sumber: ' . $this->dir);
            }
        }
        if (!is_writable($this->dir)) {
            @chmod($this->dir, 0777);
        }
        if (!is_writable($this->dir)) {
            throw new RuntimeException(
                'Folder sumber belum dapat ditulis: ' . $this->dir
                . '. Jalankan: chmod 777 "' . $this->dir . '" atau php fix_permissions.php'
            );
        }
    }

    /** @return list<array{name:string,size:int,mtime:int,mtime_label:string}> */
    public function listFiles(): array
    {
        $files = glob($this->dir . '/*.xlsx') ?: [];
        $out = [];
        foreach ($files as $path) {
            if (str_starts_with(basename($path), '~$')) {
                continue;
            }
            $out[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'mtime' => (int) filemtime($path),
                'mtime_label' => date('Y-m-d H:i', filemtime($path) ?: time()),
            ];
        }
        usort($out, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @param array<string,mixed> $fileItem satu elemen $_FILES
     * @return array{name:string,size:int}
     */
    public function uploadFile(array $fileItem): array
    {
        $this->ensureWritableDir();

        if (($fileItem['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage((int) ($fileItem['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $tmp = (string) ($fileItem['tmp_name'] ?? '');
        $original = (string) ($fileItem['name'] ?? 'upload.xlsx');
        $size = (int) ($fileItem['size'] ?? 0);
        $maxBytes = ((int) Config::get('upload_max_mb', 20)) * 1024 * 1024;

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('File upload tidak valid.');
        }
        if ($size <= 0 || $size > $maxBytes) {
            throw new InvalidArgumentException('Ukuran file melebihi batas ' . Config::get('upload_max_mb', 20) . ' MB.');
        }

        $safeName = $this->sanitizeFilename($original);
        if (!preg_match('/\.xlsx$/i', $safeName)) {
            throw new InvalidArgumentException('Hanya file .xlsx yang diizinkan.');
        }

        $this->assertXlsx($tmp);

        // Timpa file dengan nama sama (sinkron ulang), jangan buat duplikat (2)/(1)(1)
        $target = $this->dir . '/' . $safeName;
        if (is_file($target)) {
            @unlink($target);
        }

        $moved = @move_uploaded_file($tmp, $target);
        if (!$moved) {
            // Fallback: copy lalu hapus tmp (beberapa hosting membatasi move)
            $moved = @copy($tmp, $target);
            if ($moved) {
                @unlink($tmp);
            }
        }
        if (!$moved || !is_file($target)) {
            throw new RuntimeException(
                'Gagal menyimpan file ke folder sumber (' . $this->dir . '). '
                . 'Pastikan folder writable: chmod 777 data/semua'
            );
        }
        @chmod($target, 0666);

        return [
            'name' => basename($target),
            'size' => (int) filesize($target),
        ];
    }

    /**
     * Unggah banyak file.
     *
     * @return array{saved:list<array{name:string,size:int}>,errors:list<string>}
     */
    public function uploadMany(array $filesField): array
    {
        $saved = [];
        $errors = [];

        // Normalisasi struktur $_FILES multi
        $items = [];
        if (isset($filesField['name']) && is_array($filesField['name'])) {
            foreach ($filesField['name'] as $i => $name) {
                $items[] = [
                    'name' => $name,
                    'type' => $filesField['type'][$i] ?? '',
                    'tmp_name' => $filesField['tmp_name'][$i] ?? '',
                    'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $filesField['size'][$i] ?? 0,
                ];
            }
        } elseif (isset($filesField['name'])) {
            $items[] = $filesField;
        }

        foreach ($items as $item) {
            if (($item['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $saved[] = $this->uploadFile($item);
            } catch (Throwable $e) {
                $errors[] = ($item['name'] ?? 'file') . ': ' . $e->getMessage();
            }
        }

        if ($saved === [] && $errors === []) {
            throw new InvalidArgumentException('Tidak ada file yang diunggah.');
        }

        return compact('saved', 'errors');
    }

    /**
     * Impor dari URL cloud / direct link.
     *
     * @return array{name:string,size:int,source_url:string}
     */
    public function importFromUrl(string $url, ?string $filename = null): array
    {
        $this->ensureWritableDir();

        if (!Config::get('allow_cloud_import', true)) {
            throw new RuntimeException('Impor cloud dinonaktifkan di konfigurasi.');
        }

        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL tidak valid.');
        }
        require_once __DIR__ . '/Security.php';
        Security::assertSafeRemoteUrl($url);

        $resolved = $this->resolveCloudUrl($url);
        Security::assertSafeRemoteUrl($resolved);
        $binary = $this->download($resolved);
        if ($binary === '' || strlen($binary) < 100) {
            throw new RuntimeException('Unduhan kosong atau gagal. Pastikan tautan cloud bersifat publik.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rdm');
        if ($tmp === false) {
            throw new RuntimeException('Tidak dapat membuat file sementara.');
        }
        file_put_contents($tmp, $binary);

        try {
            $this->assertXlsx($tmp);
            $name = $filename !== null && trim($filename) !== ''
                ? $this->sanitizeFilename($filename)
                : $this->guessFilename($url, $resolved);
            if (!preg_match('/\.xlsx$/i', $name)) {
                $name .= '.xlsx';
            }
            $target = $this->dir . '/' . $name;
            if (is_file($target)) {
                @unlink($target);
            }
            if (!rename($tmp, $target) && !copy($tmp, $target)) {
                throw new RuntimeException('Gagal menyimpan file hasil unduhan cloud ke ' . $this->dir);
            }
            @unlink($tmp);
            @chmod($target, 0666);

            return [
                'name' => basename($target),
                'size' => (int) filesize($target),
                'source_url' => $url,
            ];
        } catch (Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }

    public function deleteFile(string $name): void
    {
        $safe = basename($name);
        if ($safe === '' || $safe !== $name || !preg_match('/\.xlsx$/i', $safe)) {
            throw new InvalidArgumentException('Nama file tidak valid.');
        }
        $path = $this->dir . '/' . $safe;
        if (!is_file($path)) {
            throw new InvalidArgumentException('File tidak ditemukan.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Gagal menghapus file.');
        }
    }

    /**
     * Hapus semua file .xlsx di folder sumber.
     *
     * @return array{deleted:int,failed:list<string>}
     */
    public function deleteAllFiles(): array
    {
        $deleted = 0;
        $failed = [];
        foreach ($this->listFiles() as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name === '') {
                continue;
            }
            try {
                $this->deleteFile($name);
                $deleted++;
            } catch (Throwable $e) {
                $failed[] = $name . ': ' . $e->getMessage();
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    public function sourceDir(): string
    {
        return $this->dir;
    }

    private function resolveCloudUrl(string $url): string
    {
        // Google Drive: /file/d/ID/ atau open?id=ID
        if (preg_match('#drive\.google\.com/file/d/([^/]+)#i', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id=' . rawurlencode($m[1]);
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m) && str_contains($url, 'drive.google.com')) {
            return 'https://drive.google.com/uc?export=download&id=' . rawurlencode($m[1]);
        }

        // Dropbox: dl=0 -> dl=1
        if (str_contains($url, 'dropbox.com')) {
            if (str_contains($url, 'dl=0')) {
                return str_replace('dl=0', 'dl=1', $url);
            }
            if (!str_contains($url, 'dl=1')) {
                return $url . (str_contains($url, '?') ? '&' : '?') . 'dl=1';
            }
        }

        // OneDrive: pakai download=1 bila memungkinkan
        if (str_contains($url, '1drv.ms') || str_contains($url, 'onedrive.live.com')) {
            if (!str_contains($url, 'download=1')) {
                return $url . (str_contains($url, '?') ? '&' : '?') . 'download=1';
            }
        }

        return $url;
    }

    private function download(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'RekapRDM-Importer/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('Gagal mengunduh: ' . $err);
            }
            if ($code >= 400) {
                throw new RuntimeException('Cloud mengembalikan HTTP ' . $code . '. Pastikan tautan publik.');
            }
            return (string) $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'user_agent' => 'RekapRDM-Importer/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Gagal mengunduh dari URL (allow_url_fopen/curl diperlukan di hosting).');
        }
        return $body;
    }

    private function assertXlsx(string $path): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new InvalidArgumentException('Tidak dapat membaca file.');
        }
        $magic = fread($fh, 4);
        fclose($fh);
        // XLSX = ZIP (PK\x03\x04)
        if ($magic === false || !str_starts_with($magic, 'PK')) {
            throw new InvalidArgumentException('File bukan Excel .xlsx yang valid.');
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace(["\0", '\\'], '', $name));
        $name = preg_replace('/[^\w\s.\-()]+/u', '', $name) ?? 'upload.xlsx';
        $name = trim($name);
        if ($name === '' || $name === '.xlsx') {
            $name = 'upload.xlsx';
        }
        return $name;
    }

    private function uniquePath(string $filename): string
    {
        $target = $this->dir . '/' . $filename;
        if (!file_exists($target)) {
            return $target;
        }
        $base = preg_replace('/\.xlsx$/i', '', $filename) ?? 'upload';
        $i = 1;
        do {
            $candidate = $this->dir . '/' . $base . '(' . $i . ').xlsx';
            $i++;
        } while (file_exists($candidate));
        return $candidate;
    }

    private function guessFilename(string $originalUrl, string $resolvedUrl): string
    {
        $path = parse_url($originalUrl, PHP_URL_PATH) ?: parse_url($resolvedUrl, PHP_URL_PATH) ?: '';
        $base = basename((string) $path);
        if ($base !== '' && preg_match('/\.xlsx$/i', $base)) {
            return $this->sanitizeFilename(urldecode($base));
        }
        return 'cloud-import-' . date('Ymd-His') . '.xlsx';
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File terlalu besar menurut pengaturan server.',
            UPLOAD_ERR_PARTIAL => 'File hanya terunggah sebagian.',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file di server.',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
            default => 'Gagal mengunggah file (kode ' . $code . ').',
        };
    }
}
