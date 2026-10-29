<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Crypto;

/**
 * Builds camera endpoint URLs from the driver presets or per-camera templates.
 *
 * A template may contain:
 *   {host} {port} {rtsp_port} {user} {pass} {channel} {channel2} {cmd} {preset} {speed}
 *
 * Credentials are never placed in browser-facing URLs: HTTP requests are made
 * server side with CURLOPT_USERPWD, RTSP URLs are only used by ffmpeg.
 */
final class StreamUrl
{
    public const DRIVERS = [
        'acti' => [
            'label'    => 'ACTi (legacy CGI)',
            'snapshot' => '/cgi-bin/encoder?SNAPSHOT',
            'mjpeg'    => '/-wvhttp-01-/stream/Mjpeg?framerate=25',
            'rtsp'     => '/onvif1',
            'ptz'      => '/cgi-bin/ptz?move={cmd}&speed={speed}',
            'hint'     => 'ACTi firmware with /cgi-bin/encoder CGI endpoints.',
        ],
        'acti_onvif' => [
            'label'    => 'ACTi / ONVIF Profile',
            'snapshot' => '/onvif-http/snapshot?Profile_{channel}',
            'mjpeg'    => '/cgi-bin/mjpeg?stream=1',
            'rtsp'     => '/onvif-media/media.amp?profile=profile_{channel}_h264&sessiontimeout=60&streamtype=unicast',
            'ptz'      => '',
            'hint'     => 'Newer ACTi firmware and ONVIF-compatible recorders.',
        ],
        'hikvision' => [
            'label'    => 'Hikvision',
            'snapshot' => '/ISAPI/Streaming/channels/{channel}01/picture',
            'mjpeg'    => '/Streaming/channels/{channel}01/httpPreview',
            'rtsp'     => '/Streaming/Channels/{channel}01',
            'ptz'      => '',
            'hint'     => 'Hikvision cameras, NVRs and OEM equivalents.',
        ],
        'dahua' => [
            'label'    => 'Dahua',
            'snapshot' => '/cgi-bin/snapshot.cgi?channel={channel}',
            'mjpeg'    => '/cgi-bin/mjpg/video.cgi?channel={channel}&subtype=1',
            'rtsp'     => '/cam/realmonitor?channel={channel}&subtype=0',
            'ptz'      => '',
            'hint'     => 'Dahua cameras, NVRs and OEM equivalents.',
        ],
        'axis' => [
            'label'    => 'Axis',
            'snapshot' => '/axis-cgi/jpg/image.cgi',
            'mjpeg'    => '/axis-cgi/mjpg/video.cgi',
            'rtsp'     => '/axis-media/media.amp',
            'ptz'      => '/axis-cgi/com/ptz.cgi?move={cmd}',
            'hint'     => 'Axis cameras and encoders.',
        ],
        'generic' => [
            'label'    => 'Generic HTTP / MJPEG',
            'snapshot' => '/snapshot.jpg',
            'mjpeg'    => '/video.mjpg',
            'rtsp'     => '/stream1',
            'ptz'      => '',
            'hint'     => 'Any camera exposing a JPEG snapshot or MJPEG stream over HTTP.',
        ],
        'demo' => [
            'label'    => 'Demo feed',
            'snapshot' => '',
            'mjpeg'    => '',
            'rtsp'     => '',
            'ptz'      => '',
            'hint'     => 'Generated placeholder used to try the wall without hardware.',
        ],
    ];

    public const PTZ_COMMANDS = [
        'up'       => 'Tilt up',
        'down'     => 'Tilt down',
        'left'     => 'Pan left',
        'right'    => 'Pan right',
        'zoom_in'  => 'Zoom in',
        'zoom_out' => 'Zoom out',
        'home'     => 'Home position',
        'stop'     => 'Stop',
    ];

    public static function driverLabel(string $driver): string
    {
        return self::DRIVERS[$driver]['label'] ?? ucfirst($driver);
    }

    public static function isDemo(array $camera): bool
    {
        return ($camera['driver'] ?? '') === 'demo';
    }

    public static function snapshotUrl(array $camera): ?string
    {
        if (self::isDemo($camera)) {
            return null;
        }

        $path = self::template($camera, 'snapshot_path', 'snapshot');
        if ($path === '') {
            return null;
        }

        return self::resolve($path, $camera);
    }

    public static function mjpegUrl(array $camera): ?string
    {
        if (self::isDemo($camera)) {
            return null;
        }

        $path = self::template($camera, 'mjpeg_path', 'mjpeg');
        if ($path === '') {
            return null;
        }

        return self::resolve($path, $camera);
    }

    /** RTSP URL with credentials embedded, used only by the ffmpeg worker. */
    public static function rtspUrl(array $camera): ?string
    {
        if (self::isDemo($camera)) {
            return null;
        }

        $path = self::template($camera, 'rtsp_path', 'rtsp');
        if ($path === '') {
            return null;
        }

        // Any absolute stream URL (rtsp/rtmp/http/file) is used verbatim so a
        // camera can point at an NVR or an HTTP-FLV/HLS source as well.
        if (preg_match('#^(rtsp|rtsps|rtmp|https?|file)://#i', $path)) {
            return self::fill($path, $camera);
        }

        $user = (string) ($camera['username'] ?? '');
        $pass = Crypto::decrypt($camera['password_enc'] ?? null);
        $auth = $user !== ''
            ? rawurlencode($user) . ':' . rawurlencode($pass) . '@'
            : '';

        return sprintf(
            'rtsp://%s%s:%d%s',
            $auth,
            $camera['host'],
            (int) ($camera['rtsp_port'] ?: 554),
            self::fill($path, $camera)
        );
    }

    public static function ptzUrl(array $camera, string $command, float $speed = 0.5, ?int $preset = null): ?string
    {
        if (!(int) ($camera['ptz_enabled'] ?? 0)) {
            return null;
        }

        $template = trim((string) ($camera['ptz_path'] ?? ''));
        if ($template === '') {
            $template = (string) (self::DRIVERS[$camera['driver'] ?? 'generic']['ptz'] ?? '');
        }

        if ($template === '') {
            return null;
        }

        $filled = str_replace(
            ['{cmd}', '{preset}', '{speed}'],
            [$command, (string) ($preset ?? 0), (string) round($speed, 2)],
            self::fill($template, $camera)
        );

        if (preg_match('#^https?://#i', $filled)) {
            return $filled;
        }

        return self::httpBase($camera) . $filled;
    }

    /** URL the browser should use for a tile (never contains credentials). */
    public static function viewerUrl(array $camera, string $type = 'snapshot'): string
    {
        return url('/stream.php?camera=' . (int) $camera['id'] . '&type=' . urlencode($type));
    }

    public static function preset(string $driver): array
    {
        $preset = self::DRIVERS[$driver] ?? self::DRIVERS['generic'];

        return [
            'snapshot_path' => $preset['snapshot'],
            'mjpeg_path'    => $preset['mjpeg'],
            'rtsp_path'     => $preset['rtsp'],
            'ptz_path'      => $preset['ptz'],
        ];
    }

    /** Prefix relative paths with the camera origin; keep absolute URLs intact. */
    private static function resolve(string $path, array $camera): string
    {
        $filled = self::fill($path, $camera);

        if (preg_match('#^(https?|rtsp|rtmp|file)://#i', $filled)) {
            return $filled;
        }

        if (str_starts_with($filled, '//')) {
            return 'http:' . $filled;
        }

        return self::httpBase($camera) . $filled;
    }

    private static function httpBase(array $camera): string
    {
        $port = (int) ($camera['port'] ?: 80);
        $scheme = $port === 443 ? 'https' : 'http';

        return sprintf('%s://%s:%d', $scheme, $camera['host'], $port);
    }

    /** Per-camera path if set, otherwise the driver default. */
    private static function template(array $camera, string $column, string $key): string
    {
        $value = trim((string) ($camera[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }

        $driver = (string) ($camera['driver'] ?? 'generic');

        return (string) (self::DRIVERS[$driver][$key] ?? '');
    }

    private static function fill(string $template, array $camera): string
    {
        $channel = max(1, (int) ($camera['channel'] ?? 1));

        return str_replace(
            ['{host}', '{port}', '{rtsp_port}', '{channel}', '{channel2}', '{user}', '{pass}'],
            [
                (string) $camera['host'],
                (string) ($camera['port'] ?? 80),
                (string) ($camera['rtsp_port'] ?? 554),
                (string) $channel,
                str_pad((string) $channel, 2, '0', STR_PAD_LEFT),
                (string) ($camera['username'] ?? ''),
                '',
            ],
            $template
        );
    }
}
