<?php

declare(strict_types=1);

/**
 * Update kode aplikasi:
 * 1) git pull jika .git tersedia
 * 2) unduh ZIP dari GitHub jika hosting tanpa git (umum di shared hosting)
 *
 * Tidak menimpa: config.php, data/, semua/, uploads/, .git/
 */
final class AppUpdateService
{
    private const DEFAULT_REPO = 'udatri/rekap-rdm';
    private const DEFAULT_BRANCH = 'main';

    private string $root;
    private string $gitBin;
    private string $repo;
    private string $branch;
    private string $metaPath;

    public function __construct(?string $root = null)
    {
        $base = rtrim($root ?? dirname(__DIR__), '/');
        $real = realpath($base);
        $this->root = rtrim($real !== false ? $real : $base, '/');
        $this->gitBin = $this->findGit();

        $cfg = [];
        if (class_exists('Config', false) || is_readable($this->root . '/lib/Config.php')) {
            if (!class_exists('Config', false)) {
                require_once $this->root . '/lib/Config.php';
            }
            try {
                $cfg = Config::all();
            } catch (Throwable $e) {
                $cfg = [];
            }
        }
        $upd = is_array($cfg['update'] ?? null) ? $cfg['update'] : [];
        $this->repo = trim((string) ($upd['repo'] ?? self::DEFAULT_REPO)) ?: self::DEFAULT_REPO;
        $this->branch = trim((string) ($upd['branch'] ?? self::DEFAULT_BRANCH)) ?: self::DEFAULT_BRANCH;

        $dataDir = is_string($cfg['data_dir'] ?? null) && $cfg['data_dir'] !== ''
            ? rtrim((string) $cfg['data_dir'], '/')
            : ($this->root . '/data');
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $this->metaPath = $dataDir . '/app_meta.json';
    }

    /**
     * @return array<string,mixed>
     */
    public function status(bool $fetch = false): array
    {
        try {
            if ($this->gitUsable()) {
                return $this->statusGit($fetch);
            }
            return $this->statusZip($fetch);
        } catch (Throwable $e) {
            // Git gagal → mode ZIP tanpa menampilkan fatal git sebagai error utama
            try {
                return $this->statusZip($fetch);
            } catch (Throwable $e2) {
                return $this->unavailable($this->friendlyError($e2->getMessage()));
            }
        }
    }

    /**
     * @return array{message:string,updated:bool,before:?string,after:?string,log:string,app:array}
     */
    public function update(): array
    {
        if ($this->gitUsable()) {
            try {
                return $this->updateGit();
            } catch (Throwable $e) {
                // Fallback ZIP jika git pull gagal (ownership / remote)
                if (!$this->zipUpdatePossible()) {
                    throw $e;
                }
            }
        }

        if (!$this->zipUpdatePossible()) {
            throw new RuntimeException(
                'Update tidak tersedia: git tidak ada dan unduhan ZIP tidak didukung (butuh allow_url_fopen/curl + ZipArchive).'
            );
        }

        return $this->updateZip();
    }

    private function gitUsable(): bool
    {
        if ($this->gitBin === ''
            || !is_dir($this->root . '/.git')
            || !function_exists('proc_open')
        ) {
            return false;
        }
        // Folder .git kosong/rusak sering ada di hosting hasil upload — jangan paksa mode git
        try {
            $inside = trim($this->runOut($this->gitCmd(['rev-parse', '--is-inside-work-tree']), 15));
            return $inside === 'true';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function zipUpdatePossible(): bool
    {
        return class_exists('ZipArchive')
            && ($this->canHttp() || function_exists('curl_init'))
            && is_writable($this->root);
    }

    private function canHttp(): bool
    {
        $v = strtolower(trim((string) ini_get('allow_url_fopen')));
        return in_array($v, ['1', 'on', 'true', 'yes'], true);
    }

    /** @return array<string,mixed> */
    private function statusGit(bool $fetch): array
    {
        if ($fetch) {
            try {
                $this->run($this->gitCmd(['fetch', '--prune', 'origin']), 90);
            } catch (Throwable $e) {
                $fetchError = $this->friendlyError($e->getMessage());
            }
        }

        $branch = trim($this->runOut($this->gitCmd(['rev-parse', '--abbrev-ref', 'HEAD'])));
        $commitFull = trim($this->runOut($this->gitCmd(['rev-parse', 'HEAD'])));
        $commit = substr($commitFull, 0, 7);
        $message = trim($this->runOut($this->gitCmd(['log', '-1', '--pretty=%s'])));
        $committedAt = trim($this->runOut($this->gitCmd(['log', '-1', '--pretty=%cI'])));
        $remote = '';
        try {
            $remote = trim($this->runOut($this->gitCmd(['config', '--get', 'remote.origin.url'])));
        } catch (Throwable $e) {
            $remote = 'github.com/' . $this->repo;
        }

        $dirty = trim($this->runOut($this->gitCmd([
            'status', '--porcelain', '--untracked-files=no',
        ]))) !== '';

        $ahead = 0;
        $behind = 0;
        $upstream = '';
        try {
            $upstream = trim($this->runOut($this->gitCmd([
                'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}',
            ])));
        } catch (Throwable $e) {
            $upstream = ($branch !== '' && $branch !== 'HEAD') ? ('origin/' . $branch) : ('origin/' . $this->branch);
        }
        try {
            $counts = trim($this->runOut($this->gitCmd([
                'rev-list', '--left-right', '--count', 'HEAD...' . $upstream,
            ])));
            if (preg_match('/^(\d+)\s+(\d+)$/', $counts, $m)) {
                $ahead = (int) $m[1];
                $behind = (int) $m[2];
            }
        } catch (Throwable $e) {
            // ignore
        }

        $out = [
            'available' => true,
            'method' => 'git',
            'branch' => $branch !== '' ? $branch : $this->branch,
            'commit' => $commit !== '' ? $commit : null,
            'commit_full' => $commitFull !== '' ? $commitFull : null,
            'message' => $message !== '' ? $message : null,
            'committed_at' => $committedAt !== '' ? $committedAt : null,
            'remote' => $remote !== '' ? $this->maskRemote($remote) : ('github.com/' . $this->repo),
            'dirty' => $dirty,
            'ahead' => $ahead,
            'behind' => $behind,
            // Update tetap bisa dipaksa meski sudah terbaru (pull no-op)
            'can_update' => !$dirty,
        ];
        if (isset($fetchError)) {
            $out['fetch_error'] = $fetchError;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function statusZip(bool $fetch, ?string $note = null): array
    {
        if (!$this->zipUpdatePossible()) {
            $why = [];
            if (!class_exists('ZipArchive')) {
                $why[] = 'ekstensi ZipArchive belum aktif';
            }
            if (!$this->canHttp() && !function_exists('curl_init')) {
                $why[] = 'unduh HTTP tidak diizinkan (allow_url_fopen/curl)';
            }
            if (!is_writable($this->root)) {
                $why[] = 'folder aplikasi tidak writable';
            }
            if ($this->gitBin === '' || !is_dir($this->root . '/.git')) {
                $why[] = 'git/clone tidak tersedia';
            }
            return $this->unavailable(
                'Update tidak tersedia di server ini: ' . implode('; ', $why ?: ['lingkungan tidak mendukung'])
            );
        }

        $meta = $this->readMeta();
        $localFull = (string) ($meta['commit_full'] ?? '');
        $localShort = $localFull !== '' ? substr($localFull, 0, 7) : (string) ($meta['commit'] ?? '');
        $message = (string) ($meta['message'] ?? 'Versi terpasang (mode ZIP GitHub)');
        $committedAt = (string) ($meta['committed_at'] ?? ($meta['updated_at'] ?? ''));

        $behind = 0;
        $remoteFull = '';
        $remoteMsg = '';
        $remoteAt = '';
        $fetchError = $note;

        if ($fetch || $localFull === '') {
            try {
                $remote = $this->fetchGithubHead();
                $remoteFull = (string) ($remote['sha'] ?? '');
                $remoteMsg = (string) ($remote['message'] ?? '');
                $remoteAt = (string) ($remote['date'] ?? '');
                if ($localFull !== '' && $remoteFull !== '' && !str_starts_with($remoteFull, $localFull) && $remoteFull !== $localFull) {
                    $behind = 1;
                } elseif ($localFull === '' && $remoteFull !== '') {
                    $behind = 1;
                }
                if ($localFull === '' && $remoteFull !== '') {
                    // Belum ada meta lokal: tampilkan remote sebagai referensi
                    $message = $remoteMsg !== '' ? $remoteMsg : $message;
                    $committedAt = $remoteAt !== '' ? $remoteAt : $committedAt;
                }
            } catch (Throwable $e) {
                $fetchError = $this->friendlyError($e->getMessage());
            }
        }

        $out = [
            'available' => true,
            'method' => 'zip',
            'branch' => $this->branch,
            'commit' => $localShort !== '' ? $localShort : null,
            'commit_full' => $localFull !== '' ? $localFull : null,
            'message' => $message !== '' ? $message : null,
            'committed_at' => $committedAt !== '' ? $committedAt : null,
            'remote' => 'https://github.com/' . $this->repo . '/tree/' . $this->branch,
            'dirty' => false,
            'ahead' => 0,
            'behind' => $behind,
            'can_update' => true,
            'remote_commit' => $remoteFull !== '' ? substr($remoteFull, 0, 7) : null,
        ];
        if ($fetchError) {
            $out['fetch_error'] = $fetchError;
        }
        return $out;
    }

    /** @return array{message:string,updated:bool,before:?string,after:?string,log:string,app:array} */
    private function updateGit(): array
    {
        $before = $this->statusGit(false);
        if (!empty($before['dirty'])) {
            throw new RuntimeException(
                'Ada perubahan file lokal yang belum di-commit. Selesaikan/bersihkan dulu sebelum update.'
            );
        }

        $log = [];
        $beforeCommit = (string) ($before['commit_full'] ?? '');

        $fetch = $this->run($this->gitCmd(['fetch', '--prune', 'origin']), 120);
        $log[] = trim($fetch['stdout'] . "\n" . $fetch['stderr']);

        $branch = (string) ($before['branch'] ?? $this->branch);
        if ($branch === '' || $branch === 'HEAD') {
            $branch = $this->branch;
        }

        $pull = $this->run($this->gitCmd(['pull', '--ff-only', 'origin', $branch]), 120);
        $log[] = trim($pull['stdout'] . "\n" . $pull['stderr']);

        $after = $this->statusGit(false);
        $afterCommit = (string) ($after['commit_full'] ?? '');
        $updated = $beforeCommit !== '' && $afterCommit !== '' && $beforeCommit !== $afterCommit;

        $this->writeMeta([
            'commit' => $after['commit'] ?? null,
            'commit_full' => $after['commit_full'] ?? null,
            'message' => $after['message'] ?? null,
            'committed_at' => $after['committed_at'] ?? null,
            'method' => 'git',
            'updated_at' => date('c'),
        ]);

        return [
            'message' => $updated
                ? ('Aplikasi diperbarui ke ' . ($after['commit'] ?? $afterCommit) . ' (git).')
                : 'Sudah versi terbaru. Tidak ada perubahan dari remote.',
            'updated' => $updated,
            'before' => $before['commit'] ?? null,
            'after' => $after['commit'] ?? null,
            'log' => trim(implode("\n", array_filter($log))),
            'app' => $after,
        ];
    }

    /** @return array{message:string,updated:bool,before:?string,after:?string,log:string,app:array} */
    private function updateZip(): array
    {
        $before = $this->statusZip(false);
        $beforeCommit = (string) ($before['commit_full'] ?? '');
        $log = [];

        $remote = $this->fetchGithubHead();
        $remoteFull = (string) ($remote['sha'] ?? '');
        $remoteShort = $remoteFull !== '' ? substr($remoteFull, 0, 7) : '';
        $log[] = 'Remote: ' . ($remoteShort ?: '?') . ' — ' . ($remote['message'] ?? '');

        if ($beforeCommit !== '' && $remoteFull !== '' && $beforeCommit === $remoteFull) {
            return [
                'message' => 'Sudah versi terbaru (' . substr($beforeCommit, 0, 7) . ').',
                'updated' => false,
                'before' => $before['commit'] ?? null,
                'after' => $before['commit'] ?? null,
                'log' => implode("\n", $log),
                'app' => $this->statusZip(false),
            ];
        }

        $zipUrl = 'https://codeload.github.com/' . rawurlencode($this->repo)
            . '/zip/refs/heads/' . rawurlencode($this->branch);
        // fallback format
        $zipUrlAlt = 'https://github.com/' . $this->repo . '/archive/refs/heads/' . rawurlencode($this->branch) . '.zip';

        $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/rekap-rdm-upd-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Gagal membuat folder sementara untuk update.');
        }

        $zipFile = $tmpDir . '/app.zip';
        try {
            try {
                $this->download($zipUrl, $zipFile);
            } catch (Throwable $e) {
                $this->download($zipUrlAlt, $zipFile);
            }
            $log[] = 'ZIP diunduh (' . $this->formatBytes((int) filesize($zipFile)) . ').';

            $extractDir = $tmpDir . '/extract';
            @mkdir($extractDir, 0775, true);
            $zip = new ZipArchive();
            $open = $zip->open($zipFile);
            if ($open !== true) {
                throw new RuntimeException('Gagal membuka ZIP (kode ' . $open . ').');
            }
            if (!$zip->extractTo($extractDir)) {
                $zip->close();
                throw new RuntimeException('Gagal mengekstrak ZIP.');
            }
            $zip->close();

            $sourceRoot = $this->findExtractedRoot($extractDir);
            if ($sourceRoot === null) {
                throw new RuntimeException('Struktur ZIP tidak dikenali.');
            }
            $log[] = 'Sumber: ' . basename($sourceRoot);

            $copied = $this->copyTree($sourceRoot, $this->root);
            $log[] = 'File disalin: ' . $copied;

            $this->writeMeta([
                'commit' => $remoteShort !== '' ? $remoteShort : null,
                'commit_full' => $remoteFull !== '' ? $remoteFull : null,
                'message' => $remote['message'] ?? null,
                'committed_at' => $remote['date'] ?? null,
                'method' => 'zip',
                'updated_at' => date('c'),
                'repo' => $this->repo,
                'branch' => $this->branch,
            ]);

            $after = $this->statusZip(false);
            return [
                'message' => 'Aplikasi diperbarui ke ' . ($remoteShort ?: 'versi terbaru') . ' (ZIP GitHub). Hard-refresh browser.',
                'updated' => true,
                'before' => $before['commit'] ?? null,
                'after' => $remoteShort !== '' ? $remoteShort : ($after['commit'] ?? null),
                'log' => implode("\n", $log),
                'app' => $after,
            ];
        } finally {
            $this->rrmdir($tmpDir);
        }
    }

    /** @return array{sha:string,message:string,date:string} */
    private function fetchGithubHead(): array
    {
        $url = 'https://api.github.com/repos/' . $this->repo . '/commits/' . rawurlencode($this->branch);
        $raw = $this->httpGet($url, [
            'Accept: application/vnd.github+json',
            'User-Agent: rekap-rdm-updater',
        ]);
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['sha'])) {
            throw new RuntimeException('Respons GitHub API tidak valid. Cek repo publik: ' . $this->repo);
        }
        return [
            'sha' => (string) $json['sha'],
            'message' => trim((string) (($json['commit']['message'] ?? '') ?: '')),
            'date' => (string) (($json['commit']['committer']['date'] ?? $json['commit']['author']['date'] ?? '') ?: ''),
        ];
    }

    private function download(string $url, string $dest): void
    {
        $data = $this->httpGet($url, [
            'User-Agent: rekap-rdm-updater',
            'Accept: application/zip,application/octet-stream,*/*',
        ], 180);
        if ($data === '' || strlen($data) < 100) {
            throw new RuntimeException('Unduhan ZIP kosong dari ' . $url);
        }
        if (file_put_contents($dest, $data) === false) {
            throw new RuntimeException('Gagal menulis file ZIP sementara.');
        }
    }

    /** @param list<string> $headers */
    private function httpGet(string $url, array $headers = [], int $timeout = 60): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init gagal.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('HTTP gagal: ' . $err);
            }
            if ($code >= 400) {
                throw new RuntimeException('HTTP ' . $code . ' dari ' . $url);
            }
            return (string) $body;
        }

        if (!$this->canHttp()) {
            throw new RuntimeException('HTTP unduhan tidak tersedia (aktifkan curl atau allow_url_fopen).');
        }
        $hdr = '';
        foreach ($headers as $h) {
            $hdr .= $h . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $hdr,
                'timeout' => $timeout,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Gagal mengunduh: ' . $url);
        }
        return $body;
    }

    private function findExtractedRoot(string $extractDir): ?string
    {
        $entries = @scandir($extractDir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $extractDir . '/' . $e;
            if (is_dir($p) && (is_readable($p . '/index.php') || is_dir($p . '/lib'))) {
                return $p;
            }
        }
        if (is_readable($extractDir . '/index.php') || is_dir($extractDir . '/lib')) {
            return $extractDir;
        }
        return null;
    }

    private function copyTree(string $src, string $dst): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($rel === '' || $this->shouldSkip($rel)) {
                continue;
            }
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0775, true);
                }
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!@copy($item->getPathname(), $target)) {
                throw new RuntimeException('Gagal menyalin: ' . $rel);
            }
            $count++;
        }
        return $count;
    }

    private function shouldSkip(string $rel): bool
    {
        $rel = ltrim($rel, '/');
        $top = explode('/', $rel, 2)[0];
        $skipTop = [
            'data', 'semua', 'uploads', '.git', '.idea', '.vscode',
            'node_modules', 'vendor',
        ];
        if (in_array($top, $skipTop, true)) {
            return true;
        }
        if ($rel === 'config.php') {
            return true;
        }
        if (preg_match('#^reset_.*_once\.php$#', $rel)) {
            return true;
        }
        if (str_starts_with($rel, '.')) {
            // .gitignore boleh ikut; .env tidak
            if ($rel === '.env' || str_starts_with($rel, '.env.')) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function readMeta(): array
    {
        if (!is_readable($this->metaPath)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($this->metaPath), true);
        return is_array($json) ? $json : [];
    }

    /** @param array<string,mixed> $meta */
    private function writeMeta(array $meta): void
    {
        $dir = dirname($this->metaPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload === false) {
            return;
        }
        @file_put_contents($this->metaPath, $payload . "\n");
        @chmod($this->metaPath, 0664);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function formatBytes(int $n): string
    {
        if ($n < 1024) {
            return $n . ' B';
        }
        if ($n < 1048576) {
            return round($n / 1024, 1) . ' KB';
        }
        return round($n / 1048576, 1) . ' MB';
    }

    /** @return array{available:false,reason:string,method:null,branch:null,commit:null,commit_full:null,message:null,committed_at:null,remote:null,dirty:false,ahead:0,behind:0,can_update:false} */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'method' => null,
            'branch' => null,
            'commit' => null,
            'commit_full' => null,
            'message' => null,
            'committed_at' => null,
            'remote' => null,
            'dirty' => false,
            'ahead' => 0,
            'behind' => 0,
            'can_update' => false,
        ];
    }

    /** @param list<string> $args */
    private function gitCmd(array $args): array
    {
        $cmd = [$this->gitBin, '-c', 'safe.directory=' . $this->root];
        $alias = rtrim(dirname(__DIR__), '/');
        if ($alias !== '' && $alias !== $this->root) {
            $cmd[] = '-c';
            $cmd[] = 'safe.directory=' . $alias;
        }
        $cmd[] = '-c';
        $cmd[] = 'safe.directory=*';
        return array_merge($cmd, $args);
    }

    private function findGit(): string
    {
        foreach (['/usr/bin/git', '/bin/git', '/usr/local/bin/git'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        if (!function_exists('exec')) {
            return '';
        }
        $out = [];
        $code = 1;
        @exec('command -v git 2>/dev/null', $out, $code);
        if ($code === 0 && isset($out[0]) && is_executable(trim($out[0]))) {
            return trim($out[0]);
        }
        return '';
    }

    private function maskRemote(string $url): string
    {
        return (string) preg_replace('#://([^/@]+):([^/@]+)@#', '://***:***@', $url);
    }

    private function friendlyError(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return 'Gagal menjalankan update.';
        }
        if (stripos($t, 'dubious ownership') !== false) {
            return 'Git menolak folder (ownership berbeda). Update akan memakai mode ZIP bila memungkinkan.';
        }
        $lines = preg_split('/\R+/', $t) ?: [$t];
        return trim((string) ($lines[0] ?? $t));
    }

    /** @param list<string> $cmd */
    private function runOut(array $cmd, int $timeout = 30): string
    {
        return $this->run($cmd, $timeout)['stdout'];
    }

    /**
     * @param list<string> $cmd
     * @return array{stdout:string,stderr:string,code:int}
     */
    private function run(array $cmd, int $timeout = 60): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Fungsi proc_open dinonaktifkan di server.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: (isset($_SERVER['HOME']) ? (string) $_SERVER['HOME'] : '/tmp'),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0',
            'LC_ALL' => 'C.UTF-8',
            'LANG' => 'C.UTF-8',
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes, $this->root, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Gagal menjalankan: ' . implode(' ', $cmd));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $exitCode = -1;
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                if (isset($status['exitcode']) && $status['exitcode'] !== -1) {
                    $exitCode = (int) $status['exitcode'];
                }
                break;
            }
            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException('Timeout menjalankan perintah (' . $timeout . 's).');
            }
            usleep(50000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($proc);
        $code = $exitCode >= 0 ? $exitCode : (int) $closeCode;

        if ($code !== 0) {
            $err = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException($err !== '' ? $err : ('exit code ' . $code));
        }

        return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
    }
}
