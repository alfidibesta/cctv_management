<?php

declare(strict_types=1);

namespace Argus\Repositories;

use Argus\Support\Activity;
use Argus\Support\Auth;
use Argus\Support\Db;

/**
 * Camera records. Credentials live in password_enc and are decrypted on use.
 */
final class Cameras
{
    public const STREAM_MODES = ['mjpeg', 'snapshot', 'hls'];

    /** Group membership cache: one query per user and request instead of per camera. */
    private static array $groupCache = [];

    public const COLUMNS = 'c.id, c.group_id, c.name, c.location, c.driver, c.host, c.port, c.rtsp_port,
        c.username, c.password_enc, c.channel, c.stream_mode, c.snapshot_path, c.mjpeg_path, c.rtsp_path,
        c.transport, c.latency_ms, c.frame_drop, c.analytics_json, c.is_enabled, c.sort_order, c.status, c.last_seen_at,
        c.last_latency_ms, c.last_error, c.created_at, c.updated_at';

    public static function all(?int $groupId = null, string $search = ''): array
    {
        $where = [];
        $params = [];

        if ($groupId !== null && $groupId > 0) {
            $where[] = 'c.group_id = :group_id';
            $params['group_id'] = $groupId;
        }

        if ($search !== '') {
            // Distinct placeholders: native prepares reject a reused named parameter.
            $where[] = '(c.name LIKE :search_name OR c.location LIKE :search_location OR c.host LIKE :search_host)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_location'] = '%' . $search . '%';
            $params['search_host'] = '%' . $search . '%';
        }

        $sql = 'SELECT ' . self::COLUMNS . ', g.name AS group_name
                FROM cameras c
                LEFT JOIN camera_groups g ON g.id = c.group_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY g.sort_order IS NULL, g.sort_order, c.sort_order, c.name';

        return Db::all($sql, $params);
    }

    /** Cameras the signed-in user may view. Viewers are limited to their groups. */
    public static function visibleForUser(?array $user): array
    {
        if (!$user) {
            return [];
        }

        if (in_array($user['role'], ['admin', 'operator'], true)) {
            return self::all();
        }

        $groupIds = self::groupIdsFor((int) $user['id']);

        if (!$groupIds) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($groupIds as $i => $gid) {
            $placeholders[] = ':g' . $i;
            $params['g' . $i] = $gid;
        }

        return Db::all(
            'SELECT ' . self::COLUMNS . ', g.name AS group_name
             FROM cameras c
             LEFT JOIN camera_groups g ON g.id = c.group_id
             WHERE c.group_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY g.sort_order IS NULL, g.sort_order, c.sort_order, c.name',
            $params
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT ' . self::COLUMNS . ', g.name AS group_name
             FROM cameras c LEFT JOIN camera_groups g ON g.id = c.group_id
             WHERE c.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = $id;
        }

        $rows = Db::all(
            'SELECT ' . self::COLUMNS . ', g.name AS group_name
             FROM cameras c LEFT JOIN camera_groups g ON g.id = c.group_id
             WHERE c.id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        return $indexed;
    }

    public static function canView(?array $user, array $camera): bool
    {
        if (!$user) {
            return false;
        }

        if (in_array($user['role'], ['admin', 'operator'], true)) {
            return true;
        }

        if ($camera['group_id'] === null) {
            return false;
        }

        return in_array((int) $camera['group_id'], self::groupIdsFor((int) $user['id']), true);
    }

    /** @return array<int, int> */
    private static function groupIdsFor(int $userId): array
    {
        if (!isset(self::$groupCache[$userId])) {
            $rows = Db::all('SELECT group_id FROM user_groups WHERE user_id = :id', ['id' => $userId]);
            self::$groupCache[$userId] = array_map(static fn (array $row): int => (int) $row['group_id'], $rows);
        }

        return self::$groupCache[$userId];
    }

    /** Record an online/offline transition in the activity feed. */
    public static function noteStatusChange(array $camera, string $status, ?string $error = null): void
    {
        $previous = (string) ($camera['status'] ?? 'unknown');
        if ($previous === $status) {
            return;
        }

        $description = $camera['name'] . ' is ' . $status . ($error !== null && $error !== '' ? ' — ' . $error : '');
        Activity::log('camera.' . $status, $description, 'camera', isset($camera['id']) ? (int) $camera['id'] : null);
    }

    public static function create(array $data): int
    {
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = gmdate('Y-m-d H:i:s');

        return Db::insert('cameras', $data);
    }

    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('cameras', $data, 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::delete('cameras', 'id = :id', ['id' => $id]);
    }

    public static function recordStatus(int $id, string $status, ?int $latency = null, ?string $error = null): void
    {
        $data = [
            'status'        => $status,
            'last_error'    => $error !== null ? mb_substr($error, 0, 255) : null,
            'last_latency_ms' => $latency,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ];

        if ($status === 'online') {
            $data['last_seen_at'] = gmdate('Y-m-d H:i:s');
        }

        Db::update('cameras', $data, 'id = :id', ['id' => $id]);

        // Sampled history for the Health page (never throws, throttled internally).
        \Argus\Services\Health::record($id, $status, $latency, $error);
    }

    public static function counts(): array
    {
        // One round-trip instead of four COUNT queries.
        $row = Db::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'online'), 0) AS online,
                    COALESCE(SUM(status = 'offline'), 0) AS offline,
                    COALESCE(SUM(is_enabled = 1), 0) AS enabled
             FROM cameras"
        );

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'online'  => (int) ($row['online'] ?? 0),
            'offline' => (int) ($row['offline'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0),
        ];
    }

    public static function nextSortOrder(): int
    {
        return ((int) Db::val('SELECT MAX(sort_order) FROM cameras')) + 10;
    }
}
