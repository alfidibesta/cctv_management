<?php

declare(strict_types=1);

namespace Argus\Repositories;

use Argus\Support\Db;

/**
 * People-counting configuration and foot-traffic samples.
 *
 * The browser batches counting events every 5 seconds; this repository stores
 * them next to the camera records and aggregates them for the analytics panel.
 */
final class Analytics
{
    public const MODES = ['off', 'tripwire', 'zone', 'both'];

    public const DEFAULT_CONFIG = [
        'enabled'        => false,
        'confidence'     => 0.55,
        'boxes'          => true,
        'mode'           => 'off',
        'tripwire'       => null,
        'flip'           => false,
        'zone'           => null,
        'zone_threshold' => 5,
    ];

    /** Decode a camera's stored analytics JSON, falling back to defaults. */
    public static function config(?string $json): array
    {
        $decoded = $json !== null && $json !== '' ? json_decode($json, true) : null;

        return self::sanitize(is_array($decoded) ? $decoded : []);
    }

    /** Clamp and validate a config payload coming from the wall UI. */
    public static function sanitize(array $raw): array
    {
        $mode = (string) ($raw['mode'] ?? 'off');
        if (!in_array($mode, self::MODES, true)) {
            $mode = 'off';
        }

        $tripwire = self::point($raw['tripwire']['a'] ?? null);
        $tripwireEnd = self::point($raw['tripwire']['b'] ?? null);

        return [
            'enabled'        => !empty($raw['enabled']),
            'confidence'     => round(max(0.30, min(0.95, (float) ($raw['confidence'] ?? 0.55))), 2),
            'boxes'          => !array_key_exists('boxes', $raw) || !empty($raw['boxes']),
            'mode'           => $mode,
            'tripwire'       => $tripwire !== null && $tripwireEnd !== null
                ? ['a' => $tripwire, 'b' => $tripwireEnd]
                : null,
            'flip'           => !empty($raw['flip']),
            'zone'           => self::rect($raw['zone'] ?? null),
            'zone_threshold' => max(1, min(999, (int) ($raw['zone_threshold'] ?? 5))),
        ];
    }

    public static function saveConfig(int $cameraId, array $raw): array
    {
        $config = self::sanitize($raw);

        Db::update('cameras', [
            'analytics_json' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $cameraId]);

        return $config;
    }

    /**
     * Store a batch of counting events.
     *
     * @param array<int, array{direction?: ?string, occupancy?: int, confidence?: float, occurred_at?: string}> $events
     */
    public static function record(int $cameraId, array $events): int
    {
        $events = array_slice($events, 0, 500);
        if ($events === []) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');

        Db::transaction(static function () use ($cameraId, $events, $now): void {
            foreach ($events as $event) {
                $direction = $event['direction'] ?? null;
                $direction = in_array($direction, ['IN', 'OUT'], true) ? $direction : null;

                Db::insert('person_events', [
                    'camera_id'   => $cameraId,
                    'direction'   => $direction,
                    'occupancy'   => max(0, min(65535, (int) ($event['occupancy'] ?? 0))),
                    'confidence'  => round(max(0, min(1, (float) ($event['confidence'] ?? 0))), 2),
                    'occurred_at' => self::timestamp((string) ($event['occurred_at'] ?? $now)),
                    'created_at'  => $now,
                ]);
            }
        });

        return count($events);
    }

    /** Totals for one day: IN, OUT, current occupancy and peak occupancy. */
    public static function totals(int $cameraId, string $day): array
    {
        $row = Db::one(
            "SELECT COALESCE(SUM(direction = 'IN'), 0) AS ins,
                    COALESCE(SUM(direction = 'OUT'), 0) AS outs,
                    COALESCE(MAX(occupancy), 0) AS peak
             FROM person_events
             WHERE camera_id = :camera AND DATE(occurred_at) = :day",
            ['camera' => $cameraId, 'day' => $day]
        );

        $latest = Db::val(
            'SELECT occupancy FROM person_events
             WHERE camera_id = :camera AND DATE(occurred_at) = :day
             ORDER BY id DESC LIMIT 1',
            ['camera' => $cameraId, 'day' => $day]
        );

        return [
            'in'     => (int) ($row['ins'] ?? 0),
            'out'    => (int) ($row['outs'] ?? 0),
            'inside' => (int) ($latest ?? 0),
            'peak'   => (int) ($row['peak'] ?? 0),
        ];
    }

    /** 24 hourly buckets for the bar chart. */
    public static function hourly(int $cameraId, string $day): array
    {
        $rows = Db::all(
            "SELECT HOUR(occurred_at) AS hour,
                    SUM(direction = 'IN') AS ins,
                    SUM(direction = 'OUT') AS outs,
                    MAX(occupancy) AS peak
             FROM person_events
             WHERE camera_id = :camera AND DATE(occurred_at) = :day
             GROUP BY HOUR(occurred_at)",
            ['camera' => $cameraId, 'day' => $day]
        );

        $buckets = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $buckets[$hour] = ['hour' => $hour, 'in' => 0, 'out' => 0, 'peak' => 0];
        }

        foreach ($rows as $row) {
            $hour = (int) $row['hour'];
            $buckets[$hour] = [
                'hour' => $hour,
                'in'   => (int) $row['ins'],
                'out'  => (int) $row['outs'],
                'peak' => (int) $row['peak'],
            ];
        }

        return array_values($buckets);
    }

    /** Daily totals for the last N days. */
    public static function daily(int $cameraId, int $days = 14): array
    {
        $days = max(1, min(90, $days));

        $rows = Db::all(
            "SELECT DATE(occurred_at) AS day,
                    SUM(direction = 'IN') AS ins,
                    SUM(direction = 'OUT') AS outs,
                    MAX(occupancy) AS peak
             FROM person_events
             WHERE camera_id = :camera AND occurred_at >= (UTC_DATE() - INTERVAL {$days} DAY)
             GROUP BY DATE(occurred_at)
             ORDER BY day",
            ['camera' => $cameraId]
        );

        return array_map(static fn (array $row): array => [
            'day'  => (string) $row['day'],
            'in'   => (int) $row['ins'],
            'out'  => (int) $row['outs'],
            'peak' => (int) $row['peak'],
        ], $rows);
    }

    /** Most recent samples, for the analytics table. */
    public static function recent(int $cameraId, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));

        return Db::all(
            "SELECT direction, occupancy, confidence, occurred_at
             FROM person_events
             WHERE camera_id = :camera
             ORDER BY id DESC
             LIMIT {$limit}",
            ['camera' => $cameraId]
        );
    }

    /** @return array{x: float, y: float}|null */
    private static function point(mixed $value): ?array
    {
        if (!is_array($value) || !isset($value['x'], $value['y']) || !is_numeric($value['x']) || !is_numeric($value['y'])) {
            return null;
        }

        return [
            'x' => round(max(0, min(1, (float) $value['x'])), 4),
            'y' => round(max(0, min(1, (float) $value['y'])), 4),
        ];
    }

    /** @return array{x: float, y: float, w: float, h: float}|null */
    private static function rect(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (!isset($value[$key]) || !is_numeric($value[$key])) {
                return null;
            }
        }

        $x = max(0, min(1, (float) $value['x']));
        $y = max(0, min(1, (float) $value['y']));
        $w = max(0.02, min(1 - $x, (float) $value['w']));
        $h = max(0.02, min(1 - $y, (float) $value['h']));

        return ['x' => round($x, 4), 'y' => round($y, 4), 'w' => round($w, 4), 'h' => round($h, 4)];
    }

    /** Accept ISO-8601 or SQL datetimes, always stored as UTC SQL time. */
    private static function timestamp(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}
