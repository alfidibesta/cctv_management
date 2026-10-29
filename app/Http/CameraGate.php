<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Cameras;
use Argus\Support\Auth;

/**
 * Resolves a camera id from the URL and enforces access rules.
 */
final class CameraGate
{
    public static function resolve(int $id, bool $forWrite = false): array
    {
        $camera = Cameras::find($id);

        if (!$camera) {
            self::deny(404, 'Camera not found.');
        }

        if (!Cameras::canView(Auth::user(), $camera)) {
            self::deny(403, 'You do not have access to this camera.');
        }

        if ($forWrite && !Auth::canManageCameras()) {
            self::deny(403, 'Your role cannot change cameras.');
        }

        return $camera;
    }

    private static function deny(int $code, string $message): never
    {
        http_response_code($code);

        if (Auth::isAjax() || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            json_response(['error' => $message], $code);
        }

        view('pages/error', ['code' => $code, 'message' => $message]);
        exit;
    }
}
