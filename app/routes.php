<?php

declare(strict_types=1);

use Argus\Http\AccountController;
use Argus\Http\ActivityController;
use Argus\Http\AnalyticsController;
use Argus\Http\ApiController;
use Argus\Http\AuthController;
use Argus\Http\CameraController;
use Argus\Http\CredentialsController;
use Argus\Http\DiscoveryController;
use Argus\Http\GroupController;
use Argus\Http\HealthController;
use Argus\Http\SettingsController;
use Argus\Http\SetupController;
use Argus\Http\UserController;
use Argus\Http\WallController;
use Argus\Support\Router;

return static function (Router $router): void {
    // -- guest ------------------------------------------------------------
    $router->get('/login', [AuthController::class, 'showLogin'], true);
    $router->post('/login', [AuthController::class, 'login'], true);
    $router->post('/logout', [AuthController::class, 'logout']);

    $router->get('/setup', [SetupController::class, 'show'], true);
    $router->post('/setup', [SetupController::class, 'install'], true);

    // -- live wall --------------------------------------------------------
    $router->get('/', [WallController::class, 'index']);
    $router->post('/api/layout', [WallController::class, 'saveLayout']);

    // -- cameras ----------------------------------------------------------
    $router->get('/cameras', [CameraController::class, 'index']);
    $router->get('/cameras/new', [CameraController::class, 'create']);
    $router->post('/cameras', [CameraController::class, 'store']);
    $router->post('/cameras/bulk', [CameraController::class, 'bulk']);
    $router->get('/cameras/{id}/edit', [CameraController::class, 'edit']);
    $router->post('/cameras/{id}', [CameraController::class, 'update']);
    $router->post('/cameras/{id}/delete', [CameraController::class, 'delete']);
    $router->post('/cameras/{id}/test', [CameraController::class, 'test']);
    $router->post('/cameras/{id}/credentials', [CredentialsController::class, 'show']);

    // -- groups -----------------------------------------------------------
    $router->get('/groups', [GroupController::class, 'index']);
    $router->post('/groups', [GroupController::class, 'store']);
    $router->post('/groups/{id}', [GroupController::class, 'update']);
    $router->post('/groups/{id}/delete', [GroupController::class, 'delete']);

    // -- discovery & health -----------------------------------------------
    $router->get('/discover', [DiscoveryController::class, 'index']);
    $router->post('/discover/scan', [DiscoveryController::class, 'scan']);
    $router->post('/discover/probe', [DiscoveryController::class, 'probe']);
    $router->post('/discover/add', [DiscoveryController::class, 'add']);

    $router->get('/health', [HealthController::class, 'index']);
    $router->get('/health/export.csv', [HealthController::class, 'export']);

    // -- people counting --------------------------------------------------
    $router->get('/analytics', [AnalyticsController::class, 'index']);

    // -- users, settings, audit ------------------------------------------
    $router->get('/users', [UserController::class, 'index']);
    $router->post('/users', [UserController::class, 'store']);
    $router->post('/users/{id}', [UserController::class, 'update']);
    $router->post('/users/{id}/delete', [UserController::class, 'delete']);

    $router->get('/settings', [SettingsController::class, 'index']);
    $router->post('/settings', [SettingsController::class, 'update']);

    $router->get('/activity', [ActivityController::class, 'index']);
    $router->get('/activity/export.csv', [ActivityController::class, 'export']);

    $router->get('/account', [AccountController::class, 'index']);
    $router->post('/account', [AccountController::class, 'update']);

    // -- json api ---------------------------------------------------------
    $router->get('/api/status', [ApiController::class, 'status']);
    $router->post('/api/webrtc/{id}', [ApiController::class, 'webrtc']);
    $router->post('/api/hls/{id}', [ApiController::class, 'hls']);
    $router->post('/api/cameras/{id}/stream', [ApiController::class, 'stream']);
};
