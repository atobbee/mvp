<?php
declare(strict_types=1);

namespace Atobbee;

final class Auth
{
    private static ?array $user = null;

    public static function startSession(): void
    {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => config('env') === 'prod',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('atobbee');
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$user === null && isset($_SESSION['uid'])) {
            self::$user = db()->one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
            if (!self::$user) unset($_SESSION['uid']);
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int)self::user()['id'] : null;
    }

    public static function attempt(string $email, string $password): bool
    {
        // Basic brute-force brake per session; put a real rate limiter (or fail2ban) in front in prod.
        $_SESSION['login_failures'] ??= 0;
        if ($_SESSION['login_failures'] >= 8) {
            usleep(1_500_000);
        }
        $u = db()->one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            $_SESSION['login_failures']++;
            return false;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->update('users', (int)$u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        self::login((int)$u['id']);
        return true;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['login_failures'] = 0;
        self::$user = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    /** Require login (and optionally one of the given roles). */
    public static function require(string ...$roles): array
    {
        $u = self::user();
        if (!$u) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            flash('info', 'Please log in first.');
            redirect('/login');
        }
        if ($roles && !in_array($u['role'], $roles, true)) abort(403, 'This page is not available for your account type.');
        return $u;
    }

    public static function home(array $u): string
    {
        return match ($u['role']) {
            'driver' => '/driver',
            'business' => '/point',
            'admin' => '/admin',
            default => '/dashboard',
        };
    }
}
