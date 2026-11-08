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
        return self::binary() !== null;
    }

    /** Segment length in seconds. Short segments are what keep HLS latency low. */
    public static function segmentSeconds(): int
    {
        return max(1, min(6, Settings::int('hls_segment_seconds', 2)));
    }

    /**
     * Resolve a working ffmpeg executable once per request.
     *
     * Order: the path configured in Settings, then bin/ffmpeg[.exe] inside the
     * project (drop-in binary, no PATH changes), then whatever PATH offers.
     * This is what makes ffmpeg work on Laragon without a package manager.
     */
    public static function binary(): ?string
    {
        static $resolved = false;
        static $path = null;

        if ($resolved) {
            return $path;
        }
        $resolved = true;

        foreach (self::candidates() as $candidate) {
            if (self::works($candidate)) {
                return $path = $candidate;
            }
        }

        return $path = null;
    }

    /** @return array<int, string> */
    public static function candidates(): array
    {
        $configured = trim((string) Settings::get('ffmpeg_path', 'ffmpeg'));
        $list = [];

        // An explicit path (or a non-default command) wins over the bundled copy.
        if ($configured !== '' && !in_array($configured, ['ffmpeg', 'ffmpeg.exe'], true)) {
            $list[] = $configured;
        }

        $list[] = BASE_PATH . '/bin/ffmpeg.exe';
        $list[] = BASE_PATH . '/bin/ffmpeg';

        if ($configured !== '') {
            $list[] = $configured;
        }

        return array_values(array_unique($list));
    }

    private static function works(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            if (!is_file($binary)) {
                return false;
            }
        }

        $output = [];
        $code = 1;
        @exec(escapeshellarg($binary) . ' -version 2>&1', $output, $code);

        return $code === 0 && str_contains(implode("\n", $output), 'ffmpeg version');
    }

    /**
     * Start the transcoder if it is not already running.
     *
     * @return array{ok: bool, playlist?: string, error?: string}
     */
    public static function ensure(array $camera): array
    {
        $cameraId = (int) $camera['id'];
        $dir = self::dir($cameraId);
        $playlist = $dir . '/index.m3u8';

        // Already running: return before binary resolution, which execs ffmpeg
        // once per candidate. A wall of HLS tiles would pay that cost per tile.
        if (self::isRunning($cameraId)) {
            return [
                'ok'              => true,
                'playlist'        => $playlist,
                'running'         => true,
                'segment_seconds' => self::segmentSeconds(),
            ];
        }

        $ffmpeg = self::binary();
        if ($ffmpeg === null) {
            return [
                'ok'    => false,
                'error' => 'ffmpeg was not found. Drop ffmpeg.exe into the project bin/ folder, set its path in Settings, or use MJPEG/snapshot mode.',
            ];
        }

        $rtsp = StreamUrl::rtspUrl($camera);
        if ($rtsp === null) {
            return ['ok' => false, 'error' => 'No RTSP path configured for this camera.'];
        }

        // Fresh session: drop stale segments so the browser does not replay old video.
        foreach (glob($dir . '/seg*.ts') ?: [] as $stale) {
            @unlink($stale);
        }
        @unlink($playlist);

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
            '-hls_time', (string) self::segmentSeconds(),
            '-hls_list_size', '6',
            // split_by_time caps a segment at hls_time even when the camera only
            // emits a keyframe every few seconds; without it a 10 s GOP camera
            // produces 10 s segments (measured) and the wall lags ~30 s.
            // independent_segments cannot be combined with split_by_time.
            '-hls_flags', 'delete_segments+omit_endlist+split_by_time',
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

        return [
            'ok'              => true,
            'playlist'        => $playlist,
            'pid'             => $pid,
            'running'         => false,
            'segment_seconds' => self::segmentSeconds(),
        ];
    }

    /**
     * A playlist alone is not enough: ffmpeg writes one with an empty segment
     * before the first keyframe arrives, and a player opening that shows nothing.
     */
    public static function hasPlayableSegment(int $cameraId): bool
    {
        $playlist = self::playlist($cameraId);
        if (!is_file($playlist)) {
            return false;
        }

        $body = @file_get_contents($playlist);
        if (!is_string($body) || !str_contains($body, '.ts')) {
            return false;
        }

        foreach (glob(self::dir($cameraId) . '/seg*.ts') ?: [] as $segment) {
            if (filesize($segment) > 1000) {
                return true;
            }
        }

        return false;
    }

    /** Wait briefly for the first decodable segment so the player does not open empty. */
    public static function waitForPlaylist(int $cameraId, float $seconds = 8.0): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            clearstatcache();
            if (self::hasPlayableSegment($cameraId)) {
                return true;
            }

            if (!self::isRunning($cameraId)) {
                return false;
            }

            usleep(200000);
        }

        return self::hasPlayableSegment($cameraId);
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
