<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base model.
 *
 * Models contain persistence logic only — no business rules, no HTML.
 * Concrete models (User, EmergencyRequest, ...) are added in Phase 2 and
 * build on these prepared-statement helpers.
 */
abstract class Model
{
    /** Table name for the model. */
    protected static string $table = '';

    /** Primary key column. */
    protected static string $primaryKey = 'id';

    public static function table(): string
    {
        return static::$table;
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * First record ordered by primary key (useful for defaults/summaries).
     */
    public static function first(): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` ORDER BY `%s` ASC LIMIT 1', static::$table, static::$primaryKey)
        );
    }

    /**
     * Find a record by primary key.
     */
    public static function find(int|string $id): ?array
    {
        return Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', static::$table, static::$primaryKey),
            [$id]
        );
    }

    /**
     * Find all records, newest first when a created_at column exists.
     */
    public static function all(int $limit = 100, int $offset = 0): array
    {
        return Database::select(
            sprintf('SELECT * FROM `%s` ORDER BY `%s` DESC LIMIT %d OFFSET %d', static::$table, static::$primaryKey, $limit, $offset)
        );
    }

    /**
     * Fetch rows matching a simple equality condition (column => value).
     */
    public static function where(array $conditions, int $limit = 100): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $clauses[] = sprintf('`%s` = ?', $column);
            $bindings[] = $value;
        }

        $sql = sprintf('SELECT * FROM `%s`', static::$table);
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= ' LIMIT ' . $limit;

        return Database::select($sql, $bindings);
    }

    public static function count(): int
    {
        $row = Database::selectOne(sprintf('SELECT COUNT(*) AS aggregate FROM `%s`', static::$table));

        return (int) ($row['aggregate'] ?? 0);
    }
}
