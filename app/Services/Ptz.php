<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Crypto;

/**
 * Pan / tilt / zoom through a configurable HTTP command template.
 *
 * Many IP cameras expose PTZ over a simple GET URL (ACTi CGI, Axis VAPIX,
 * Dahua/Lorex style). The template lives on the camera record so a single
 * install can mix vendors; ONVIF-SOAP-only devices need their own endpoint.
 */
final class Ptz
{
    /**
     * @return array{ok: bool, error?: string, latency_ms?: int}
     */
    public static function move(array $camera, string $command, float $speed = 0.5): array
    {
        if (!array_key_exists($command, StreamUrl::PTZ_COMMANDS)) {
            return ['ok' => false, 'error' => 'Unknown PTZ command.'];
        }

        if (!(int) ($camera['ptz_enabled'] ?? 0)) {
            return ['ok' => false, 'error' => 'PTZ is disabled for this camera.'];
        }

        $url = StreamUrl::ptzUrl($camera, $command, $speed);
        if ($url === null) {
            return ['ok' => false, 'error' => 'No PTZ command URL configured for this camera.'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'The PHP cURL extension is required.'];
        }

        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'Argus/' . ARGUS_VERSION,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $user = (string) ($camera['username'] ?? '');
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . Crypto::decrypt($camera['password_enc'] ?? null));
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($body === false || $status >= 400 || $status === 0) {
            return [
                'ok'         => false,
                'error'      => $error !== '' ? $error : 'Camera returned HTTP ' . $status,
                'latency_ms' => $latency,
            ];
        }

        return ['ok' => true, 'latency_ms' => $latency];
    }
}
