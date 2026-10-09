<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `users` table.
 *
 * Password hashing and verification live in AuthService (Phase 3); this
 * repository only stores and retrieves records.
 */
final class UserRepository extends Repository
{
    protected static string $table = 'users';

    public static function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower(trim($email))]
        );
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS aggregate FROM users WHERE email = :email';
        $bindings = ['email' => strtolower(trim($email))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $bindings['id'] = $exceptId;
        }

        $row = Database::selectOne($sql, $bindings);

        return (int) ($row['aggregate'] ?? 0) > 0;
    }

    public static function countByRole(string $role): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS aggregate FROM users WHERE role = :role',
            ['role' => $role]
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * User ids for a role (optionally filtered by account status).
     *
     * @return array<int, int>
     */
    public static function idsByRole(string $role, ?string $status = 'ACTIVE'): array
    {
        $sql = 'SELECT id FROM users WHERE role = :role';
        $bindings = ['role' => $role];

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $bindings['status'] = $status;
        }

        $rows = Database::select($sql, $bindings);

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public static function countActive(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM users WHERE status = 'ACTIVE'"
        );

        return (int) ($row['aggregate'] ?? 0);
    }
}
