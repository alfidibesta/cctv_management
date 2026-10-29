<?php

declare(strict_types=1);

namespace Argus\Repositories;

use Argus\Support\Db;

final class Users
{
    public const ROLES = ['admin', 'operator', 'viewer'];

    public static function all(): array
    {
        $users = Db::all(
            'SELECT id, name, email, role, is_active, last_login_at, created_at
             FROM users ORDER BY name'
        );

        foreach ($users as &$user) {
            $user['groups'] = self::groupIds((int) $user['id']);
        }

        return $users;
    }

    public static function find(int $id): ?array
    {
        $user = Db::one(
            'SELECT id, name, email, role, is_active, last_login_at, created_at FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        if ($user) {
            $user['groups'] = self::groupIds($id);
        }

        return $user;
    }

    public static function findByEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => mb_strtolower(trim($email))]);
    }

    public static function passwordHash(int $userId): string
    {
        return (string) Db::val('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
    }

    public static function groupIds(int $userId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['group_id'],
            Db::all('SELECT group_id FROM user_groups WHERE user_id = :id', ['id' => $userId])
        );
    }

    public static function create(array $data, array $groupIds = []): int
    {
        $id = Db::insert('users', [
            'name'          => $data['name'],
            'email'         => mb_strtolower($data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role'          => $data['role'],
            'is_active'     => $data['is_active'] ?? 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ]);

        self::syncGroups($id, $groupIds);

        return $id;
    }

    public static function update(int $id, array $data, array $groupIds = []): void
    {
        $update = [
            'name'       => $data['name'],
            'email'      => mb_strtolower($data['email']),
            'role'       => $data['role'],
            'is_active'  => $data['is_active'] ?? 1,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if (!empty($data['password'])) {
            $update['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        Db::update('users', $update, 'id = :id', ['id' => $id]);
        self::syncGroups($id, $groupIds);
    }

    public static function delete(int $id): void
    {
        Db::delete('users', 'id = :id', ['id' => $id]);
    }

    public static function syncGroups(int $userId, array $groupIds): void
    {
        Db::delete('user_groups', 'user_id = :id', ['id' => $userId]);

        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            if ($groupId > 0) {
                Db::insert('user_groups', ['user_id' => $userId, 'group_id' => $groupId]);
            }
        }
    }

    public static function countAdmins(): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
    }

    public static function emailTaken(string $email, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        $params = ['email' => mb_strtolower(trim($email))];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        return (bool) Db::val($sql . ' LIMIT 1', $params);
    }
}
