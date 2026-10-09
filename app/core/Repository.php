<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Base repository.
 *
 * Repositories contain persistence logic only — no business rules, no HTTP,
 * no HTML. All values are passed through prepared statements; only validated
 * identifiers (column names) are interpolated, and always backtick-quoted.
 */
abstract class Repository extends Model
{
    /**
     * Insert a row and return the new primary key.
     *
     * @param array<string, mixed> $attributes column => value
     */
    public static function create(array $attributes): int
    {
        if ($attributes === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = array_map([self::class, 'quoteIdentifier'], array_keys($attributes));
        $placeholders = array_map(static fn (string $column): string => ':' . $column, array_keys($attributes));

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            static::$table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        Database::run($sql, self::bindings($attributes));

        return (int) Database::lastInsertId();
    }

    /**
     * Update a row by primary key. Returns affected rows.
     *
     * @param array<string, mixed> $attributes column => value
     */
    public static function update(int|string $id, array $attributes): int
    {
        if ($attributes === []) {
            return 0;
        }

        $assignments = array_map(
            static fn (string $column): string => sprintf('`%s` = :%s', $column, $column),
            array_keys($attributes)
        );

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :__id',
            static::$table,
            implode(', ', $assignments),
            static::$primaryKey
        );

        return Database::execute($sql, self::bindings($attributes) + ['__id' => $id]);
    }

    /**
     * Delete a row by primary key. Returns affected rows.
     */
    public static function delete(int|string $id): int
    {
        return Database::execute(
            sprintf('DELETE FROM `%s` WHERE `%s` = ?', static::$table, static::$primaryKey),
            [$id]
        );
    }

    /**
     * Whether at least one row matches the given equality conditions.
     *
     * @param array<string, mixed> $conditions
     */
    public static function exists(array $conditions): bool
    {
        return self::countWhere($conditions) > 0;
    }

    /**
     * Count rows matching equality conditions.
     *
     * @param array<string, mixed> $conditions
     */
    public static function countWhere(array $conditions = []): int
    {
        [$where, $bindings] = self::buildWhere($conditions);
        $row = Database::selectOne(
            sprintf('SELECT COUNT(*) AS aggregate FROM `%s`%s', static::$table, $where),
            $bindings
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Paginated fetch with equality filters and a whitelisted order column.
     *
     * @param array<string, mixed> $conditions
     * @return array{items: array<int, array>, pagination: array{page:int,limit:int,total:int,pages:int}}
     */
    public static function paginate(int $page = 1, int $perPage = 20, array $conditions = [], string $orderBy = 'id', string $direction = 'DESC'): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $orderBy = self::quoteIdentifier($orderBy);
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $bindings] = self::buildWhere($conditions);

        $total = self::countWhere($conditions);

        // LIMIT/OFFSET are validated integers, safe to inline.
        $items = Database::select(
            sprintf(
                'SELECT * FROM `%s`%s ORDER BY %s %s LIMIT %d OFFSET %d',
                static::$table,
                $where,
                $orderBy,
                $direction,
                $perPage,
                $offset
            ),
            $bindings
        );

        return [
            'items'      => $items,
            'pagination' => [
                'page'  => $page,
                'limit' => $perPage,
                'total' => $total,
                'pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Run a callback inside a database transaction.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    protected static function transaction(callable $callback): mixed
    {
        return Database::transaction(static fn () => $callback());
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($conditions as $column => $value) {
            $key = 'w_' . $column;
            $clauses[] = sprintf('`%s` = :%s', $column, $key);
            $bindings[$key] = $value;
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * Prefix named bindings to avoid collisions (PDO requires the leading colon
     * to be omitted in the array keys).
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private static function bindings(array $attributes): array
    {
        $bindings = [];

        foreach ($attributes as $column => $value) {
            $bindings[$column] = $value;
        }

        return $bindings;
    }

    /**
     * Reject anything that is not a plain identifier before interpolation.
     */
    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
