<?php
/**
 * People-counting telemetry.
 *
 * POST  {camera_id, events[], occupancy, config?}  batch-sync every 5 seconds
 * GET   ?camera=&date=YYYY-MM-DD                    totals + hourly buckets
 * GET   ?camera=&date=&format=csv                    hourly CSV export
 *
 * Counting runs in the browser (Web Worker); this endpoint is the only writer,
 * and every request is checked against the signed-in user's camera access.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/app/bootstrap.php';

use Argus\Repositories\Analytics;
use Argus\Repositories\Cameras;
use Argus\Support\Auth;
use Argus\Support\Csrf;

Auth::requireAuth();

$user = Auth::user();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    if (!Csrf::verify(Csrf::fromRequest())) {
        json_response(['error' => 'Your session expired. Reload the wall and try again.'], 419);
    }

    $raw = (string) file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        json_response(['error' => 'Expected a JSON body.'], 422);
    }

    $cameraId = (int) ($payload['camera_id'] ?? 0);
    $camera = $cameraId > 0 ? Cameras::find($cameraId) : null;

    if (!$camera || !Cameras::canView($user, $camera)) {
        json_response(['error' => 'Camera not found or not available to your account.'], 404);
    }

    // Configuration changes belong to camera managers; viewers may only send
    // the counting samples their browser produced.
    if (array_key_exists('config', $payload)) {
        if (!Auth::canManageCameras()) {
            json_response(['error' => 'Your role cannot change detection settings.'], 403);
        }

        $config = Analytics::saveConfig($cameraId, is_array($payload['config']) ? $payload['config'] : []);
    }

    $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
    $stored = Analytics::record($cameraId, array_values(array_filter($events, 'is_array')));

    json_response([
        'ok'      => true,
        'stored'  => $stored,
        'config'  => $config ?? null,
        'totals'  => Analytics::totals($cameraId, gmdate('Y-m-d')),
    ]);
}

if ($method !== 'GET') {
    header('Allow: GET, POST');
    json_response(['error' => 'Unsupported method.'], 405);
}

$cameraId = (int) ($_GET['camera'] ?? 0);
$camera = $cameraId > 0 ? Cameras::find($cameraId) : null;

if (!$camera || !Cameras::canView($user, $camera)) {
    json_response(['error' => 'Camera not found or not available to your account.'], 404);
}

$date = (string) ($_GET['date'] ?? gmdate('Y-m-d'));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = gmdate('Y-m-d');
}

$hourly = Analytics::hourly($cameraId, $date);

if (($_GET['format'] ?? '') === 'csv') {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $camera['name']) ?: 'camera';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="foot-traffic-' . $name . '-' . $date . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }

    fwrite($out, "\xEF\xBB\xBF");
    // The explicit escape argument silences PHP 8.4+ deprecation and pins the
    // documented future default ("" instead of "\\").
    fputcsv($out, ['camera', 'date', 'hour', 'in', 'out', 'peak_occupancy'], ',', '"', '');
    foreach ($hourly as $bucket) {
        fputcsv($out, [
            $camera['name'],
            $date,
            sprintf('%02d:00', $bucket['hour']),
            $bucket['in'],
            $bucket['out'],
            $bucket['peak'],
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

session_write_close();

json_response([
    'ok'      => true,
    'camera'  => ['id' => (int) $camera['id'], 'name' => (string) $camera['name']],
    'date'    => $date,
    'totals'  => Analytics::totals($cameraId, $date),
    'hourly'  => $hourly,
    'daily'   => Analytics::daily($cameraId),
    'recent'  => Analytics::recent($cameraId),
]);
