<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Services\Hls;
use Argus\Services\MediaServer;
use Argus\Services\Snapshot;
use Argus\Support\Activity;
use Argus\Support\Auth;
use Argus\Support\Logger;

final class ApiController extends Controller
{
    /** Health check for the cameras currently on the wall. */
    public function status(): void
    {
        $raw = (string) ($_GET['ids'] ?? '');
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', $raw)))), 0, 40);

        if (!$ids) {
            $this->json(['ok' => true, 'cameras' => []]);
        }

        $user = Auth::user();
        $cameras = array_values(array_filter(
            Cameras::findMany($ids),
            static fn (array $camera): bool => Cameras::canView($user, $camera)
        ));

        $probes = $cameras ? Snapshot::probeMany($cameras) : [];
        $payload = [];

        foreach ($cameras as $camera) {
            $id = (int) $camera['id'];
            $probe = $probes[$id] ?? ['status' => 'unknown', 'latency_ms' => null, 'error' => 'Not probed.'];

            $payload[] = [
                'id'         => $id,
                'status'     => $probe['status'],
                'latency_ms' => $probe['latency_ms'],
                'error'      => $probe['error'],
                'checked_at' => gmdate('c'),
            ];
        }

        $this->json(['ok' => true, 'cameras' => $payload]);
    }

    /** Start the ffmpeg HLS converter for an RTSP camera and return its playlist. */
    public function hls(string $id): void
    {
        $camera = CameraGate::resolve((int) $id);

        if (($camera['stream_mode'] ?? '') !== 'hls') {
            $this->json(['error' => 'This camera is not configured for HLS streaming.'], 422);
        }

        $result = Hls::ensure($camera);

        if (!$result['ok']) {
            Logger::warning('HLS start failed: ' . ($result['error'] ?? 'unknown'), [
                'camera' => (int) $camera['id'],
            ]);
            Activity::log('hls.failed', 'HLS failed for "' . $camera['name'] . '": ' . ($result['error'] ?? ''), 'camera', (int) $camera['id']);

            $this->json([
                'error'   => $result['error'] ?? 'Unable to start the stream.',
                'fallback' => 'test-pattern',
                'steps'   => \Argus\Services\Diagnostics::stepsFor((string) ($result['error'] ?? ''), $camera),
            ], 422);
        }

        if (!Hls::waitForPlaylist((int) $camera['id'], 8.0)) {
            $detail = $this->ffmpegTail((int) $camera['id']);

            Logger::warning('HLS low-latency start failed, retrying in compatibility mode', [
                'camera' => (int) $camera['id'],
                'ffmpeg' => $detail,
            ]);

            // Cameras that drop their SPS/PPS without input buffering fail with
            // the low-latency flags. Retry once with the buffered profile before
            // telling the wall the stream is unavailable.
            Hls::stop((int) $camera['id']);
            $retry = Hls::ensure($camera, ['low_latency' => false]);

            if ($retry['ok'] && Hls::waitForPlaylist((int) $camera['id'], 8.0)) {
                Activity::log('hls.started', 'HLS started in compatibility mode for "' . $camera['name'] . '"', 'camera', (int) $camera['id']);
            } else {
                $detail = $this->ffmpegTail((int) $camera['id']) ?: $detail;

                Logger::warning('HLS playlist not ready', ['camera' => (int) $camera['id'], 'ffmpeg' => $detail]);
                Activity::log('hls.failed', 'HLS playlist not ready for "' . $camera['name'] . '"', 'camera', (int) $camera['id']);

                $this->json([
                    'error'    => 'The camera stream did not start. Check the RTSP path and credentials.',
                    'detail'   => $detail,
                    'fallback' => 'test-pattern',
                    'steps'    => \Argus\Services\Diagnostics::stepsFor($detail !== '' ? $detail : 'rtsp timed out', $camera),
                ], 422);
            }
        }

        Activity::log('hls.started', 'HLS started for "' . $camera['name'] . '"', 'camera', (int) $camera['id']);

        $this->json([
            'ok'              => true,
            'playlist'        => url('/stream.php?camera=' . (int) $camera['id'] . '&type=hls&file=index.m3u8'),
            'segment_seconds' => Hls::segmentSeconds(),
            'latency_ms'      => $result['latency_ms'] ?? Hls::latencyMs($camera),
            'transport'       => $result['transport'] ?? Hls::transport($camera),
            'frame_drop'      => $result['frame_drop'] ?? Hls::frameDrop($camera),
        ]);
    }

    /**
     * WebRTC (WHEP) signalling relay.
     *
     * The browser posts an SDP offer here; PHP forwards it to go2rtc or
     * MediaMTX and returns the answer. The media server port is never exposed
     * to the browser, and access is checked against the camera's group rules.
     */
    public function webrtc(string $id): void
    {
        $camera = CameraGate::resolve((int) $id);

        $payload = json_decode((string) file_get_contents('php://input'), true);
        $offer = is_array($payload) ? (string) ($payload['sdp'] ?? '') : '';

        if (trim($offer) === '') {
            $this->json([
                'error'    => 'An SDP offer is required.',
                'fallback' => $this->fallbackFor($camera),
            ], 422);
        }

        $result = MediaServer::offer((int) $camera['id'], $offer);

        if (!$result['ok']) {
            $this->json([
                'error'    => $result['error'] ?? 'The media server did not answer.',
                'fallback' => $this->fallbackFor($camera),
            ], 502);
        }

        Activity::log(
            'webrtc.session',
            'WebRTC session opened for "' . $camera['name'] . '" via ' . strtoupper((string) $result['driver']),
            'camera',
            (int) $camera['id']
        );

        $this->json([
            'ok'         => true,
            'type'       => 'answer',
            'sdp'        => $result['sdp'] ?? '',
            'session'    => $result['session'] ?? null,
            'driver'     => $result['driver'] ?? MediaServer::driver(),
            'latency_ms' => Hls::latencyMs($camera),
        ]);
    }

    /**
     * Per-camera transport and jitter buffer, saved from the wall's stream
     * popover. The next stream start picks the new values up.
     */
    public function stream(string $id): void
    {
        Auth::requireCameraManager();

        $camera = CameraGate::resolve((int) $id, true);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $payload = is_array($payload) ? $payload : $_POST;

        $transport = strtolower((string) ($payload['transport'] ?? 'tcp'));
        $latency = (int) ($payload['latency_ms'] ?? 0);
        $frameDrop = filter_var($payload['frame_drop'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($transport, ['udp', 'tcp'], true)) {
            $this->json(['error' => 'Transport must be UDP or TCP.'], 422);
        }

        if ($latency < 50 || $latency > 2000) {
            $this->json(['error' => 'The jitter buffer must be between 50 and 2000 ms.'], 422);
        }

        Cameras::saveStreamSettings((int) $camera['id'], $transport, $latency, $frameDrop);

        Activity::log(
            'camera.stream',
            sprintf('%s stream set to %s with a %d ms buffer', $camera['name'], strtoupper($transport), $latency),
            'camera',
            (int) $camera['id']
        );

        $this->json([
            'ok'         => true,
            'transport'  => $transport,
            'latency_ms' => $latency,
            'frame_drop' => $frameDrop,
        ]);
    }

    /** Media mode the wall should use when WebRTC is unavailable. */
    private function fallbackFor(array $camera): string
    {
        return match ((string) ($camera['stream_mode'] ?? '')) {
            'hls'   => 'hls',
            'mjpeg' => 'mjpeg',
            default => 'snapshot',
        };
    }

    /** Last lines of the ffmpeg log, for the error response and the app log. */
    private function ffmpegTail(int $cameraId): string
    {
        $log = @file_get_contents(Hls::dir($cameraId) . '/ffmpeg.log');

        return is_string($log) ? trim(substr($log, -320)) : '';
    }
}
