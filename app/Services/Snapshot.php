<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Crypto;
use Argus\Support\Settings;

/**
 * Fetches JPEG snapshots from cameras and generates demo frames.
 *
 * Camera credentials are attached with CURLOPT_USERPWD (HTTP Basic/Digest via
 * CURLAUTH_ANY) so they never reach the browser or the access log.
 */
final class Snapshot
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * @return array{ok: bool, body?: string, content_type?: string, latency_ms?: int, error?: string}
     */
    public static function fetch(array $camera, int $connectTimeout = 2, int $timeout = 6): array
    {
        if (StreamUrl::isDemo($camera)) {
            $start = microtime(true);

            return [
                'ok'           => true,
                'body'         => self::demoFrame($camera),
                'content_type' => 'image/svg+xml',
                'latency_ms'   => (int) round((microtime(true) - $start) * 1000),
            ];
        }

        $url = StreamUrl::snapshotUrl($camera);
        if ($url === null) {
            return ['ok' => false, 'error' => 'No snapshot URL configured for this camera.'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'The PHP cURL extension is required to reach cameras.'];
        }

        $start = microtime(true);
        $body = '';
        $tooLarge = false;
        $verify = Settings::bool('verify_tls');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Argus/' . ARGUS_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: image/jpeg,image/*;q=0.8,*/*;q=0.5'],
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                $body .= $chunk;
                if (strlen($body) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0; // abort transfer
                }

                return strlen($chunk);
            },
        ]);

        self::attachCredentials($ch, $camera);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($tooLarge) {
            return ['ok' => false, 'error' => 'Snapshot larger than 8 MB.', 'latency_ms' => $latency];
        }

        if ($ok === false || $status >= 400 || $status === 0) {
            $message = $error !== '' ? $error : 'HTTP ' . $status;

            return ['ok' => false, 'error' => self::friendlyError($message, $status), 'latency_ms' => $latency];
        }

        if ($body === '') {
            return ['ok' => false, 'error' => 'Camera returned an empty response.', 'latency_ms' => $latency];
        }

        if (!self::looksLikeImage($body, $contentType)) {
            return [
                'ok'         => false,
                'error'      => 'Camera response was not an image. Check the snapshot path.',
                'latency_ms' => $latency,
            ];
        }

        return [
            'ok'           => true,
            'body'         => $body,
            'content_type' => self::contentType($body, $contentType),
            'latency_ms'   => $latency,
        ];
    }

    /** Cached fetch used by the browser tile endpoint. */
    public static function cached(array $camera, int $ttl): array
    {
        $ttl = max(0, min($ttl, 60));
        $file = self::cachePath((int) $camera['id']);

        if ($ttl > 0 && is_file($file) && (time() - (int) filemtime($file)) < $ttl) {
            $body = (string) file_get_contents($file);

            return [
                'ok'           => true,
                'body'         => $body,
                'content_type' => self::contentType($body, ''),
                'cached'       => true,
                'latency_ms'   => 0,
            ];
        }

        $result = self::fetch($camera);

        if ($result['ok']) {
            self::writeCache($file, $result['body']);
        } elseif (is_file($file) && (time() - (int) filemtime($file)) < 120) {
            // Serve a recent frame instead of a broken tile during a short outage.
            $body = (string) file_get_contents($file);

            return [
                'ok'           => true,
                'body'         => $body,
                'content_type' => self::contentType($body, ''),
                'cached'       => true,
                'stale'        => true,
                'latency_ms'   => 0,
            ];
        }

        return $result;
    }

    public static function clearCache(int $cameraId): void
    {
        @unlink(self::cachePath($cameraId));
    }

    public static function cachePath(int $cameraId): string
    {
        return self::storageDir('cache') . '/camera-' . $cameraId . '.img';
    }

    public static function storageDir(string $name): string
    {
        $dir = BASE_PATH . '/storage/' . $name;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }

    /** Probe a camera and persist its status. */
    public static function probe(array $camera): array
    {
        $result = self::fetch($camera, 2, 5);

        if ($result['ok']) {
            \Argus\Repositories\Cameras::recordStatus((int) $camera['id'], 'online', $result['latency_ms'] ?? null, null);
            if (StreamUrl::isDemo($camera)) {
                self::writeCache(self::cachePath((int) $camera['id']), $result['body']);
            }
        } else {
            \Argus\Repositories\Cameras::recordStatus((int) $camera['id'], 'offline', null, $result['error'] ?? 'Unreachable');
        }

        return $result;
    }

    /**
     * Probe several cameras in parallel and persist their status.
     *
     * @param array<int, array> $cameras
     * @return array<int, array{status: string, latency_ms: ?int, error: ?string}>
     */
    public static function probeMany(array $cameras, int $timeout = 4): array
    {
        $results = [];
        $pending = [];
        $multi = function_exists('curl_multi_init') ? curl_multi_init() : null;

        foreach ($cameras as $camera) {
            $id = (int) $camera['id'];

            if (StreamUrl::isDemo($camera)) {
                $frame = self::demoFrame($camera);
                self::writeCache(self::cachePath($id), $frame);
                \Argus\Repositories\Cameras::recordStatus($id, 'online', 1, null);
                $results[$id] = ['status' => 'online', 'latency_ms' => 1, 'error' => null];
                continue;
            }

            if ($multi === null) {
                $probe = self::probe($camera);
                $results[$id] = [
                    'status'     => $probe['ok'] ? 'online' : 'offline',
                    'latency_ms' => $probe['latency_ms'] ?? null,
                    'error'      => $probe['error'] ?? null,
                ];
                continue;
            }

            $url = StreamUrl::snapshotUrl($camera);
            if ($url === null) {
                \Argus\Repositories\Cameras::recordStatus($id, 'offline', null, 'No snapshot URL configured.');
                $results[$id] = ['status' => 'offline', 'latency_ms' => null, 'error' => 'No snapshot URL configured.'];
                continue;
            }

            $verify = Settings::bool('verify_tls');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_USERAGENT      => 'Argus/' . ARGUS_VERSION,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_NOBODY         => false,
                CURLOPT_RANGE          => '0-2047',
            ]);
            self::attachCredentials($ch, $camera);
            curl_multi_add_handle($multi, $ch);
            $pending[(int) $ch] = ['handle' => $ch, 'camera' => $camera, 'started' => microtime(true)];
        }

        if ($multi !== null && $pending) {
            $deadline = microtime(true) + $timeout + 3;

            do {
                curl_multi_exec($multi, $active);

                if ($active > 0 && curl_multi_select($multi, 0.2) === -1) {
                    usleep(50000);
                }

                while ($info = curl_multi_info_read($multi)) {
                    $ch = $info['handle'];
                    $meta = $pending[(int) $ch] ?? null;
                    if ($meta === null) {
                        continue;
                    }

                    $camera = $meta['camera'];
                    $id = (int) $camera['id'];
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $error = curl_error($ch);
                    $latency = (int) round((microtime(true) - $meta['started']) * 1000);
                    $online = $info['result'] === CURLE_OK || ($httpCode >= 200 && $httpCode < 400);
                    $online = $online && $httpCode > 0 && $httpCode < 400;

                    if ($online) {
                        \Argus\Repositories\Cameras::recordStatus($id, 'online', $latency, null);
                        $results[$id] = ['status' => 'online', 'latency_ms' => $latency, 'error' => null];
                    } else {
                        $message = self::friendlyError($error, $httpCode);
                        \Argus\Repositories\Cameras::recordStatus($id, 'offline', null, $message);
                        $results[$id] = ['status' => 'offline', 'latency_ms' => $latency, 'error' => $message];
                    }

                    unset($pending[(int) $ch]);
                    curl_multi_remove_handle($multi, $ch);
                    curl_close($ch);
                }
            } while ($active > 0 && microtime(true) < $deadline);

            foreach ($pending as $meta) {
                $hz = $meta['handle'];
                $id = (int) $meta['camera']['id'];
                \Argus\Repositories\Cameras::recordStatus($id, 'offline', null, 'Probe timed out.');
                $results[$id] = ['status' => 'offline', 'latency_ms' => null, 'error' => 'Probe timed out.'];
                curl_multi_remove_handle($multi, $hz);
                curl_close($hz);
            }

            curl_multi_close($multi);
        }

        return $results;
    }

    private static function attachCredentials($ch, array $camera): void
    {
        $user = (string) ($camera['username'] ?? '');
        if ($user === '') {
            return;
        }

        $password = Crypto::decrypt($camera['password_enc'] ?? null);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    }

    private static function looksLikeImage(string $body, string $contentType): bool
    {
        if (str_starts_with($contentType, 'image/')) {
            return true;
        }

        $signatures = [
            "\xFF\xD8\xFF",                       // JPEG
            "\x89PNG\r\n\x1A\n",                  // PNG
            'GIF8',                                // GIF
            'RIFF',                                // WebP
            '<svg',                                // SVG (demo frames)
        ];

        foreach ($signatures as $signature) {
            if (str_starts_with($body, $signature) || str_contains(substr($body, 0, 512), $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function contentType(string $body, string $reported): string
    {
        if (str_starts_with($body, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($body, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($body, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($body, '<svg') || str_contains(substr($body, 0, 256), '<svg')) {
            return 'image/svg+xml';
        }
        if (str_starts_with($reported, 'image/')) {
            return strtok($reported, ';') ?: 'image/jpeg';
        }

        return 'image/jpeg';
    }

    private static function writeCache(string $file, string $body): void
    {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $body) !== false) {
            @rename($tmp, $file);
        }
    }

    private static function friendlyError(string $error, int $status): string
    {
        return match (true) {
            str_contains($error, 'timed out')        => 'Connection timed out. Check host and port.',
            str_contains($error, 'getaddrinfo')      => 'Host name could not be resolved.',
            str_contains($error, 'Connection refused') => 'Connection refused. Is the camera reachable?',
            $status === 401 || $status === 403       => 'Camera rejected the credentials.',
            $status === 404                          => 'Snapshot path not found (HTTP 404).',
            str_contains($error, 'SSL')              => 'TLS error. Try disabling certificate verification in settings.',
            default                                  => $error !== '' ? $error : 'Camera unreachable.',
        };
    }

    /**
     * Deterministic generated frame for demo cameras: no hardware required.
     */
    public static function demoFrame(array $camera): string
    {
        $name = htmlspecialchars((string) ($camera['name'] ?? 'Camera'), ENT_QUOTES);
        $location = htmlspecialchars((string) ($camera['location'] ?? 'Demo feed'), ENT_QUOTES);
        $time = gmdate('H:i:s');
        $date = gmdate('D d M Y');
        $seed = (int) ($camera['id'] ?? 1);
        $hue = ($seed * 47) % 360;

        $bars = '';
        for ($i = 0; $i < 12; $i++) {
            $w = 1280 / 12;
            $alpha = 0.04 + (($i + $seed) % 5) * 0.012;
            $bars .= sprintf(
                '<rect x="%.1f" y="0" width="%.1f" height="720" fill="hsl(%d 40%% 60%%)" opacity="%.3f"/>',
                $i * $w,
                $w,
                ($hue + $i * 9) % 360,
                $alpha
            );
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#0b1014"/>
      <stop offset="55%" stop-color="#111a20"/>
      <stop offset="100%" stop-color="#0a0f13"/>
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="45%" r="60%">
      <stop offset="0%" stop-color="hsl({$hue} 60% 65%)" stop-opacity="0.22"/>
      <stop offset="100%" stop-color="hsl({$hue} 60% 65%)" stop-opacity="0"/>
    </radialGradient>
    <pattern id="scan" width="4" height="4" patternUnits="userSpaceOnUse">
      <rect width="4" height="1" fill="#ffffff" opacity="0.025"/>
    </pattern>
  </defs>
  <rect width="1280" height="720" fill="url(#bg)"/>
  <rect width="1280" height="720" fill="url(#glow)"/>
  {$bars}
  <rect width="1280" height="720" fill="url(#scan)"/>
  <g stroke="#ffffff" stroke-opacity="0.08" fill="none">
    <rect x="120" y="150" width="400" height="300" rx="6"/>
    <rect x="760" y="220" width="360" height="330" rx="6"/>
    <path d="M120 480 H520 M760 580 H1120"/>
  </g>
  <g font-family="ui-monospace, monospace" fill="#e8f5ef">
    <text x="56" y="86" font-size="34" letter-spacing="2">{$name}</text>
    <text x="56" y="122" font-size="19" fill="#8fb3a6">{$location}</text>
    <text x="56" y="650" font-size="26">{$date}  {$time}</text>
  </g>
  <g transform="translate(1150 70)">
    <circle r="9" fill="#5fe3c0">
      <animate attributeName="opacity" values="1;0.25;1" dur="2s" repeatCount="indefinite"/>
    </circle>
    <text x="22" y="7" font-family="ui-monospace, monospace" font-size="20" fill="#5fe3c0">DEMO</text>
  </g>
  <g stroke="#5fe3c0" stroke-opacity="0.5" stroke-width="3" fill="none">
    <path d="M40 40 H90 M40 40 V90 M1240 40 H1190 M1240 40 V90 M40 680 H90 M40 680 V640 M1240 680 H1190 M1240 680 V640"/>
  </g>
</svg>
SVG;
    }
}
