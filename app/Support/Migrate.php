<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * Tiny, idempotent schema upgrade runner.
 *
 * Installs upgrade themselves on the next request; nothing has to be typed on
 * the server. Older databases get the columns and settings the streaming
 * pipeline needs without losing camera records.
 */
final class Migrate
{
    public const VERSION = 3;

    public static function run(): void
    {
        if (!Config::installed()) {
            return;
        }

        try {
            $current = (int) Db::val("SELECT `value` FROM settings WHERE `key` = 'schema_version'");

            if ($current >= self::VERSION) {
                return;
            }

            self::addColumn('cameras', 'latency_ms', 'SMALLINT UNSIGNED NOT NULL DEFAULT 300 AFTER transport');
            self::addColumn('cameras', 'frame_drop', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER latency_ms');
            self::addColumn('cameras', 'analytics_json', 'TEXT NULL AFTER frame_drop');

            // Foot-traffic samples batched from the browser every 5 seconds.
            Db::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS person_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    camera_id INT UNSIGNED NOT NULL,
                    direction ENUM('IN','OUT') NULL,
                    occupancy SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
                    occurred_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY person_events_camera_time_idx (camera_id, occurred_at),
                    CONSTRAINT person_events_camera_fk FOREIGN KEY (camera_id) REFERENCES cameras (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            Db::run(
                "INSERT INTO settings (`key`, `value`) VALUES ('schema_version', :version)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                ['version' => (string) self::VERSION]
            );

            Settings::flush();
        } catch (\Throwable $e) {
            // A read-only database or a missing table must not break the app:
            // the columns fall back to their defaults in code.
            Logger::warning('Schema migration skipped: ' . $e->getMessage());
        }
    }

    /** Add a column only when the table does not have it yet. */
    private static function addColumn(string $table, string $column, string $definition): void
    {
        $exists = Db::val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => $column]
        );

        if ((int) $exists > 0) {
            return;
        }

        Db::pdo()->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            Db::identifier($table),
            Db::identifier($column),
            $definition
        ));
    }
}
