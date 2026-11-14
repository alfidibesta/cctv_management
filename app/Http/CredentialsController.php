<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Services\Credentials;
use Argus\Support\Activity;
use Argus\Support\Auth;

/**
 * Credential decrypt & verify inspector.
 *
 * Returns the plaintext password (operator rights only), the percent-encoded
 * form and the sanitized RTSP URI side by side so an operator can see exactly
 * what the server will hand to ffmpeg or the media server.
 */
final class CredentialsController extends Controller
{
    public function show(string $id): void
    {
        Auth::requireCameraManager();

        $camera = CameraGate::resolve((int) $id, true);
        $report = Credentials::report($camera);

        // The activity feed records the verdict, never the secret: the password
        // is masked and any URI is redacted by Activity::log().
        Activity::log(
            $report['status'],
            sprintf(
                '%s for "%s" — password %s, RTSP URI %s',
                $report['status'] === 'AUTH_VERIFIED' ? 'Credentials verified' : 'Credential encoding problem',
                $camera['name'],
                $report['masked'],
                $report['uri_masked'] !== '' ? $report['uri_masked'] : '(no RTSP path)'
            ),
            'camera',
            (int) $camera['id']
        );

        $this->json($report);
    }
}
