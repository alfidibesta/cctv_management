<?php
/**
 * Camera media gateway.
 *
 * Every image, MJPEG stream and HLS playlist the browser sees is proxied here
 * so camera credentials stay on the server and access rules are enforced.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

use Argus\Repositories\Cameras;
use Argus\Services\Hls;
use Argus\Services\Snapshot;
use Argus\Services\StreamUrl;
use Argus\Support\Auth;
use Argus\Support\Logger;
use Argus\Support\Settings;

Auth::requireAuth();

$cameraId = (int) ($_GET['camera'] ?? 0);
$type = (string) ($_GET['type'] ?? 'snapshot');
$download = isset($_GET['download']);

$camera = $cameraId > 0 ? Cameras::find($cameraId) : null;

if (!$camera || !Cameras::canView(Auth::user(), $camera)) {
    http_response_code(404);
    header('Content-Type: image/svg+xml');
    echo Snapshot::demoFrame(['id' => 0, 'name' => 'Camera unavailable', 'location' => 'No access']);
    exit;
}

// Long-lived responses must not hold the session lock.
session_write_close();
ignore_user_abort(false);

header('X-Content-Type-Options: nosniff');

switch ($type) {
    case 'snapshot':
        serveSnapshot($camera, $download);
        break;

    case 'mjpeg':
        serveMjpeg($camera);
        break;

    case 'hls':
        serveHls($camera, (string) ($_GET['file'] ?? 'index.m3u8'));
        break;

    default:
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Unknown stream type.';
}

/**
 * JPEG (or demo SVG) with a short server-side cache.
 */
function serveSnapshot(array $camera, bool $download): void
{
    $ttl = Settings::int('snapshot_cache', 3);
    $result = Snapshot::cached($camera, $ttl);

    if (!$result['ok']) {
        $error = $result['error'] ?? 'Unreachable';

        // Log only when the camera changes state, not on every retry of the tile.
        if (($camera['status'] ?? '') !== 'offline') {
            Logger::warning('Snapshot failed for "' . $camera['name'] . '": ' . $error, [
                'camera' => (int) $camera['id'],
                'mode'   => (string) $camera['stream_mode'],
            ]);
        }

        Cameras::recordStatus((int) $camera['id'], 'offline', null, $error);
        Cameras::noteStatusChange($camera, 'offline', $error);
        http_response_code(503);
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-store');
        echo errorFrame($camera, $result['error'] ?? 'Camera unreachable');
        return;
    }

    if (empty($result['cached'])) {
        Cameras::recordStatus((int) $camera['id'], 'online', $result['latency_ms'] ?? null, null);
        Cameras::noteStatusChange($camera, 'online');
    }

    $contentType = $result['content_type'] ?? 'image/jpeg';
    $extension = match ($contentType) {
        'image/png'      => 'png',
        'image/svg+xml'  => 'svg',
        'image/gif'      => 'gif',
        default          => 'jpg',
    };

    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if ($download) {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $camera['name']) ?: 'camera';
        header(sprintf('Content-Disposition: attachment; filename="%s-%s.%s"', $name, gmdate('Ymd-His'), $extension));
    }

    echo $result['body'];
}

/**
 * Pass the camera's multipart MJPEG stream through untouched, including its
 * boundary, so a plain <img> tag can render it.
 */
function serveMjpeg(array $camera): void
{
    $url = StreamUrl::mjpegUrl($camera);

    if ($url === null || !function_exists('curl_init')) {
        http_response_code(503);
        header('Content-Type: image/svg+xml');
        echo errorFrame($camera, 'No MJPEG path configured for this camera.');
        return;
    }

    @set_time_limit(0);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Accel-Buffering: no');

    $started = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_USERAGENT      => 'Argus/' . ARGUS_VERSION,
        CURLOPT_SSL_VERIFYPEER => Settings::bool('verify_tls'),
        CURLOPT_SSL_VERIFYHOST => Settings::bool('verify_tls') ? 2 : 0,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$started): int {
            if (stripos($header, 'content-type:') === 0 && !$started) {
                $started = true;
                header('Content-Type: ' . trim(substr($header, 13)));
            }

            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk): int {
            echo $chunk;
            @flush();

            if (connection_aborted()) {
                return 0;
            }

            return strlen($chunk);
        },
    ]);

    $user = (string) ($camera['username'] ?? '');
    if ($user !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . \Argus\Support\Crypto::decrypt($camera['password_enc'] ?? null));
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    }

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($ok === false || $status >= 400) {
        Cameras::recordStatus((int) $camera['id'], 'offline', null, $error !== '' ? $error : 'MJPEG HTTP ' . $status);
        if (!$started) {
            http_response_code(503);
            header('Content-Type: image/svg+xml');
            echo errorFrame($camera, $error !== '' ? $error : 'MJPEG stream unavailable');
        }
        return;
    }

    Cameras::recordStatus((int) $camera['id'], 'online', null, null);
}

/**
 * Serve HLS playlists and segments produced by the ffmpeg worker.
 */
function serveHls(array $camera, string $file): void
{
    $file = basename($file);

    if (!preg_match('/^(index\.m3u8|seg\d{5}\.ts)$/', $file)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    if (($camera['stream_mode'] ?? '') !== 'hls') {
        http_response_code(404);
        echo 'Not an HLS camera';
        return;
    }

    $path = Hls::dir((int) $camera['id']) . '/' . $file;

    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Playlist not ready. Start the stream first.';
        return;
    }

    // Touch on read so the idle cleanup does not stop an active viewer.
    @touch($path);

    header('Content-Type: ' . (str_ends_with($file, '.m3u8')
        ? 'application/vnd.apple.mpegurl'
        : 'video/mp2t'));
    header('Cache-Control: no-store');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
}

/**
 * Friendly "no signal" frame so the wall never shows a broken image icon.
 */
function errorFrame(array $camera, string $message): string
{
    $name = htmlspecialchars((string) ($camera['name'] ?? 'Camera'), ENT_QUOTES);
    $message = htmlspecialchars(mb_substr($message, 0, 110), ENT_QUOTES);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">
  <rect width="1280" height="720" fill="#0c0f12"/>
  <g stroke="#2a323a" stroke-width="2" fill="none">
    <path d="M40 40 H90 M40 40 V90 M1240 40 H1190 M1240 40 V90 M40 680 H90 M40 680 V640 M1240 680 H1190 M1240 680 V640"/>
  </g>
  <g font-family="ui-monospace, monospace" fill="#5c6873" text-anchor="middle">
    <text x="640" y="330" font-size="44" letter-spacing="6">NO SIGNAL</text>
    <text x="640" y="386" font-size="24" fill="#94a3ad">{$name}</text>
    <text x="640" y="428" font-size="17">{$message}</text>
  </g>
</svg>
SVG;
}
