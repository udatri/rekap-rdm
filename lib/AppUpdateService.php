<?php

declare(strict_types=1);

/**
 * Status & update kode aplikasi dari remote git (origin).
 * Data lokal (config.php, data/, semua/) tidak ikut karena di-.gitignore.
 */
final class AppUpdateService
{
    private string $root;
    private string $gitBin;

    public function __construct(?string $root = null)
    {
        $base = rtrim($root ?? dirname(__DIR__), '/');
        $real = realpath($base);
        $this->root = rtrim($real !== false ? $real : $base, '/');
        $this->gitBin = $this->findGit();
    }

    /** @param list<string> $args */
    private function gitCmd(array $args): array
    {
        // Lewati proteksi "dubious ownership" saat PHP jalan sebagai user web (daemon/www-data)
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

    /**
     * @return array{
     *   available:bool,
     *   reason?:string,
     *   branch:?string,
     *   commit:?string,
     *   commit_full:?string,
     *   message:?string,
     *   committed_at:?string,
     *   remote:?string,
     *   dirty:bool,
     *   ahead:int,
     *   behind:int,
     *   can_update:bool
     * }
     */
    public function status(bool $fetch = false): array
    {
        try {
            return $this->statusUnsafe($fetch);
        } catch (Throwable $e) {
            return $this->unavailable($this->friendlyGitError($e->getMessage()));
        }
    }

    /**
     * @return array{
     *   available:bool,
     *   reason?:string,
     *   branch:?string,
     *   commit:?string,
     *   commit_full:?string,
     *   message:?string,
     *   committed_at:?string,
     *   remote:?string,
     *   dirty:bool,
     *   ahead:int,
     *   behind:int,
     *   can_update:bool,
     *   fetch_error?:string
     * }
     */
    private function statusUnsafe(bool $fetch = false): array
    {
        if ($this->gitBin === '') {
            return $this->unavailable('Perintah git tidak tersedia di server.');
        }
        if (!is_dir($this->root . '/.git')) {
            return $this->unavailable('Folder aplikasi bukan salinan git (tidak ada .git).');
        }

        if ($fetch) {
            try {
                $this->run($this->gitCmd(['fetch', '--prune', 'origin']), 90);
            } catch (Throwable $e) {
                // Status tetap ditampilkan; fetch gagal dicatat di reason soft
                $fetchError = $this->friendlyGitError($e->getMessage());
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
            $remote = '';
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
            $upstream = ($branch !== '' && $branch !== 'HEAD') ? ('origin/' . $branch) : 'origin/main';
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
            // upstream belum ada / belum fetch
        }

        $out = [
            'available' => true,
            'branch' => $branch !== '' ? $branch : null,
            'commit' => $commit !== '' ? $commit : null,
            'commit_full' => $commitFull !== '' ? $commitFull : null,
            'message' => $message !== '' ? $message : null,
            'committed_at' => $committedAt !== '' ? $committedAt : null,
            'remote' => $remote !== '' ? $this->maskRemote($remote) : null,
            'dirty' => $dirty,
            'ahead' => $ahead,
            'behind' => $behind,
            'can_update' => !$dirty && $behind > 0,
        ];
        if (isset($fetchError)) {
            $out['fetch_error'] = $fetchError;
        }
        return $out;
    }

    /**
     * @return array{
     *   message:string,
     *   updated:bool,
     *   before:?string,
     *   after:?string,
     *   log:string,
     *   app:array
     * }
     */
    public function update(): array
    {
        $before = $this->status(false);
        if (!$before['available']) {
            throw new RuntimeException((string) ($before['reason'] ?? 'Update tidak tersedia.'));
        }
        if (!empty($before['dirty'])) {
            throw new RuntimeException(
                'Ada perubahan file lokal yang belum di-commit. Selesaikan/bersihkan dulu sebelum update.'
            );
        }

        $log = [];
        $beforeCommit = (string) ($before['commit_full'] ?? '');

        $fetch = $this->run($this->gitCmd(['fetch', '--prune', 'origin']), 120);
        $log[] = trim($fetch['stdout'] . "\n" . $fetch['stderr']);

        $branch = (string) ($before['branch'] ?? 'main');
        if ($branch === '' || $branch === 'HEAD') {
            $branch = 'main';
        }

        // Fast-forward saja — aman, tidak overwrite divergensi lokal
        $pull = $this->run($this->gitCmd(['pull', '--ff-only', 'origin', $branch]), 120);
        $log[] = trim($pull['stdout'] . "\n" . $pull['stderr']);

        $after = $this->status(false);
        $afterCommit = (string) ($after['commit_full'] ?? '');
        $updated = $beforeCommit !== '' && $afterCommit !== '' && $beforeCommit !== $afterCommit;

        $msg = $updated
            ? ('Aplikasi diperbarui ke ' . ($after['commit'] ?? $afterCommit) . '.')
            : 'Sudah versi terbaru. Tidak ada perubahan dari remote.';

        return [
            'message' => $msg,
            'updated' => $updated,
            'before' => $before['commit'] ?? null,
            'after' => $after['commit'] ?? null,
            'log' => trim(implode("\n", array_filter($log))),
            'app' => $after,
        ];
    }

    /** @return array{available:false,reason:string,branch:null,commit:null,commit_full:null,message:null,committed_at:null,remote:null,dirty:false,ahead:0,behind:0,can_update:false} */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
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

    private function findGit(): string
    {
        foreach (['/usr/bin/git', '/bin/git', '/usr/local/bin/git', 'git'] as $bin) {
            if ($bin === 'git') {
                break;
            }
            if (is_executable($bin)) {
                return $bin;
            }
        }
        if (!function_exists('proc_open') || !function_exists('exec')) {
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
        // Sembunyikan kredensial di URL https://user:token@host/...
        return (string) preg_replace('#://([^/@]+):([^/@]+)@#', '://***:***@', $url);
    }

    private function friendlyGitError(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return 'Gagal menjalankan git.';
        }
        if (stripos($t, 'dubious ownership') !== false) {
            return 'Git menolak folder (ownership berbeda dari user web server). Sudah dicoba lewati via safe.directory; jika masih gagal, jalankan di server: git config --global --add safe.directory '
                . $this->root;
        }
        // Ambil baris inti, buang saran panjang git
        $lines = preg_split('/\R+/', $t) ?: [$t];
        $first = trim((string) ($lines[0] ?? $t));
        return $first !== '' ? $first : $t;
    }

    /** @param list<string> $cmd */
    private function runOut(array $cmd, int $timeout = 30): string
    {
        $r = $this->run($cmd, $timeout);
        return $r['stdout'];
    }

    /**
     * @param list<string> $cmd
     * @return array{stdout:string,stderr:string,code:int}
     */
    private function run(array $cmd, int $timeout = 60): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Fungsi proc_open dinonaktifkan di server; update via UI tidak bisa dijalankan.');
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

        $proc = @proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->root,
            $env,
            ['bypass_shell' => true]
        );
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
                // exitcode valid hanya sekali setelah proses selesai
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
                throw new RuntimeException('Timeout menjalankan git (' . $timeout . 's).');
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
            if ($err === '') {
                $err = 'exit code ' . $code;
            }
            throw new RuntimeException($err);
        }

        return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
    }
}
