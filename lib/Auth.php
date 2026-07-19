<?php

declare(strict_types=1);

require_once __DIR__ . '/UserStore.php';
require_once __DIR__ . '/Security.php';

/**
 * Autentikasi berbasis session PHP.
 */
final class Auth
{
    private const SESSION_KEY = 'rdm_auth_user';

    private static ?UserStore $users = null;
    private static bool $started = false;

    public static function users(): UserStore
    {
        if (self::$users === null) {
            self::$users = new UserStore();
        }
        return self::$users;
    }

    public static function startSession(): void
    {
        if (self::$started) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('RDMSESSID');
            $opts = [
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                'cookie_lifetime' => 0,
            ];
            if (Security::isHttps()) {
                $opts['cookie_secure'] = true;
            }
            session_start($opts);
        }
        self::$started = true;
    }

    public static function login(string $username, string $password): array
    {
        self::startSession();
        $username = trim($username);
        Security::assertLoginAllowed($username);
        $user = self::users()->verify($username, $password);
        if ($user === null) {
            Security::registerLoginFailure($username);
            throw new InvalidArgumentException('Username atau password salah.');
        }
        Security::clearLoginFailures($username);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $user;
        // Rotasi CSRF setelah login
        unset($_SESSION['rdm_csrf']);
        Security::csrfToken();
        self::applySekolahContext($user);
        return $user;
    }

    public static function logout(): void
    {
        self::startSession();
        unset($_SESSION[self::SESSION_KEY]);
        unset($_SESSION['rdm_csrf']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function user(): ?array
    {
        self::startSession();
        $u = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($u) || empty($u['id'])) {
            return null;
        }
        $fresh = self::users()->findById((string) $u['id']);
        if ($fresh === null || empty($fresh['aktif'])) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        $pub = self::users()->publicUser($fresh);
        $_SESSION[self::SESSION_KEY] = $pub;
        self::applySekolahContext($pub);
        return $pub;
    }

    /** Ikat konteks sekolah untuk admin (SCH01 ← admin1, dst.). */
    public static function applySekolahContext(?array $user = null): void
    {
        require_once __DIR__ . '/SekolahStore.php';
        $u = $user ?? self::userWithoutContextRefresh();
        if (!is_array($u)) {
            SekolahStore::setContextId(null);
            return;
        }
        $role = (string) ($u['role'] ?? '');
        $sid = trim((string) ($u['sekolah_id'] ?? ''));
        if ($role !== UserStore::ROLE_SUPERADMIN && $sid !== '') {
            SekolahStore::setContextId($sid);
        }
    }

    private static function userWithoutContextRefresh(): ?array
    {
        self::startSession();
        $u = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($u) && !empty($u['id']) ? $u : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if ($u === null) {
            throw new RuntimeException('UNAUTHORIZED');
        }
        return $u;
    }

    /** @param list<string> $roles */
    public static function requireRole(array $roles): array
    {
        $u = self::requireLogin();
        if (!in_array($u['role'], $roles, true)) {
            throw new RuntimeException('FORBIDDEN');
        }
        return $u;
    }

    public static function can(string $capability, ?array $user = null): bool
    {
        $u = $user ?? self::user();
        if ($u === null) {
            return false;
        }
        $role = $u['role'] ?? UserStore::ROLE_USER;
        $map = [
            'view_rekap' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN, UserStore::ROLE_USER],
            'export' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN, UserStore::ROLE_USER],
            'ujian' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
            'impor' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
            'kelas' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
            'sekolah' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
            'sekolah_manage' => [UserStore::ROLE_SUPERADMIN],
            'users' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
            'users_superadmin' => [UserStore::ROLE_SUPERADMIN],
            'bobot_ijazah' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
            'olah_rapor' => [UserStore::ROLE_SUPERADMIN, UserStore::ROLE_ADMIN],
        ];
        return in_array($role, $map[$capability] ?? [], true);
    }

    public static function guardPage(): array
    {
        $u = self::user();
        if ($u === null) {
            $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: login.php?next=' . rawurlencode($next));
            exit;
        }
        return $u;
    }
}
