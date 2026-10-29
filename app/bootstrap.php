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
