<?php
/**
 * Helper autentikasi berbasis session.
 * Dipakai semua halaman untuk cek status login & role.
 */
declare(strict_types=1);

class Auth
{
    /** Mulai session sekali saja (aman dipanggil berkali-kali). */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** True kalau ada user login. */
    public static function check(): bool
    {
        self::boot();
        return isset($_SESSION['user_id']);
    }

    /** Data user login dari session, atau null. */
    public static function user(): ?array
    {
        self::boot();
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id'       => (int)$_SESSION['user_id'],
            'username' => (string)($_SESSION['username'] ?? ''),
            'role'     => (string)($_SESSION['role'] ?? 'user'),
        ];
    }

    public static function id(): ?int
    {
        return self::check() ? (int)$_SESSION['user_id'] : null;
    }

    public static function isAdmin(): bool
    {
        return self::check() && ($_SESSION['role'] ?? 'user') === 'admin';
    }

    /** Simpan data user ke session setelah login sukses. */
    public static function login(array $user): void
    {
        self::boot();
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['username'] = (string)$user['username'];
        $_SESSION['role']     = (string)($user['role'] ?? 'user');
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Wajib login: kalau belum, lempar ke login.php. */
    public static function requireLogin(string $redirect = 'login.php'): void
    {
        if (!self::check()) {
            header('Location: ' . $redirect . '?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
            exit;
        }
    }

    /** Wajib admin: kalau bukan admin, tolak. */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            require __DIR__ . '/../public/_denied.php';
            exit;
        }
    }
    /** Token CSRF disimpan di session. */
    public static function csrfToken(): string
    {
        self::boot();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    /** Verifikasi token dari form POST. */
    public static function csrfCheck(?string $token): bool
    {
        self::boot();
        return is_string($token) && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }
}
