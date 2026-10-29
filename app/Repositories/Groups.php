<?php

declare(strict_types=1);

namespace Argus\Repositories;

use Argus\Support\Db;

final class Groups
{
    public static function all(): array
    {
        return Db::all(
            'SELECT g.*, (SELECT COUNT(*) FROM cameras c WHERE c.group_id = g.id) AS camera_count
             FROM camera_groups g
             ORDER BY g.sort_order, g.name'
        );
    }

    public static function options(): array
    {
        return Db::all('SELECT id, name FROM camera_groups ORDER BY sort_order, name');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM camera_groups WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public static function create(string $name, string $description = ''): int
    {
        $order = ((int) Db::val('SELECT MAX(sort_order) FROM camera_groups')) + 10;

        return Db::insert('camera_groups', [
            'name'        => $name,
            'description' => $description,
            'sort_order'  => $order,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $id, string $name, string $description = ''): void
    {
        Db::update('camera_groups', ['name' => $name, 'description' => $description], 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::delete('camera_groups', 'id = :id', ['id' => $id]);
    }

    public static function exists(string $name, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM camera_groups WHERE name = :name';
        $params = ['name' => $name];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        return (bool) Db::val($sql . ' LIMIT 1', $params);
    }
}
