<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `organizations` table.
 */
final class OrganizationRepository extends Repository
{
    protected static string $table = 'organizations';

    public static function findByUserId(int $userId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM organizations WHERE user_id = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /** @return array<int, array> */
    public static function byVerificationStatus(string $status, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM organizations WHERE verification_status = :status ORDER BY name LIMIT ' . max(1, $limit),
            ['status' => $status]
        );
    }

    public static function setVerificationStatus(int $id, string $status): int
    {
        return Database::execute(
            'UPDATE organizations SET verification_status = :status WHERE id = :id',
            ['status' => $status, 'id' => $id]
        );
    }

    public static function countVerified(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM organizations WHERE verification_status = 'VERIFIED'"
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Count organizations with a verification status (admin dashboard/queue).
     */
    public static function countByVerificationStatus(string $status): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS aggregate FROM organizations WHERE verification_status = :status',
            ['status' => $status]
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Admin list joined with resource inventory counts.
     *
     * Deliberately exposes only the display name — never account email or
     * phone (DATABASE.md §29). The status filter is validated against the
     * enum vocabulary and always bound as a parameter.
     *
     * @return array<int, array>
     */
    public static function adminList(?string $verificationStatus = null, int $limit = 200): array
    {
        $sql = 'SELECT o.*,
                       u.name AS user_name,
                       COUNT(r.id) AS resource_count,
                       COALESCE(SUM(r.quantity), 0) AS total_quantity,
                       COALESCE(SUM(r.available_quantity), 0) AS available_quantity
                FROM organizations o
                JOIN users u ON u.id = o.user_id
                LEFT JOIN resources r ON r.organization_id = o.id';
        $bindings = [];

        if ($verificationStatus !== null) {
            if (!in_array($verificationStatus, ['PENDING', 'VERIFIED', 'REJECTED'], true)) {
                throw new \InvalidArgumentException('Unknown verification status filter.');
            }

            $sql .= ' WHERE o.verification_status = :status';
            $bindings['status'] = $verificationStatus;
        }

        $sql .= " GROUP BY o.id
                  ORDER BY FIELD(o.verification_status, 'PENDING', 'REJECTED', 'VERIFIED'), o.name
                  LIMIT " . max(1, $limit);

        return Database::select($sql, $bindings);
    }
}
