<?php

declare(strict_types=1);

namespace Argus\Services;

use Argus\Support\Db;
use Argus\Support\Logger;

/**
 * Camera health history: uptime, latency and downtime.
 *
 * A sample is written at most once a minute per camera while its state is
 * steady, plus immediately on every online<->offline transition. That keeps a
 * week of history small (a busy 9-camera wall is roughly 10k rows) while still
 * preserving every outage.
 */
final class Health
{
    public const RETENTION_DAYS = 14;

    /** Minutes represented by one stored sample (used for downtime estimates). */
    private const SAMPLE_MINUTES = 1;

    private const MAX_CSV_ROWS = 50000;

    private static bool $ready = false;

    /** Create the table on older installs; install/schema.sql has it for new ones. */
    public static function ensureTable(): bool
    {
        if (self::$ready) {
            return true;
        }

        try {
            Db::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS camera_checks (
                    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    camera_id  INT UNSIGNED NOT NULL,
                    status     ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
                    latency_ms INT UNSIGNED NULL,
                    error      VARCHAR(255) NULL,
                    checked_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY camera_checks_camera_time_idx (camera_id, checked_at),
                    CONSTRAINT camera_checks_camera_fk FOREIGN KEY (camera_id)
                        REFERENCES cameras (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            self::$ready = true;

            return true;
        } catch (\Throwable $e) {
            Logger::debug('health.schema_failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Store a check result. Never throws: health history must not break a probe.
     */
    public static function record(int $cameraId, string $status, ?int $latency = null, ?string $error = null): void
    {
        if ($cameraId <= 0 || !in_array($status, ['online', 'offline', 'unknown'], true)) {
            return;
        }

        if (!self::ensureTable()) {
            return;
        }

        try {
            // Single statement: insert unless an identical sample was stored recently.
            // Distinct placeholders because native prepares reject reused names.
            Db::run(
                'INSERT INTO camera_checks (camera_id, status, latency_ms, error, checked_at)
                 SELECT :camera_id, :status, :latency, :error, :checked_at FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM camera_checks
                     WHERE camera_id = :recent_camera
                       AND status = :recent_status
                       AND checked_at > :since
                 )',
                [
                    'camera_id'     => $cameraId,
                    'status'        => $status,
                    'latency'       => $latency,
                    'error'         => $error !== null ? mb_substr($error, 0, 255) : null,
                    'checked_at'    => gmdate('Y-m-d H:i:s'),
                    'recent_camera' => $cameraId,
                    'recent_status' => $status,
                    'since'         => gmdate('Y-m-d H:i:s', time() - 60),
                ]
            );
        } catch (\Throwable $e) {
            Logger::debug('health.record_failed', ['camera' => $cameraId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Aggregate one window with a single query.
     *
     * @return array<int, array{samples: int, online: int, offline: int, uptime: ?float,
     *                            avg_latency: ?int, downtime_minutes: int, last_check: ?string}>
     */
    public static function window(int $days): array
    {
        if (!self::ensureTable()) {
            return [];
        }

        $since = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);

        try {
            $rows = Db::all(
                'SELECT camera_id,
                        COUNT(*) AS samples,
                        COALESCE(SUM(status = :online), 0) AS online_count,
                        COALESCE(SUM(status = :offline), 0) AS offline_count,
                        AVG(NULLIF(latency_ms, 0)) AS avg_latency,
                        MAX(checked_at) AS last_check
                 FROM camera_checks
                 WHERE checked_at >= :since
                 GROUP BY camera_id',
                ['online' => 'online', 'offline' => 'offline', 'since' => $since]
            );
        } catch (\Throwable $e) {
            Logger::debug('health.window_failed', ['error' => $e->getMessage()]);

            return [];
        }

        $stats = [];
        foreach ($rows as $row) {
            $samples = (int) $row['samples'];
            $online = (int) $row['online_count'];
            $offline = (int) $row['offline_count'];

            $stats[(int) $row['camera_id']] = [
                'samples'          => $samples,
                'online'           => $online,
                'offline'          => $offline,
                'uptime'           => $samples > 0 ? round($online * 100 / $samples, 1) : null,
                'avg_latency'      => $row['avg_latency'] !== null ? (int) round((float) $row['avg_latency']) : null,
                'downtime_minutes' => $offline * self::SAMPLE_MINUTES,
                'last_check'       => $row['last_check'] !== null ? (string) $row['last_check'] : null,
            ];
        }

        return $stats;
    }

    /**
     * Uptime per hour bucket for a sparkline, all cameras in one query.
     *
     * @return array<int, array<int, ?float>> camera id => [bucket => uptime %]
     */
    public static function bars(int $hours = 24, int $buckets = 24): array
    {
        if (!self::ensureTable()) {
            return [];
        }

        $hours = max(1, min($hours, 72));
        $buckets = max(4, min($buckets, 72));
        $sinceTs = time() - $hours * 3600;
        $span = max(1, (int) floor($hours * 3600 / $buckets));

        try {
            $rows = Db::all(
                // Buckets are measured against NOW() inside MySQL: the stored
                // timestamps are UTC while the MySQL session may not be, so
                // UNIX_TIMESTAMP() arithmetic on them would shift every sample
                // out of range.
                'SELECT camera_id,
                        :buckets - 1 - FLOOR(TIMESTAMPDIFF(SECOND, checked_at, NOW()) / :span) AS bucket,
                        COUNT(*) AS samples,
                        COALESCE(SUM(status = :online), 0) AS online_count
                 FROM camera_checks
                 WHERE checked_at >= :since_text
                 GROUP BY camera_id, bucket',
                [
                    'buckets'    => $buckets,
                    'span'       => $span,
                    'online'     => 'online',
                    'since_text' => gmdate('Y-m-d H:i:s', $sinceTs),
                ]
            );
        } catch (\Throwable $e) {
            Logger::debug('health.bars_failed', ['error' => $e->getMessage()]);

            return [];
        }

        $series = [];
        foreach ($rows as $row) {
            $cameraId = (int) $row['camera_id'];
            $bucket = (int) $row['bucket'];
            if ($bucket < 0 || $bucket >= $buckets) {
                continue;
            }
            $samples = (int) $row['samples'];
            $series[$cameraId][$bucket] = $samples > 0
                ? round((int) $row['online_count'] * 100 / $samples, 0)
                : null;
        }

        return $series;
    }

    /**
     * Raw samples for the CSV export, newest first.
     *
     * @return array<int, array>
     */
    public static function samples(int $days, ?int $cameraId = null): array
    {
        if (!self::ensureTable()) {
            return [];
        }

        $sql = 'SELECT cc.camera_id, c.name AS camera, c.host, cc.checked_at, cc.status, cc.latency_ms, cc.error
                FROM camera_checks cc
                JOIN cameras c ON c.id = cc.camera_id
                WHERE cc.checked_at >= :since';
        $params = ['since' => gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)];

        if ($cameraId !== null && $cameraId > 0) {
            $sql .= ' AND cc.camera_id = :camera';
            $params['camera'] = $cameraId;
        }

        $sql .= ' ORDER BY cc.id DESC LIMIT ' . self::MAX_CSV_ROWS;

        try {
            return Db::all($sql, $params);
        } catch (\Throwable $e) {
            Logger::debug('health.samples_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Delete samples older than the retention window. */
    public static function prune(int $days = self::RETENTION_DAYS): int
    {
        if (!self::ensureTable()) {
            return 0;
        }

        try {
            return Db::run(
                'DELETE FROM camera_checks WHERE checked_at < :cutoff LIMIT 20000',
                ['cutoff' => gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)]
            )->rowCount();
        } catch (\Throwable $e) {
            Logger::debug('health.prune_failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
