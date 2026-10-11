<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query goes through prepared statements;
 * there is deliberately no method that interpolates values into SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;
    /** Queries run and their total time this request (shown as Server-Timing when APP_DEBUG is on). */
    public static int $queries = 0;
    public static float $queryTime = 0.0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::env('DB_HOST', 'localhost'),
                (int) Config::env('DB_PORT', 3306),
                Config::env('DB_NAME', '')
            );
            self::$pdo = new PDO($dsn, (string) Config::env('DB_USER'), (string) Config::env('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Store and compare everything in UTC regardless of server timezone.
            self::$pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    /** @param array<int|string, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $t = hrtime(true);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        self::$queries++;
        self::$queryTime += (hrtime(true) - $t) / 1e6;
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run $fn inside a transaction; rolls back and rethrows on any error.
     * A call made while a transaction is already open joins it (services
     * that use transactions can be called from inside another one).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
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
