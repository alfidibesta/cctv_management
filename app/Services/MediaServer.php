<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Config;
use Argus\Support\Logger;

/**
 * Media gateway integration: go2rtc or MediaMTX, selected in config.php.
 *
 * PHP only provisions stream paths and relays WebRTC signalling; the media
 * server itself terminates RTSP and serves WHEP/MSE to the browser. Nothing
 * here is exposed directly: internal ports stay behind the app's session.
 */
final class MediaServer
{
    public const DRIVERS = [
        'none'     => 'Disabled (ffmpeg / MJPEG only)',
        'go2rtc'   => 'go2rtc',
        'mediamtx' => 'MediaMTX',
    ];

    public static function driver(): string
    {
        $driver = strtolower(trim((string) Config::get('media.driver', 'none')));

        return array_key_exists($driver, self::DRIVERS) ? $driver : 'none';
    }

    public static function driverLabel(): string
    {
        return self::DRIVERS[self::driver()];
    }

    public static function enabled(): bool
    {
        return self::driver() !== 'none';
    }

    /** Base URL of the media server API, without a trailing slash. */
    public static function baseUrl(): string
    {
        $default = self::driver() === 'go2rtc'
            ? 'http://127.0.0.1:1984'
            : 'http://127.0.0.1:9997';

        return rtrim((string) Config::get('media.' . self::driver() . '_url', $default), '/');
    }

    public static function streamName(int $cameraId): string
    {
        return (string) $cameraId;
    }

    /**
     * Register or refresh the camera's RTSP source on the media server.
     *
     * @return array{ok: bool, skipped: bool, driver: string, error?: string, status?: int}
     */
    public static function provision(array $camera): array
    {
        $driver = self::driver();
        if ($driver === 'none') {
            return ['ok' => true, 'skipped' => true, 'driver' => $driver];
        }

        $rtsp = StreamUrl::rtspUrl($camera);
        if ($rtsp === null) {
            return ['ok' => true, 'skipped' => true, 'driver' => $driver, 'error' => 'No RTSP path configured.'];
        }

        $name = self::streamName((int) $camera['id']);
        $transport = self::transport($camera);

        if ($driver === 'go2rtc') {
            $response = self::request(
                'PUT',
                self::baseUrl() . '/api/streams?name=' . rawurlencode($name) . '&src=' . rawurlencode($rtsp)
            );
        } else {
            $payload = [
                'source'                   => $rtsp,
                'rtspTransport'            => $transport === 'udp' ? 'udp' : 'tcp',
                'sourceOnDemand'           => true,
                'sourceOnDemandCloseAfter' => '15s',
            ];

            $response = self::request('POST', self::baseUrl() . '/v3/config/paths/add/' . rawurlencode($name), $payload);

            // Path already exists: MediaMTX answers 400/409, patch it instead.
            if ($response['status'] === 400 || $response['status'] === 409) {
                $response = self::request('PATCH', self::baseUrl() . '/v3/config/paths/patch/' . rawurlencode($name), $payload);
            }
        }

        if (!$response['ok']) {
            Logger::warning('Media server provisioning failed', [
                'driver' => $driver,
                'camera' => (int) ($camera['id'] ?? 0),
                'status' => $response['status'],
                'error'  => $response['error'],
            ]);
        }

        return [
            'ok'      => $response['ok'],
            'skipped' => false,
            'driver'  => $driver,
            'status'  => $response['status'],
            'error'   => $response['ok'] ? null : $response['error'],
        ];
    }

    /**
     * Remove a camera's path from the media server.
     *
     * @return array{ok: bool, skipped: bool, driver: string, error?: string}
     */
    public static function remove(int $cameraId): array
    {
        $driver = self::driver();
        if ($driver === 'none') {
            return ['ok' => true, 'skipped' => true, 'driver' => $driver];
        }

        $name = self::streamName($cameraId);

        if ($driver === 'go2rtc') {
            $response = self::request('DELETE', self::baseUrl() . '/api/streams?name=' . rawurlencode($name));
        } else {
            $response = self::request('POST', self::baseUrl() . '/v3/config/paths/delete/' . rawurlencode($name));
        }

        // A missing path is not an error: the camera is already gone.
        $ok = $response['ok'] || $response['status'] === 404;

        return [
            'ok'      => $ok,
            'skipped' => false,
            'driver'  => $driver,
            'error'   => $ok ? null : $response['error'],
        ];
    }

    /**
     * Relay an SDP offer and return the media server's answer.
     *
     * @return array{ok: bool, driver: string, sdp?: string, session?: ?string, error?: string}
     */
    public static function offer(int $cameraId, string $sdp, string $session = ''): array
    {
        $driver = self::driver();

        if ($driver === 'none') {
            return [
                'ok'    => false,
                'driver' => $driver,
                'error' => 'No media server is configured. Set media.driver in config/config.php to go2rtc or mediamtx.',
            ];
        }

        if (trim($sdp) === '' || !str_starts_with(ltrim($sdp), 'v=')) {
            return ['ok' => false, 'driver' => $driver, 'error' => 'The SDP offer is empty or malformed.'];
        }

        $name = self::streamName($cameraId);

        if ($driver === 'go2rtc') {
            $url = self::baseUrl() . '/api/webrtc?src=' . rawurlencode($name);
        } else {
            $url = self::baseUrl() . '/' . rawurlencode($name) . '/whep';
            if ($session !== '') {
                $url .= '?session=' . rawurlencode($session);
            }
        }

        $response = self::request('POST', $url, null, [
            'Content-Type: application/sdp',
            'Accept: application/sdp',
        ], $sdp);

        if (!$response['ok'] || trim($response['body']) === '') {
            Logger::warning('WebRTC offer rejected by the media server', [
                'driver' => $driver,
                'camera' => $cameraId,
                'status' => $response['status'],
                'error'  => $response['error'],
            ]);

            return ['ok' => false, 'driver' => $driver, 'error' => $response['error']];
        }

        return [
            'ok'      => true,
            'driver'  => $driver,
            'sdp'     => $response['body'],
            'session' => $response['location'],
        ];
    }

    /** Reachability of the configured media server, for Settings diagnostics. */
    public static function health(): array
    {
        $driver = self::driver();

        if ($driver === 'none') {
            return ['driver' => $driver, 'label' => self::driverLabel(), 'ok' => false, 'url' => '', 'detail' => 'Not configured'];
        }

        $url = $driver === 'go2rtc'
            ? self::baseUrl() . '/api/streams'
            : self::baseUrl() . '/v3/config/global/get';

        $response = self::request('GET', $url);

        return [
            'driver' => $driver,
            'label'  => self::driverLabel(),
            'ok'     => $response['ok'],
            'url'    => self::baseUrl(),
            'detail' => $response['ok']
                ? 'Reachable'
                : ($response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['status']),
        ];
    }

    public static function transport(array $camera): string
    {
        $transport = (string) ($camera['transport'] ?? \Argus\Support\Settings::get('rtsp_transport', 'tcp'));

        return strtolower($transport) === 'udp' ? 'udp' : 'tcp';
    }

    /**
     * Minimal HTTP client. Returns status, body, error and Location header.
     *
     * @param array<int, string> $headers
     * @return array{ok: bool, status: int, body: string, error: string, location: ?string}
     */
    private static function request(string $method, string $url, ?array $json = null, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'The PHP cURL extension is required.', 'location' => null];
        }

        $payload = $body;
        if ($json !== null) {
            $payload = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $timeout = max(1, (int) Config::get('media.timeout', 3));
        $location = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout + 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'Argus/' . ARGUS_VERSION,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($responseBody === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error !== '' ? $error : 'Request failed.', 'location' => null];
        }

        $ok = $status >= 200 && $status < 300;

        return [
            'ok'       => $ok,
            'status'   => $status,
            'body'     => (string) $responseBody,
            'error'    => $ok ? '' : ($error !== '' ? $error : 'HTTP ' . $status),
            'location' => $location,
        ];
    }
}
