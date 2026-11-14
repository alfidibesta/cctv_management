<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * Session based authentication for admin / operator / viewer roles.
 */
final class Auth
{
    private const SESSION_KEY = 'uid';
    private const THROTTLE_WINDOW = 900;   // 15 minutes
    private const THROTTLE_LIMIT  = 8;     // failed attempts per window

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }

        self::$loaded = true;
        $id = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_int($id) && !is_numeric($id)) {
            return self::$user = null;
        }

        $user = Db::one(
            'SELECT id, name, email, role, is_active, last_login_at FROM users WHERE id = :id LIMIT 1',
            ['id' => (int) $id]
        );

        if (!$user || (int) $user['is_active'] !== 1) {
            self::logout();

            return self::$user = null;
        }

        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): string
    {
        return (string) (self::user()['role'] ?? 'guest');
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Admins manage everything; operators manage cameras and layouts. */
    public static function canManageCameras(): bool
    {
        return in_array(self::role(), ['admin', 'operator'], true);
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            if (self::isAjax()) {
                json_response(['error' => 'Not authenticated.'], 401);
            }

            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
            redirect('/login');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireAuth();

        if (!self::isAdmin()) {
            if (self::isAjax()) {
                json_response(['error' => 'Administrator access required.'], 403);
            }

            http_response_code(403);
            view('pages/error', ['code' => 403, 'message' => 'You need administrator access to open this page.']);
            exit;
        }
    }

    public static function requireCameraManager(): void
    {
        self::requireAuth();

        if (!self::canManageCameras()) {
            if (self::isAjax()) {
                json_response(['error' => 'You do not have permission to change cameras.'], 403);
            }

            http_response_code(403);
            view('pages/error', ['code' => 403, 'message' => 'Your role cannot change cameras.']);
            exit;
        }
    }

    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * Validate credentials.
     *
     * @return array{ok: bool, error?: string, user?: array}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = self::clientIp();

        if ($email === '' || $password === '') {
            return ['ok' => false, 'error' => 'Enter your email and password.'];
        }

        if (self::isThrottled($email, $ip)) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in a few minutes.'];
        }

        $user = Db::one('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            self::recordAttempt($email, $ip, false);
            Activity::log('auth.failed', 'Failed sign-in for ' . $email);

            return ['ok' => false, 'error' => 'Those credentials do not match our records.'];
        }

        if ((int) $user['is_active'] !== 1) {
            self::recordAttempt($email, $ip, false);

            return ['ok' => false, 'error' => 'This account has been disabled.'];
        }

        self::recordAttempt($email, $ip, true);

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => (int) $user['id']]);
        }

        self::login($user);

        return ['ok' => true, 'user' => $user];
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['_login_at'] = time();

        self::$user = [
            'id'            => (int) $user['id'],
            'name'          => $user['name'],
            'email'         => $user['email'],
            'role'          => $user['role'],
            'is_active'     => 1,
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
        self::$loaded = true;

        Db::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $user['id']]);
        Activity::log('auth.login', 'Signed in', null, (int) $user['id']);
    }

    public static function logout(): void
    {
        if (self::check()) {
            Activity::log('auth.logout', 'Signed out');
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();

        self::$user = null;
        self::$loaded = true;
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return substr((string) $ip, 0, 45);
    }

    private static function isThrottled(string $email, string $ip): bool
    {
        try {
            $count = (int) Db::val(
                'SELECT COUNT(*) FROM login_attempts
                 WHERE success = 0 AND created_at > :cutoff
                   AND (email = :email OR ip = :ip)',
                [
                    'cutoff' => gmdate('Y-m-d H:i:s', time() - self::THROTTLE_WINDOW),
                    'email'  => $email,
                    'ip'     => $ip,
                ]
            );

            return $count >= self::THROTTLE_LIMIT;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function recordAttempt(string $email, string $ip, bool $success): void
    {
        try {
            Db::insert('login_attempts', [
                'email'      => mb_substr($email, 0, 190),
                'ip'         => $ip,
                'success'    => $success ? 1 : 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            if (random_int(1, 20) === 1) {
                Db::run('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 2 DAY)');
            }
        } catch (\Throwable) {
            // Never block a sign-in because of audit logging.
        }
    }
}
