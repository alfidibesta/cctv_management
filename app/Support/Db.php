<?php

declare(strict_types=1);

namespace Argus\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query in the app goes through prepared statements.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = Config::get('db');
        if (!is_array($config) || empty($config['database'])) {
            throw new \RuntimeException('Database is not configured. Run the installer.');
        }

        self::$pdo = self::connect($config);

        return self::$pdo;
    }

    /** Open a connection for arbitrary credentials (used by the installer). */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );

        return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]);
    }

    /** Test credentials without keeping the connection. */
    public static function test(array $config): array
    {
        try {
            $pdo = self::connect($config);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $pdo = null;

            return ['ok' => true, 'version' => $version];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            self::identifier($table),
            implode('`, `', array_map([self::class, 'identifier'], $columns)),
            implode(', ', $placeholders)
        );

        self::run($sql, $data);

        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = sprintf('`%s` = :%s', self::identifier($column), $column);
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', self::identifier($table), implode(', ', $sets), $where);

        return self::run($sql, $data + $whereParams)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', self::identifier($table), $where);

        return self::run($sql, $params)->rowCount();
    }

    /** Quote and validate a table/column name (only identifiers we control). */
    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }

        return $name;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
