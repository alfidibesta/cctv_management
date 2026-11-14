<?php
/**
 * WebRTC (WHEP) signalling proxy.
 *
 * Keeps go2rtc / MediaMTX on loopback: the browser only ever talks to this
 * endpoint, which checks the session, CSRF token and camera access before
 * relaying the SDP offer and returning the SDP answer.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));

require BASE_PATH . '/app/bootstrap.php';

use Argus\Repositories\Cameras;
use Argus\Services\Hls;
use Argus\Services\MediaServer;
use Argus\Support\Auth;
use Argus\Support\Csrf;

Auth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'POST an SDP offer to this endpoint.'], 405);
}

if (!Csrf::verify(Csrf::fromRequest())) {
    json_response(['error' => 'Your session expired. Reload the wall and try again.'], 419);
}

$cameraId = (int) ($_GET['camera'] ?? $_POST['camera'] ?? 0);
$camera = $cameraId > 0 ? Cameras::find($cameraId) : null;

if (!$camera || !Cameras::canView(Auth::user(), $camera)) {
    json_response(['error' => 'Camera not found or not available to your account.'], 404);
}

$raw = (string) file_get_contents('php://input');
$decoded = json_decode($raw, true);

$offer = is_array($decoded) ? (string) ($decoded['sdp'] ?? '') : trim($raw);

// Raw SDP is also accepted, which is handy for manual debugging with curl.
if ($offer === '' || !str_starts_with(ltrim($offer), 'v=')) {
    json_response([
        'error'    => 'A valid SDP offer is required.',
        'fallback' => fallbackFor($camera),
    ], 422);
}

$result = MediaServer::offer((int) $camera['id'], $offer);

if (!$result['ok']) {
    json_response([
        'error'    => $result['error'] ?? 'The media server did not answer.',
        'fallback' => fallbackFor($camera),
    ], 502);
}

// Keep the session lock out of the way for repeat signalling calls.
session_write_close();

json_response([
    'ok'         => true,
    'type'       => 'answer',
    'sdp'        => $result['sdp'] ?? '',
    'session'    => $result['session'] ?? null,
    'driver'     => $result['driver'] ?? MediaServer::driver(),
    'latency_ms' => Hls::latencyMs($camera),
]);

/** Media mode the wall should use when WebRTC is unavailable. */
function fallbackFor(array $camera): string
{
    return match ((string) ($camera['stream_mode'] ?? '')) {
        'hls'   => 'hls',
        'mjpeg' => 'mjpeg',
        default => 'snapshot',
    };
}
