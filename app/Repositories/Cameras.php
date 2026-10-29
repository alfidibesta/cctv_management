<?php

declare(strict_types=1);

namespace Argus\Repositories;

use Argus\Support\Auth;
use Argus\Support\Db;

/**
 * Camera records. Credentials live in password_enc and are decrypted on use.
 */
final class Cameras
{
    public const STREAM_MODES = ['mjpeg', 'snapshot', 'hls'];

    public const COLUMNS = 'c.id, c.group_id, c.name, c.location, c.driver, c.host, c.port, c.rtsp_port,
        c.username, c.password_enc, c.channel, c.stream_mode, c.snapshot_path, c.mjpeg_path, c.rtsp_path,
        c.ptz_path, c.ptz_enabled, c.transport, c.is_enabled, c.sort_order, c.status, c.last_seen_at,
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
            $where[] = '(c.name LIKE :search OR c.location LIKE :search OR c.host LIKE :search)';
            $params['search'] = '%' . $search . '%';
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

        $groupIds = array_map(static fn (array $r): int => (int) $r['group_id'], Db::all(
            'SELECT group_id FROM user_groups WHERE user_id = :id',
            ['id' => (int) $user['id']]
        ));

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

        return (bool) Db::val(
            'SELECT 1 FROM user_groups WHERE user_id = :uid AND group_id = :gid',
            ['uid' => (int) $user['id'], 'gid' => (int) $camera['group_id']]
        );
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
    }

    public static function counts(): array
    {
        return [
            'total'   => (int) Db::val('SELECT COUNT(*) FROM cameras'),
            'online'  => (int) Db::val("SELECT COUNT(*) FROM cameras WHERE status = 'online'"),
            'offline' => (int) Db::val("SELECT COUNT(*) FROM cameras WHERE status = 'offline'"),
            'enabled' => (int) Db::val('SELECT COUNT(*) FROM cameras WHERE is_enabled = 1'),
        ];
    }

    public static function nextSortOrder(): int
    {
        return ((int) Db::val('SELECT MAX(sort_order) FROM cameras')) + 10;
    }
}
