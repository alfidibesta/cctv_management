<?php

declare(strict_types=1);

namespace Argus\Repositories;

use Argus\Support\Db;

/**
 * One live-wall layout per user, capped at 16 tiles per page.
 */
final class Layouts
{
    public const MAX_TILES = 16;
    public const COLUMNS = [1, 4, 9, 16];

    public static function forUser(int $userId): ?array
    {
        $row = Db::one('SELECT * FROM layouts WHERE user_id = :id LIMIT 1', ['id' => $userId]);

        return $row ? self::hydrate($row) : null;
    }

    public static function save(int $userId, int $columns, array $tiles, string $mode = 'custom'): array
    {
        $columns = in_array($columns, self::COLUMNS, true) ? $columns : 9;
        $mode = in_array($mode, ['custom', 'auto'], true) ? $mode : 'custom';

        // Tiles are camera ids (int) or null for an empty slot; always length == columns.
        $normalized = [];
        for ($i = 0; $i < $columns; $i++) {
            $value = $tiles[$i] ?? null;
            $normalized[] = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        $payload = json_encode($normalized, JSON_THROW_ON_ERROR);

        $existing = Db::one('SELECT id FROM layouts WHERE user_id = :id LIMIT 1', ['id' => $userId]);

        if ($existing) {
            Db::update('layouts', [
                'columns'    => $columns,
                'tiles'      => $payload,
                'mode'       => $mode,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            Db::insert('layouts', [
                'user_id'    => $userId,
                'columns'    => $columns,
                'tiles'      => $payload,
                'mode'       => $mode,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        return ['columns' => $columns, 'tiles' => $normalized, 'mode' => $mode];
    }

    public static function deleteForUser(int $userId): void
    {
        Db::delete('layouts', 'user_id = :id', ['id' => $userId]);
    }

    private static function hydrate(array $row): array
    {
        $tiles = json_decode((string) $row['tiles'], true);

        return [
            'columns' => in_array((int) $row['columns'], self::COLUMNS, true) ? (int) $row['columns'] : 9,
            'tiles'   => is_array($tiles) ? array_slice($tiles, 0, self::MAX_TILES) : [],
            'mode'    => in_array($row['mode'] ?? 'custom', ['custom', 'auto'], true) ? $row['mode'] : 'custom',
            'updated_at' => $row['updated_at'],
        ];
    }
}
