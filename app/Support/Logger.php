<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * Append-only JSON-lines logger for storage/logs/app.log.
 *
 * Every line is one event so the activity page can render it without a parser:
 * {"time":"2026-01-01T00:00:00+00:00","level":"error","message":"...","context":{}}
 */
final class Logger
{
    private const MAX_BYTES = 2_000_000;
    private const KEEP = 3;

    private static bool $rotated = false;

    public static function path(): string
    {
        return BASE_PATH . '/storage/logs/app.log';
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        self::rotate();

        $line = json_encode([
            'time'    => gmdate('c'),
            'level'   => $level,
            // Credentials inside URIs are masked before they reach the log.
            'message' => \Argus\Services\Credentials::redact(mb_substr($message, 0, 2000)),
            'context' => self::cleanContext($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($line === false) {
            return;
        }

        @file_put_contents(self::path(), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Newest log entries first, optionally filtered by level.
     *
     * @return array<int, array{time: string, level: string, message: string, context: array}>
     */
    public static function tail(int $limit = 200, string $level = ''): array
    {
        $limit = max(1, min($limit, 1000));
        $file = self::path();

        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = self::readLastLines($file, $limit * 2);
        $rows = [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $decoded = json_decode($lines[$i], true);
            if (!is_array($decoded) || !isset($decoded['message'])) {
                continue;
            }
            if ($level !== '' && ($decoded['level'] ?? '') !== $level) {
                continue;
            }

            $rows[] = [
                'time'    => (string) ($decoded['time'] ?? ''),
                'level'   => (string) ($decoded['level'] ?? 'info'),
                'message' => (string) $decoded['message'],
                'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public static function countByLevel(string $level): int
    {
        $count = 0;
        foreach (self::tail(1000) as $row) {
            if ($row['level'] === $level) {
                $count++;
            }
        }

        return $count;
    }

    public static function clear(): void
    {
        @file_put_contents(self::path(), '');
    }

    /** @return array<int, string> */
    private static function readLastLines(string $file, int $max): array
    {
        $size = (int) @filesize($file);
        if ($size <= 0) {
            return [];
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        // Read at most the last 512 KB: enough for the activity page.
        $chunk = min($size, 524288);
        fseek($handle, -$chunk, SEEK_END);
        $data = (string) fread($handle, $chunk);
        fclose($handle);

        $lines = preg_split('/\R/', $data) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        return array_slice($lines, -$max);
    }

    private static function rotate(): void
    {
        if (self::$rotated) {
            return;
        }
        self::$rotated = true;

        $file = self::path();
        if (!is_file($file) || (int) @filesize($file) < self::MAX_BYTES) {
            return;
        }

        for ($i = self::KEEP; $i > 1; $i--) {
            $from = $file . '.' . ($i - 1);
            $to = $file . '.' . $i;
            if (is_file($from)) {
                @rename($from, $to);
            }
        }

        @rename($file, $file . '.1');
    }

    /** Keep log lines readable: trim long strings and nested arrays. */
    private static function cleanContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = \Argus\Services\Credentials::redact(mb_substr($value, 0, 600));
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = array_slice(array_map(
                    static fn ($item): mixed => is_scalar($item) ? $item : gettype($item),
                    $value
                ), 0, 20);
            }
        }

        return $clean;
    }
}
