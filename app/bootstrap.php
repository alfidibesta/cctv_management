<?php
/**
 * Application bootstrap: autoloading, configuration, session, timezone.
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

define('ARGUS_VERSION', '1.0.0');

// ---------------------------------------------------------------- autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'Argus\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/helpers.php';

Argus\Support\Config::load();

// --------------------------------------------------- error + exception trail
// Everything unusual is appended to storage/logs/app.log (JSON lines) and
// mirrored into the activity feed so an admin can see it in the browser.
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $level = in_array($severity, [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)
        ? 'warning'
        : 'error';

    Argus\Support\Logger::write($level, $message, [
        'file' => str_replace(BASE_PATH, '', $file),
        'line' => $line,
    ]);

    // Keep PHP's own handling (display/log_errors) working as before.
    return false;
});

set_exception_handler(static function (Throwable $e): void {
    Argus\Support\Logger::error('Uncaught ' . $e::class . ': ' . $e->getMessage(), [
        'file'  => str_replace(BASE_PATH, '', $e->getFile()),
        'line'  => $e->getLine(),
        'trace' => array_map(
            static fn (array $frame): string => str_replace(BASE_PATH, '', (string) ($frame['file'] ?? '?'))
                . ':' . (string) ($frame['line'] ?? '?')
                . ' ' . (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? ''),
            array_slice($e->getTrace(), 0, 6)
        ),
    ]);

    try {
        Argus\Support\Activity::log('error', $e->getMessage(), null, null);
    } catch (Throwable) {
        // The database itself may be the problem.
    }

    $isMedia = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'stream.php')
        || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'stream.php');

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . ($isMedia ? 'text/plain' : 'text/html') . '; charset=utf-8');
    }

    if ($isMedia) {
        echo 'Camera stream error.';

        return;
    }

    $debug = (bool) Argus\Support\Config::get('app.debug', false);
    $message = $debug ? $e->getMessage() : 'The request could not be completed.';

    try {
        echo Argus\Support\View::render('pages/server-error', [
            'title'   => 'Server error',
            'message' => $message,
            'detail'  => $debug ? str_replace(BASE_PATH, '', $e->getFile()) . ':' . $e->getLine() : '',
            'logged'  => is_file(Argus\Support\Logger::path()),
        ], 'layouts/guest');
    } catch (Throwable) {
        // Rendering failed too (usually the database): fall back to plain HTML.
        echo '<!doctype html><meta charset="utf-8"><title>Server error</title>'
            . '<div style="font:15px/1.6 system-ui;max-width:640px;margin:12vh auto;padding:24px">'
            . '<h1>Server error</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
            . '<p>Details were written to <code>storage/logs/app.log</code>.</p></div>';
    }
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    Argus\Support\Logger::error('Fatal: ' . $error['message'], [
        'file' => str_replace(BASE_PATH, '', (string) $error['file']),
        'line' => (int) $error['line'],
    ]);

    try {
        Argus\Support\Activity::log('error.fatal', $error['message']);
    } catch (Throwable) {
        // Nothing else we can do during shutdown.
    }
});

$debug = (bool) Argus\Support\Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = BASE_PATH . '/storage/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/php-error.log');
}

date_default_timezone_set('UTC');

// ------------------------------------------------------------------- session
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('argus_session');
    session_start();
}

// Timezone and display settings come from the database once installed.
if (Argus\Support\Config::installed()) {
    try {
        $tz = Argus\Support\Settings::get('timezone');
        if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    } catch (Throwable $e) {
        // Database not reachable yet: stay on UTC so the app can render an error.
    }
}
