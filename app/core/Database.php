<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Central PDO database access layer.
 *
 * Responsibilities:
 *  - Lazy connection (the app boots and serves pages even when MySQL is down).
 *  - Prepared statements only — no string interpolation of SQL values.
 *  - Transaction helpers for multi-step state changes.
 *
 * Connection failures raise a DatabaseException; callers decide whether to
 * degrade gracefully (see ARCHITECTURE: external dependency fallback).
 */
final class Database
{
    private static ?PDO $pdo = null;

    private static bool $connected = false;

    private function __construct()
    {
    }

    /**
     * Return the shared PDO connection, creating it on first use.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = Config::get('database', []);
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? '3306',
            $config['database'] ?? 'resqlink',
            $config['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? 'root'),
                (string) ($config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );

            self::$connected = true;
            Logger::info('Database connection established.');

            return self::$pdo;
        } catch (PDOException $exception) {
            self::$connected = false;
            Logger::error('Database connection failed.', ['error' => $exception->getMessage()]);

            throw new DatabaseException('Unable to connect to the database.', 0, $exception);
        }
    }

    /**
     * Whether a live connection currently exists without forcing a new one.
     */
    public static function isConnected(): bool
    {
        return self::$connected && self::$pdo instanceof PDO;
    }

    /**
     * Cheap connectivity probe used by health checks and graceful UI states.
     */
    public static function ping(): bool
    {
        try {
            self::pdo()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run a prepared statement and return the statement.
     */
    public static function run(string $sql, array $bindings = []): \PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /**
     * Fetch all rows.
     */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    /**
     * Fetch a single row or null.
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Execute a write statement and return the affected row count.
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }

    /**
     * Execute a callback inside a transaction, rolling back on any failure.
     *
     * Nested calls join the outer transaction instead of opening a second one
     * (PDO does not support nested begin/commit). This lets a service compose
     * a multi-step unit of work — e.g. resource allocation + request event +
     * audit log (Phase 6) — out of repository helpers that each open their own
     * transaction: the outermost call owns commit/rollback, and an inner
     * failure propagates so everything rolls back together.
     *
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();

        // Already inside a transaction: participate in it, do not nest.
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
