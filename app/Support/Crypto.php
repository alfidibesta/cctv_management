<?php

declare(strict_types=1);

namespace Argus\Support;

use RuntimeException;

/**
 * AES-256-GCM encryption for camera credentials stored in MySQL.
 *
 * Camera passwords are never sent to the browser: stream URLs are proxied by
 * the server, which decrypts them per request.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $encoded = (string) Config::get('app.key', '');
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'The application key is missing or invalid. It must be 32 random bytes, base64 encoded.'
            );
        }

        return $key;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt camera credentials.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    /** Generate a fresh application key (base64, 32 bytes). */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
