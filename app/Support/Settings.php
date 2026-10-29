<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * Database-backed key/value settings with a per-request cache.
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'app_name'          => 'Argus',
        'timezone'          => 'UTC',
        'refresh_interval'  => '5',
        'default_layout'    => '9',
        'snapshot_cache'    => '3',
        'verify_tls'        => '0',
        'ffmpeg_path'       => 'ffmpeg',
        'hls_idle_minutes'  => '15',
        'status_interval'   => '20',
        'wall_title'        => 'Live wall',
    ];

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::DEFAULTS;

        try {
            foreach (Db::all('SELECT `key`, `value` FROM settings') as $row) {
                $values[$row['key']] = (string) $row['value'];
            }
        } catch (\Throwable) {
            // Table not created yet (installer): fall back to defaults.
        }

        return self::$cache = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::all();

        return $values[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, (string) $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key): bool
    {
        return in_array((string) self::get($key, '0'), ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            ['k' => $key, 'v' => $value]
        );

        self::$cache = null;
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set((string) $key, (string) $value);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
