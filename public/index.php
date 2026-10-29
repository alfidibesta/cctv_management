<?php
/**
 * Argus front controller. Point your web server document root at this folder.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

$router = new Argus\Support\Router();
(require BASE_PATH . '/app/routes.php')($router);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/'
);
