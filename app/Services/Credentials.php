<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Crypto;

/**
 * RTSP credential encoding, decoding and verification.
 *
 * Passwords may contain characters that are delimiters inside a URI
 * (`!`, `@`, `#`, `$`, `%`, `&`, `:`, `/`, `?`). They must be percent-encoded
 * with the RFC 3986 unreserved set (the same rule as JavaScript's
 * encodeURIComponent) or a formatter will eat part of the value: `aaa19!`
 * turning into `aaa19 21` is the classic symptom of an unencoded `!` run
 * through a URL/form decoder.
 */
final class Credentials
{
    /** Characters that must be escaped inside the userinfo part of a URI. */
    private const RESERVED = ['!', '@', '#', '$', '%', '&', ':', '/', '?', '+', ';', '=', ' ', '\\'];

    /** RFC 3986 percent-encoding, identical to JavaScript encodeURIComponent. */
    public static function encode(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Decode a percent-encoded value for display.
     *
     * rawurldecode() is used instead of urldecode(): the latter turns "+" into
     * a space, which corrupts passwords such as "a+b".
     */
    public static function decode(string $value): string
    {
        return rawurldecode($value);
    }

    /** Mask a secret for logs and the activity feed. */
    public static function mask(?string $secret): string
    {
        $secret = (string) $secret;
        $length = mb_strlen($secret);

        if ($length === 0) {
            return '(empty)';
        }
        if ($length <= 2) {
            return '••••';
        }

        return mb_substr($secret, 0, 1)
            . str_repeat('•', min(10, max(3, $length - 2)))
            . mb_substr($secret, -1);
    }

    /** Replace every `scheme://user:password@host` password inside a message. */
    public static function redact(string $text): string
    {
        if ($text === '' || !str_contains($text, '://')) {
            return $text;
        }

        return (string) preg_replace_callback(
            '#([a-z][a-z0-9+.\-]*://)([^:/\s@]+):([^@/\s]*)@#i',
            static fn (array $match): string => $match[1] . $match[2] . ':' . self::mask($match[3]) . '@',
            $text
        );
    }

    /**
     * Decrypt the stored password and verify the RTSP URI it produces.
     *
     * @return array{
     *     status: string,
     *     ok: bool,
     *     plaintext: string,
     *     masked: string,
     *     username: string,
     *     encoded_username: string,
     *     encoded_password: string,
     *     decoded_password: string,
     *     uri: string,
     *     uri_masked: string,
     *     host: string,
     *     port: int,
     *     special_characters: array<int, string>,
     *     roundtrip_ok: bool,
     *     issues: array<int, string>,
     *     checked_at: string
     * }
     */
    public static function report(array $camera): array
    {
        $plain = Crypto::decrypt($camera['password_enc'] ?? null);
        $user = (string) ($camera['username'] ?? '');
        $encodedUser = self::encode($user);
        $encodedPassword = self::encode($plain);

        $uri = StreamUrl::sanitizedRtspUrl($camera) ?? '';
        $uriMasked = $uri !== ''
            ? (string) preg_replace(
                '#([a-z][a-z0-9+.\-]*://)([^:/\s@]+):([^@/\s]+)@#i',
                '$1$2:' . self::mask($plain) . '@',
                $uri
            )
            : '';

        $special = [];
        foreach (array_unique(mb_str_split($plain)) as $character) {
            if (in_array($character, self::RESERVED, true)) {
                $special[] = $character;
            }
        }

        $issues = [];
        $decoded = self::decode($encodedPassword);

        if ($plain !== '' && $decoded !== $plain) {
            $issues[] = 'The password does not survive an encode/decode round-trip.';
        }
        if ($plain !== '' && str_contains($uri, ' ')) {
            $issues[] = 'The RTSP URI contains a raw space; the password must be percent-encoded.';
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $uri) === 1) {
            $issues[] = 'The RTSP URI contains a malformed percent escape.';
        }
        if ($plain !== '' && str_contains($uri, ':' . $plain . '@') && $special !== []) {
            $issues[] = 'The password is embedded without encoding; special characters will be misparsed.';
        }
        if ($user === '') {
            $issues[] = 'No username is stored, so the RTSP URI is unauthenticated.';
        }

        if ($issues !== []) {
            $status = 'AUTH_ENCODING_ERROR';
        } elseif ($plain === '') {
            $status = $user === '' ? 'AUTH_NOT_CONFIGURED' : 'AUTH_NO_PASSWORD';
        } else {
            $status = 'AUTH_VERIFIED';
        }

        return [
            'status'             => $status,
            'ok'                 => $status === 'AUTH_VERIFIED',
            'plaintext'          => $plain,
            'masked'             => self::mask($plain),
            'username'           => $user,
            'encoded_username'   => $encodedUser,
            'encoded_password'   => $encodedPassword,
            'decoded_password'   => $decoded,
            'uri'                => $uri,
            'uri_masked'         => $uriMasked,
            'host'               => (string) ($camera['host'] ?? ''),
            'port'               => (int) (($camera['rtsp_port'] ?? 0) ?: 554),
            'special_characters' => $special,
            'roundtrip_ok'       => $plain === '' || $decoded === $plain,
            'issues'             => $issues,
            'checked_at'         => gmdate('c'),
        ];
    }
}
