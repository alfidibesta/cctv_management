<?php

declare(strict_types=1);

namespace Argus\Services;

/**
 * Turns a raw camera probe result into a short verdict plus concrete fixes.
 *
 * Shown right after a camera is saved and inside the "Test" result panel, so
 * an operator does not have to guess why a camera stays offline.
 */
final class Diagnostics
{
    /**
     * @param array $camera Camera row (driver, stream_mode, host, port, paths).
     * @param array $result Output of Snapshot::probe()/fetch().
     * @return array{ok: bool, headline: string, detail: string, steps: array<int, string>, latency_ms: ?int, error: ?string}
     */
    public static function forCamera(array $camera, array $result): array
    {
        $ok = !empty($result['ok']);
        $latency = isset($result['latency_ms']) ? (int) $result['latency_ms'] : null;
        $error = isset($result['error']) && $result['error'] !== '' ? (string) $result['error'] : null;
        $mode = (string) ($camera['stream_mode'] ?? 'mjpeg');

        if ($ok) {
            $headline = $latency !== null
                ? sprintf('Camera answered in %d ms', $latency)
                : 'Camera answered';

            $detail = match ($mode) {
                'hls'      => 'The snapshot endpoint works. HLS needs a working RTSP path and ffmpeg on this server.',
                'snapshot' => 'Snapshot mode is ready: the wall will refresh this image on an interval.',
                default    => 'MJPEG mode is ready: the wall will keep this stream open.',
            };

            $steps = $mode === 'hls'
                ? [
                    'If the wall stays on "no signal", open the RTSP path with VLC to confirm it plays.',
                    'Check Settings → Streaming: the ffmpeg path must point to a working binary.',
                ]
                : [];

            return [
                'ok'         => true,
                'headline'   => $headline,
                'detail'     => $detail,
                'steps'      => $steps,
                'latency_ms' => $latency,
                'error'      => null,
            ];
        }

        return [
            'ok'         => false,
            'headline'   => $error ?? 'Camera did not answer',
            'detail'     => 'The camera was saved, but Argus could not read an image from it yet.',
            'steps'      => self::stepsFor((string) $error, $camera),
            'latency_ms' => $latency,
            'error'      => $error,
        ];
    }

    /**
     * Actionable next steps for a probe failure message.
     *
     * @return array<int, string>
     */
    public static function stepsFor(string $error, array $camera = []): array
    {
        $message = mb_strtolower($error);
        $host = (string) ($camera['host'] ?? '');
        $port = (int) ($camera['port'] ?? 80);
        $driver = StreamUrl::driverLabel((string) ($camera['driver'] ?? ''));
        $address = $host !== '' ? $host . ':' . $port : 'the camera address';

        $match = static function (array $needles) use ($message): bool {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($message, $needle)) {
                    return true;
                }
            }

            return false;
        };

        if ($match(['rejected the credentials', 'http 401', 'http 403', 'unauthorized'])) {
            return [
                'Check the username and password on the camera itself — use the viewing account, not a temporary one.',
                'Re-open this camera, type the password again and save. Old passwords encrypted with a different app.key cannot be read.',
                'Confirm the account is allowed HTTP snapshot access; some cameras need "RTSP/HTTP authentication" enabled per user.',
                'Verify from a browser or with: curl -u user:pass http://' . $address . '/ — it should return an image.',
            ];
        }

        if ($match(['timed out', 'timeout'])) {
            return [
                'Ping the camera from this server: ping ' . ($host !== '' ? $host : 'CAMERA_IP') . '.',
                'Make sure port ' . $port . ' is open (web/HTTP port, not the RTSP 554 port).',
                'Check that Argus and the camera sit on the same network or VPN, and that no firewall blocks the server.',
            ];
        }

        if ($match(['could not be resolved', 'getaddrinfo', 'name or service not known'])) {
            return [
                'The host name does not resolve. Use the camera IP address instead of its hostname.',
                'If you must use a name, add it to the hosts file of the machine running Argus (Laragon: C:\Windows\System32\drivers\etc\hosts).',
            ];
        }

        if ($match(['connection refused', 'could not connect to server', 'failed to connect'])) {
            return [
                'The camera is reachable but nothing is listening on port ' . $port . '. Double-check the HTTP port (80, 8080, 8000...).',
                'Open http://' . $address . '/ in a browser to confirm the web interface answers.',
            ];
        }

        if ($match(['not an image'])) {
            return [
                'The snapshot path returned a web page instead of a picture. Point it at a JPEG endpoint, e.g. /snapshot.jpg or /cgi-bin/snapshot.cgi.',
                'For ' . $driver . ', the expected snapshot path is shown when you select the driver in the camera form.',
                'Test the exact URL in a browser: if you see a login page or JSON, the path is wrong.',
            ];
        }

        if ($match(['snapshot path not found', 'http 404', 'no snapshot url'])) {
            return [
                'Pick the right driver preset, or set the snapshot path manually in the camera form.',
                'Typical paths: ACTi /cgi-bin/encoder?SNAPSHOT · Hikvision /ISAPI/Streaming/channels/101/picture · Dahua /cgi-bin/snapshot.cgi · Axis /axis-cgi/jpg/image.cgi.',
                'Browse the camera web UI and copy the image URL it uses for its live preview.',
            ];
        }

        if ($match(['tls', 'ssl', 'certificate'])) {
            return [
                'The camera uses a self-signed certificate. In Settings → Streaming, turn off "Verify camera TLS certificates".',
                'Alternatively switch the camera path to http:// instead of https://.',
            ];
        }

        if ($match(['empty response'])) {
            return [
                'The camera accepted the request but sent no image data. Try another stream profile or lower resolution.',
                'Some cameras return an empty body until a session is established — retry the test once.',
            ];
        }

        if ($match(['larger than 8 mb'])) {
            return [
                'The snapshot is too large. Use a lower-resolution stream profile on the camera.',
            ];
        }

        if ($match(['curl extension', 'php curl'])) {
            return [
                'Enable the PHP cURL extension: in Laragon open Menu → PHP → php.ini and uncomment extension=curl, then restart.',
                'Reload Settings afterwards — the cURL row in Server diagnostics must say "Ready".',
            ];
        }

        if ($match(['ffmpeg'])) {
            return [
                'HLS needs ffmpeg. Put ffmpeg.exe in the project bin/ folder (no PATH change needed) or set its full path in Settings → Streaming.',
                'Re-open Settings after copying the file to confirm "ffmpeg (HLS)" says Ready.',
            ];
        }

        return [
            'Open http://' . $address . '/ in a browser from the Argus server and sign in to the camera.',
            'Check the snapshot path and driver preset in the camera form, then press Test connection again.',
            'If the camera is on another subnet, confirm routing and firewall rules between server and camera.',
        ];
    }
}
