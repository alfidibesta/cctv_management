<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Settings;

/**
 * Converts an RTSP camera stream to HLS with a background ffmpeg process.
 *
 * Browsers cannot play RTSP directly. ffmpeg is started per camera on demand
 * and writes a rolling playlist into storage/hls/<camera-id>/.
 */
final class Hls
{
    public static function dir(int $cameraId): string
    {
        $dir = Snapshot::storageDir('hls') . '/camera-' . $cameraId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }

    public static function playlist(int $cameraId): string
    {
        return self::dir($cameraId) . '/index.m3u8';
    }

    public static function isRunning(int $cameraId): bool
    {
        $pidFile = self::dir($cameraId) . '/ffmpeg.pid';
        if (!is_file($pidFile)) {
            return false;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }

    public static function isAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        $ffmpeg = (string) Settings::get('ffmpeg_path', 'ffmpeg');
        $output = [];
        $code = 1;
        @exec(escapeshellarg($ffmpeg) . ' -version 2>&1', $output, $code);

        return $available = ($code === 0 && str_contains(implode("\n", $output), 'ffmpeg version'));
    }

    /**
     * Start the transcoder if it is not already running.
     *
     * @return array{ok: bool, playlist?: string, error?: string}
     */
    public static function ensure(array $camera): array
    {
        $cameraId = (int) $camera['id'];

        if (!self::isAvailable()) {
            return ['ok' => false, 'error' => 'ffmpeg is not available on this server. Install ffmpeg or use MJPEG/snapshot mode.'];
        }

        $rtsp = StreamUrl::rtspUrl($camera);
        if ($rtsp === null) {
            return ['ok' => false, 'error' => 'No RTSP path configured for this camera.'];
        }

        $dir = self::dir($cameraId);
        $playlist = $dir . '/index.m3u8';

        if (self::isRunning($cameraId)) {
            return ['ok' => true, 'playlist' => $playlist, 'running' => true];
        }

        // Fresh session: drop stale segments so the browser does not replay old video.
        foreach (glob($dir . '/seg*.ts') ?: [] as $stale) {
            @unlink($stale);
        }
        @unlink($playlist);

        $ffmpeg = (string) Settings::get('ffmpeg_path', 'ffmpeg');
        $transport = ($camera['transport'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp';

        $args = [
            $ffmpeg,
            '-nostdin',
            '-loglevel', 'error',
        ];

        // Only RTSP inputs accept the transport flag; HTTP/RTMP sources reject it.
        if (preg_match('#^rtsps?://#i', $rtsp)) {
            $args[] = '-rtsp_transport';
            $args[] = $transport;
        }

        $args = array_merge($args, [
            '-analyzeduration', '2000000',
            '-probesize', '2000000',
            '-i', $rtsp,
            '-c:v', 'copy',
            '-an',
            '-f', 'hls',
            '-hls_time', '2',
            '-hls_list_size', '6',
            '-hls_flags', 'delete_segments+omit_endlist+independent_segments',
            '-hls_segment_filename', $dir . '/seg%05d.ts',
            $playlist,
        ]);

        $command = implode(' ', array_map('escapeshellarg', $args))
            . ' >> ' . escapeshellarg($dir . '/ffmpeg.log') . ' 2>&1 & echo $!';

        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        $pid = (int) ($output[0] ?? 0);

        if ($pid <= 0) {
            return ['ok' => false, 'error' => 'Unable to start the stream converter.'];
        }

        file_put_contents($dir . '/ffmpeg.pid', (string) $pid);
        file_put_contents($dir . '/started_at', (string) time());

        return ['ok' => true, 'playlist' => $playlist, 'pid' => $pid, 'running' => false];
    }

    /** Wait briefly for the first playlist so the player does not open empty. */
    public static function waitForPlaylist(int $cameraId, float $seconds = 7.0): bool
    {
        $playlist = self::playlist($cameraId);
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            clearstatcache(true, $playlist);
            if (is_file($playlist) && filesize($playlist) > 40) {
                return true;
            }

            if (!self::isRunning($cameraId)) {
                return false;
            }

            usleep(250000);
        }

        return is_file($playlist);
    }

    public static function stop(int $cameraId): void
    {
        $dir = self::dir($cameraId);
        $pidFile = $dir . '/ffmpeg.pid';

        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0) {
                @exec('kill ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
            }
            @unlink($pidFile);
        }

        foreach (glob($dir . '/seg*.ts') ?: [] as $segment) {
            @unlink($segment);
        }
        @unlink($dir . '/index.m3u8');
    }

    /** Stop converters that have been idle longer than the configured window. */
    public static function cleanupIdle(?int $minutes = null): int
    {
        $minutes = $minutes ?? max(1, Settings::int('hls_idle_minutes', 15));
        $stopped = 0;

        foreach (glob(Snapshot::storageDir('hls') . '/camera-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $cameraId = (int) substr(basename($dir), strlen('camera-'));

            if (!self::isRunning($cameraId)) {
                continue;
            }

            $lastAccess = 0;
            foreach (['index.m3u8', 'seg00001.ts'] as $file) {
                if (is_file($dir . '/' . $file)) {
                    $lastAccess = max($lastAccess, (int) filemtime($dir . '/' . $file));
                }
            }

            if ($lastAccess > 0 && (time() - $lastAccess) > $minutes * 60) {
                self::stop($cameraId);
                $stopped++;
            }
        }

        return $stopped;
    }
}
