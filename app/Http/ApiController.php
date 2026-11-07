<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Services\Hls;
use Argus\Services\Ptz;
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

    public function ptz(string $id): void
    {
        Auth::requireCameraManager();
        $camera = CameraGate::resolve((int) $id);

        $command = (string) $this->input('command', 'stop');
        $speed = (float) $this->input('speed', 0.5);

        $result = Ptz::move($camera, $command, max(0.05, min(1.0, $speed)));

        Activity::log(
            'ptz.' . $command,
            'PTZ ' . $command . ' on "' . $camera['name'] . '"',
            'camera',
            (int) $camera['id']
        );

        $this->json($result, $result['ok'] ? 200 : 422);
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
                'error' => $result['error'] ?? 'Unable to start the stream.',
                'steps' => \Argus\Services\Diagnostics::stepsFor((string) ($result['error'] ?? ''), $camera),
            ], 422);
        }

        if (!Hls::waitForPlaylist((int) $camera['id'], 8.0)) {
            $log = @file_get_contents(Hls::dir((int) $camera['id']) . '/ffmpeg.log');
            $detail = is_string($log) ? trim(substr($log, -320)) : '';

            Logger::warning('HLS playlist not ready', ['camera' => (int) $camera['id'], 'ffmpeg' => $detail]);
            Activity::log('hls.failed', 'HLS playlist not ready for "' . $camera['name'] . '"', 'camera', (int) $camera['id']);

            $this->json([
                'error'  => 'The camera stream did not start. Check the RTSP path and credentials.',
                'detail' => $detail,
                'steps'  => \Argus\Services\Diagnostics::stepsFor($detail !== '' ? $detail : 'rtsp timed out', $camera),
            ], 422);
        }

        Activity::log('hls.started', 'HLS started for "' . $camera['name'] . '"', 'camera', (int) $camera['id']);

        $this->json([
            'ok'       => true,
            'playlist' => url('/stream.php?camera=' . (int) $camera['id'] . '&type=hls&file=index.m3u8'),
        ]);
    }
}
