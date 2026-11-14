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

        return self::alive($pid);
    }

    private static function alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/' . $pid);
    }

    /** Wait for a process to disappear, so its camera session is released. */
    private static function waitForExit(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (!self::alive($pid)) {
                return true;
            }

            usleep(50000);
        }

        return !self::alive($pid);
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
     * Latency target for a camera: per-camera value first, global setting next.
     * 50–2000 ms, matching the slider in the camera form and Settings.
     */
    public static function latencyMs(array $camera): int
    {
        $value = (int) ($camera['latency_ms'] ?? 0);

        if ($value <= 0) {
            $value = Settings::int('rtsp_latency_ms', 300);
        }

        return max(50, min(2000, $value));
    }

    /** True when delayed frames should be discarded instead of queued. */
    public static function frameDrop(array $camera): bool
    {
        if (array_key_exists('frame_drop', $camera) && $camera['frame_drop'] !== null) {
            return (int) $camera['frame_drop'] === 1;
        }

        return Settings::bool('rtsp_frame_drop');
    }

    /** RTSP transport: per-camera choice, then the global default. */
    public static function transport(array $camera): string
    {
        $transport = strtolower((string) ($camera['transport'] ?? ''));

        if ($transport === '') {
            $transport = strtolower((string) Settings::get('rtsp_transport', 'tcp'));
        }

        return $transport === 'udp' ? 'udp' : 'tcp';
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
     * @param array{low_latency?: bool} $options Pass low_latency=false for the
     *        compatibility profile: buffered input, 2 s/2 MB probes and a longer
     *        playlist. Some cameras lose their SPS/PPS without input buffering.
     * @return array{ok: bool, playlist?: string, error?: string}
     */
    public static function ensure(array $camera, array $options = []): array
    {
        $lowLatency = (bool) ($options['low_latency'] ?? true);
        $cameraId = (int) $camera['id'];
        $dir = self::dir($cameraId);
        $playlist = $dir . '/index.m3u8';

        $transport = self::transport($camera);
        $latency = self::latencyMs($camera);
        $frameDrop = self::frameDrop($camera);

        // Already running: return before binary resolution, which execs ffmpeg
        // once per candidate. A wall of HLS tiles would pay that cost per tile.
        if (self::isRunning($cameraId)) {
            // The process can outlive a dropped camera and stop writing
            // segments, leaving a playlist that never advances. Restart it
            // instead of handing the player a frozen stream.
            if (self::isFresh($cameraId)) {
                return [
                    'ok'              => true,
                    'playlist'        => $playlist,
                    'running'         => true,
                    'segment_seconds' => self::segmentSeconds(),
                    'latency_ms'      => $latency,
                    'transport'       => $transport,
                    'frame_drop'      => $frameDrop,
                ];
            }

            self::stop($cameraId);
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

        // Jitter buffer: the latency target is turned into ffmpeg's input
        // buffering window. 50 ms is near-passthrough; 2000 ms tolerates a
        // lossy link at the cost of delay.
        $maxDelayMicros = $latency * 1000;
        $analyseMicros = (string) max(200000, min(3000000, $maxDelayMicros * 2));
        $probeBytes = (string) max(200000, min(2000000, $latency * 2000));

        $args = [
            $ffmpeg,
            '-nostdin',
            '-loglevel', 'error',
        ];

        $liveInput = (bool) preg_match('#^(rtsps?|rtmps?)://#i', $rtsp);

        // nobuffer/low_delay only make sense for a live source. On a file or
        // progressive HTTP source they discard packets while probing and the
        // output ends up with no stream at all (measured).
        // -avioflags direct is deliberately not used: it breaks HTTP inputs.
        if ($liveInput && $lowLatency) {
            $args = array_merge($args, ['-fflags', 'nobuffer', '-flags', 'low_delay']);
        }

        // Drop corrupt packets as soon as they arrive instead of letting the
        // decoder wait for a retransmission: this is what keeps a live wall
        // real-time when the camera link is lossy.
        if ($frameDrop && $liveInput) {
            $args = array_merge($args, ['-fflags', '+discardcorrupt']);
        }

        // Only RTSP inputs accept the transport flag; HTTP/RTMP sources reject it.
        if (preg_match('#^rtsps?://#i', $rtsp)) {
            $args[] = '-rtsp_transport';
            $args[] = $transport;
            $args[] = '-max_delay';
            $args[] = (string) $maxDelayMicros;
            $args[] = '-rw_timeout';
            $args[] = (string) max(2000000, $maxDelayMicros * 4);
        }

        $args = array_merge($args, [
            // Scale the analysis window with the latency target instead of
            // always waiting for 1–2 MB of data before the first segment.
            '-analyzeduration', $analyseMicros,
            '-probesize', $probeBytes,
            '-i', $rtsp,
            '-c:v', 'copy',
            '-an',
            '-f', 'hls',
            '-hls_time', (string) self::segmentSeconds(),
            // A short window keeps the player close to the live edge.
            '-hls_list_size', $lowLatency ? '3' : '6',
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
            'latency_ms'      => $latency,
            'transport'       => $transport,
            'frame_drop'      => $frameDrop,
        ];
    }

    /** True while the converter is still producing segments. */
    private static function isFresh(int $cameraId): bool
    {
        $newest = 0;

        foreach (glob(self::dir($cameraId) . '/seg*.ts') ?: [] as $segment) {
            $newest = max($newest, (int) filemtime($segment));
        }

        if ($newest === 0) {
            // No segment yet: allow the startup grace period before restarting.
            $started = (int) @file_get_contents(self::dir($cameraId) . '/started_at');

            return $started > 0 && (time() - $started) < 20;
        }

        return (time() - $newest) < 20;
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
        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;

        if (self::alive($pid)) {
            @exec('kill ' . escapeshellarg((string) $pid) . ' 2>/dev/null');

            // Cameras often accept a single RTSP client, so the next converter
            // must not start while this one still holds the session.
            if (!self::waitForExit($pid, 2.0)) {
                @exec('kill -9 ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
                self::waitForExit($pid, 1.0);
            }
        }

        @unlink($pidFile);

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
