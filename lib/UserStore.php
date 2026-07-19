<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Penyimpanan akun pengguna (JSON di data/users.json).
 * Role: superadmin | admin | user
 */
final class UserStore
{
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';

    public const ROLES = [
        self::ROLE_SUPERADMIN => 'Superadmin',
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_USER => 'User',
    ];

    private string $jsonPath;

    public function __construct(?string $jsonPath = null)
    {
        $this->jsonPath = $jsonPath ?? (Config::dataDir() . '/users.json');
        $this->ensureSeeded();
        $this->ensureSchoolAdmins();
    }

    /** @return list<array> tanpa password_hash */
    public function allPublic(): array
    {
        $out = [];
        foreach ($this->read()['users'] as $u) {
            $out[] = $this->publicUser($u);
        }
        usort($out, static function ($a, $b) {
            $order = [UserStore::ROLE_SUPERADMIN => 0, UserStore::ROLE_ADMIN => 1, UserStore::ROLE_USER => 2];
            $ra = $order[$a['role']] ?? 9;
            $rb = $order[$b['role']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcasecmp($a['username'], $b['username']);
        });
        return $out;
    }

    public function findByUsername(string $username): ?array
    {
        $username = strtolower(trim($username));
        foreach ($this->read()['users'] as $u) {
            if (strtolower($u['username']) === $username) {
                return $u;
            }
        }
        return null;
    }

    public function findById(string $id): ?array
    {
        foreach ($this->read()['users'] as $u) {
            if ($u['id'] === $id) {
                return $u;
            }
        }
        return null;
    }

    public function verify(string $username, string $password): ?array
    {
        $u = $this->findByUsername($username);
        if ($u === null || empty($u['aktif'])) {
            return null;
        }
        if (!password_verify($password, (string) $u['password_hash'])) {
            return null;
        }
        return $this->publicUser($u);
    }

    /**
     * @param array{
     *   id?:string,username?:string,nama?:string,role?:string,
     *   password?:string,aktif?:bool|int|string,sekolah_id?:string
     * } $input
     * @return array publik
     */
    public function save(array $input, string $actorRole): array
    {
        $state = $this->read();
        $id = trim((string) ($input['id'] ?? ''));
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $nama = trim((string) ($input['nama'] ?? ''));
        $role = strtolower(trim((string) ($input['role'] ?? self::ROLE_USER)));
        $password = (string) ($input['password'] ?? '');
        $aktif = !isset($input['aktif']) || filter_var($input['aktif'], FILTER_VALIDATE_BOOLEAN);
        $sekolahId = trim((string) ($input['sekolah_id'] ?? ''));

        if (!isset(self::ROLES[$role])) {
            throw new InvalidArgumentException('Role tidak valid.');
        }
        if ($actorRole === self::ROLE_ADMIN && $role === self::ROLE_SUPERADMIN) {
            throw new InvalidArgumentException('Admin tidak dapat membuat/mengubah superadmin.');
        }
        if ($actorRole === self::ROLE_USER) {
            throw new InvalidArgumentException('Tidak berwenang mengelola akun.');
        }

        if ($id === '') {
            if ($username === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
                throw new InvalidArgumentException('Username 3–40 karakter (huruf kecil, angka, . _ -).');
            }
            if ($this->findByUsername($username) !== null) {
                throw new InvalidArgumentException('Username sudah dipakai.');
            }
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('Password minimal 8 karakter.');
            }
            if ($nama === '') {
                $nama = $username;
            }
            $row = [
                'id' => bin2hex(random_bytes(8)),
                'username' => $username,
                'nama' => $nama,
                'role' => $role,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'aktif' => $aktif,
                'sekolah_id' => $sekolahId,
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            $state['users'][] = $row;
            $this->write($state);
            return $this->publicUser($row);
        }

        $found = false;
        foreach ($state['users'] as &$u) {
            if ($u['id'] !== $id) {
                continue;
            }
            if ($actorRole === self::ROLE_ADMIN && ($u['role'] ?? '') === self::ROLE_SUPERADMIN) {
                throw new InvalidArgumentException('Admin tidak dapat mengubah superadmin.');
            }
            if ($nama !== '') {
                $u['nama'] = $nama;
            }
            if ($role !== '') {
                $u['role'] = $role;
            }
            if ($password !== '') {
                if (strlen($password) < 8) {
                    throw new InvalidArgumentException('Password minimal 8 karakter.');
                }
                $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $u['aktif'] = $aktif;
            $u['sekolah_id'] = $sekolahId;
            $u['updated_at'] = date('c');
            // username tidak diubah setelah dibuat
            $found = true;
            $saved = $u;
            break;
        }
        unset($u);
        if (!$found) {
            throw new InvalidArgumentException('Pengguna tidak ditemukan.');
        }
        $this->write($state);
        return $this->publicUser($saved);
    }

    public function delete(string $id, string $actorId, string $actorRole): void
    {
        if ($actorRole === self::ROLE_USER) {
            throw new InvalidArgumentException('Tidak berwenang menghapus akun.');
        }
        if ($id === $actorId) {
            throw new InvalidArgumentException('Tidak dapat menghapus akun sendiri.');
        }
        $state = $this->read();
        $target = null;
        foreach ($state['users'] as $u) {
            if ($u['id'] === $id) {
                $target = $u;
                break;
            }
        }
        if ($target === null) {
            throw new InvalidArgumentException('Pengguna tidak ditemukan.');
        }
        if ($actorRole === self::ROLE_ADMIN && ($target['role'] ?? '') === self::ROLE_SUPERADMIN) {
            throw new InvalidArgumentException('Admin tidak dapat menghapus superadmin.');
        }
        if (($target['role'] ?? '') === self::ROLE_SUPERADMIN) {
            $countSa = 0;
            foreach ($state['users'] as $u) {
                if (($u['role'] ?? '') === self::ROLE_SUPERADMIN && !empty($u['aktif'])) {
                    $countSa++;
                }
            }
            if ($countSa <= 1) {
                throw new InvalidArgumentException('Minimal harus ada satu superadmin aktif.');
            }
        }
        $state['users'] = array_values(array_filter(
            $state['users'],
            static fn ($u) => $u['id'] !== $id
        ));
        $this->write($state);
    }

    public function changePassword(string $userId, string $oldPassword, string $newPassword): void
    {
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('Password baru minimal 8 karakter.');
        }
        $state = $this->read();
        foreach ($state['users'] as &$u) {
            if ($u['id'] !== $userId) {
                continue;
            }
            if (!password_verify($oldPassword, (string) $u['password_hash'])) {
                throw new InvalidArgumentException('Password lama salah.');
            }
            $u['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            $u['updated_at'] = date('c');
            $this->write($state);
            return;
        }
        unset($u);
        throw new InvalidArgumentException('Pengguna tidak ditemukan.');
    }

    public function publicUser(array $u): array
    {
        return [
            'id' => (string) ($u['id'] ?? ''),
            'username' => (string) ($u['username'] ?? ''),
            'nama' => (string) ($u['nama'] ?? ''),
            'role' => (string) ($u['role'] ?? self::ROLE_USER),
            'role_label' => self::ROLES[$u['role'] ?? ''] ?? (string) ($u['role'] ?? ''),
            'aktif' => !empty($u['aktif']),
            'sekolah_id' => (string) ($u['sekolah_id'] ?? ''),
            'created_at' => (string) ($u['created_at'] ?? ''),
            'updated_at' => (string) ($u['updated_at'] ?? ''),
        ];
    }

    private function ensureSeeded(): void
    {
        $state = $this->read();
        if ($state['users'] !== []) {
            return;
        }
        $now = date('c');
        $defaults = [
            ['username' => 'superadmin', 'nama' => 'Super Admin', 'role' => self::ROLE_SUPERADMIN, 'password' => 'Superadmin123', 'sekolah_id' => ''],
            ['username' => 'user', 'nama' => 'Pengguna', 'role' => self::ROLE_USER, 'password' => 'User123', 'sekolah_id' => ''],
        ];
        foreach ($defaults as $d) {
            $state['users'][] = [
                'id' => bin2hex(random_bytes(8)),
                'username' => $d['username'],
                'nama' => $d['nama'],
                'role' => $d['role'],
                'password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),
                'aktif' => true,
                'sekolah_id' => $d['sekolah_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $state['seeded_at'] = $now;
        $this->write($state);
    }

    /**
     * Pastikan adminN ada untuk setiap sekolah SCH## di data/sekolah.json.
     * Password default akun baru: Admin123
     */
    private function ensureSchoolAdmins(): void
    {
        $schoolIds = $this->schoolIdsFromFile();
        if ($schoolIds === []) {
            // Fallback seed awal sebelum sekolah.json siap (100 slot)
            for ($n = 1; $n <= 100; $n++) {
                $schoolIds[] = sprintf('SCH%02d', $n);
            }
        }

        $state = $this->read();
        $now = date('c');
        $changed = false;

        foreach ($schoolIds as $sekolahId) {
            if ($this->upsertSchoolAdmin($state, $sekolahId, $now)) {
                $changed = true;
            }
        }

        // Nonaktifkan akun admin generik lama
        foreach ($state['users'] as &$u) {
            if (strtolower((string) ($u['username'] ?? '')) === 'admin' && !empty($u['aktif'])) {
                $u['aktif'] = false;
                $u['updated_at'] = $now;
                if (trim((string) ($u['sekolah_id'] ?? '')) === '') {
                    $u['sekolah_id'] = 'SCH01';
                }
                $changed = true;
            }
        }
        unset($u);

        if (!$changed && !empty($state['school_admins_seeded'])) {
            return;
        }

        $state['school_admins_seeded'] = true;
        $state['seeded_at'] = $state['seeded_at'] ?? $now;
        try {
            $this->write($state);
        } catch (Throwable) {
            $seedPath = dirname($this->jsonPath) . '/users.seed.json';
            @file_put_contents(
                $seedPath,
                json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * Reset password admin sekolah ke default (setelah expire 7 hari).
     */
    public function resetAdminPasswordForSekolah(string $sekolahId, string $password = 'Admin123'): void
    {
        $sekolahId = trim($sekolahId);
        if ($sekolahId === '' || !preg_match('/^SCH(\d+)$/i', $sekolahId, $m)) {
            return;
        }
        $username = 'admin' . (int) $m[1];
        $state = $this->read();
        $now = date('c');
        $found = false;
        foreach ($state['users'] as &$u) {
            if (strtolower((string) ($u['username'] ?? '')) !== strtolower($username)) {
                continue;
            }
            $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $u['role'] = self::ROLE_ADMIN;
            $u['aktif'] = true;
            $u['sekolah_id'] = sprintf('SCH%02d', (int) $m[1]);
            $u['updated_at'] = $now;
            $found = true;
            break;
        }
        unset($u);
        if (!$found) {
            $this->upsertSchoolAdmin($state, sprintf('SCH%02d', (int) $m[1]), $now);
        }
        $this->write($state);
    }

    /**
     * Buat/ikat akun admin untuk satu sekolah (SCH11 → admin11).
     *
     * @return array user publik
     */
    public function ensureAdminForSekolah(string $sekolahId): array
    {
        $sekolahId = trim($sekolahId);
        if ($sekolahId === '' || !preg_match('/^SCH(\d+)$/i', $sekolahId, $m)) {
            throw new InvalidArgumentException('ID sekolah tidak valid untuk admin otomatis.');
        }
        $state = $this->read();
        $now = date('c');
        $this->upsertSchoolAdmin($state, sprintf('SCH%02d', (int) $m[1]), $now);
        $this->write($state);

        $username = 'admin' . (int) $m[1];
        foreach ($state['users'] as $u) {
            if (strtolower((string) ($u['username'] ?? '')) === strtolower($username)) {
                return $this->publicUser($u);
            }
        }
        throw new RuntimeException('Gagal membuat admin sekolah.');
    }

    /**
     * @param array{users:list<array>} $state
     */
    private function upsertSchoolAdmin(array &$state, string $sekolahId, string $now): bool
    {
        if (!preg_match('/^SCH(\d+)$/i', $sekolahId, $m)) {
            return false;
        }
        $n = (int) $m[1];
        $sekolahId = sprintf('SCH%02d', $n);
        $username = 'admin' . $n;
        $changed = false;

        foreach ($state['users'] as &$u) {
            if (strtolower((string) ($u['username'] ?? '')) !== strtolower($username)) {
                continue;
            }
            if (($u['sekolah_id'] ?? '') !== $sekolahId
                || ($u['role'] ?? '') !== self::ROLE_ADMIN
                || empty($u['aktif'])
            ) {
                $u['sekolah_id'] = $sekolahId;
                $u['role'] = self::ROLE_ADMIN;
                $u['aktif'] = true;
                $u['updated_at'] = $now;
                $changed = true;
            }
            unset($u);
            return $changed;
        }
        unset($u);

        $state['users'][] = [
            'id' => bin2hex(random_bytes(8)),
            'username' => $username,
            'nama' => 'Admin Sekolah ' . $n,
            'role' => self::ROLE_ADMIN,
            'password_hash' => password_hash('Admin123', PASSWORD_DEFAULT),
            'aktif' => true,
            'sekolah_id' => $sekolahId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return true;
    }

    /** @return list<string> */
    private function schoolIdsFromFile(): array
    {
        $path = dirname($this->jsonPath) . '/sekolah.json';
        if (!is_readable($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return [];
        }
        $ids = [];
        foreach ($json['sekolah'] ?? [] as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '' && preg_match('/^SCH\d+$/i', $id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return array{users:list<array>,seeded_at?:string,school_admins_seeded?:bool} */
    private function read(): array
    {
        $seedPath = dirname($this->jsonPath) . '/users.seed.json';
        if (is_readable($seedPath)) {
            $seed = json_decode((string) file_get_contents($seedPath), true);
            if (is_array($seed) && !empty($seed['school_admins_seeded']) && is_array($seed['users'] ?? null)) {
                try {
                    $this->write([
                        'users' => $seed['users'],
                        'seeded_at' => (string) ($seed['seeded_at'] ?? date('c')),
                        'school_admins_seeded' => true,
                    ]);
                    @unlink($seedPath);
                } catch (Throwable) {
                    // pakai seed di memori
                    return [
                        'users' => $seed['users'],
                        'seeded_at' => (string) ($seed['seeded_at'] ?? ''),
                        'school_admins_seeded' => true,
                    ];
                }
            }
        }

        if (!is_readable($this->jsonPath)) {
            return ['users' => []];
        }
        $json = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($json)) {
            return ['users' => []];
        }
        $users = [];
        foreach ($json['users'] ?? [] as $u) {
            if (!is_array($u) || trim((string) ($u['id'] ?? '')) === '') {
                continue;
            }
            $users[] = $u;
        }
        return [
            'users' => $users,
            'seeded_at' => (string) ($json['seeded_at'] ?? ''),
            'school_admins_seeded' => !empty($json['school_admins_seeded']),
        ];
    }

    /** @param array{users:list<array>,seeded_at?:string,school_admins_seeded?:bool} $state */
    private function write(array $state): void
    {
        require_once __DIR__ . '/Security.php';
        $payload = [
            'users' => $state['users'] ?? [],
            'seeded_at' => $state['seeded_at'] ?? date('c'),
            'school_admins_seeded' => !empty($state['school_admins_seeded']),
        ];
        try {
            Security::writeJsonFile($this->jsonPath, $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('Gagal menyimpan data pengguna. Pastikan folder data/ writable.');
        }
    }
}
