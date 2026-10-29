<?php
/**
 * Router script for PHP's built-in server:
 *   php -S 0.0.0.0:8080 -t public public/router.php
 *
 * Static assets are served by the built-in server; everything else is routed
 * through the front controller. Set PHP_CLI_SERVER_WORKERS to serve several
 * MJPEG streams at once.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
