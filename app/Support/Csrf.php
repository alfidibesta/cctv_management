<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * CSRF protection. Tokens are per-session and rotate on login.
 */
final class Csrf
{
    public const FIELD = '_token';

    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function verify(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';

        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    /** Token presented by a POST request, form field or header. */
    public static function fromRequest(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $field = $_POST[self::FIELD] ?? null;

        return is_string($field) ? $field : null;
    }
}
