<?php

declare(strict_types=1);

namespace Argus\Support;

/**
 * Audit trail used by the activity page and for troubleshooting.
 */
final class Activity
{
    public static function log(string $action, string $description, ?string $subjectType = null, ?int $subjectId = null): void
    {
        try {
            Db::insert('activity_log', [
                'user_id'      => Auth::id(),
                'action'       => mb_substr($action, 0, 64),
                'subject_type' => $subjectType !== null ? mb_substr($subjectType, 0, 32) : null,
                'subject_id'   => $subjectId,
                'description'  => mb_substr($description, 0, 255),
                'ip'           => Auth::clientIp(),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Logging must never break a user action.
        }
    }

    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return Db::all(
            "SELECT a.*, u.name AS user_name
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT {$limit}"
        );
    }
}
