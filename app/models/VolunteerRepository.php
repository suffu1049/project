<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `volunteer_profiles` table.
 *
 * Skill compatibility and distance scoring belong to MatchingService (Phase 7);
 * this repository fetches candidate rows and applies Phase 5 profile writes.
 */
final class VolunteerRepository extends Repository
{
    protected static string $table = 'volunteer_profiles';

    public static function findByUserId(int $userId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM volunteer_profiles WHERE user_id = :user_id LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /**
     * Profile joined with its user account (admin operational reads).
     */
    public static function findWithUser(int $profileId): ?array
    {
        return Database::selectOne(
            "SELECT vp.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, u.status AS user_status, u.role AS user_role
             FROM volunteer_profiles vp
             INNER JOIN users u ON u.id = vp.user_id
             WHERE vp.id = :id
             LIMIT 1",
            ['id' => $profileId]
        );
    }

    /**
     * Admin listing, optionally filtered by verification status.
     *
     * @return array<int, array>
     */
    public static function allWithUsers(?string $verificationStatus = null, int $limit = 200): array
    {
        if ($verificationStatus !== null && $verificationStatus !== '') {
            return Database::select(
                "SELECT vp.*, u.name AS user_name, u.email AS user_email, u.status AS user_status, u.role AS user_role
                 FROM volunteer_profiles vp
                 INNER JOIN users u ON u.id = vp.user_id
                 WHERE vp.verification_status = :status
                 ORDER BY vp.updated_at DESC
                 LIMIT " . max(1, $limit),
                ['status' => $verificationStatus]
            );
        }

        return Database::select(
            "SELECT vp.*, u.name AS user_name, u.email AS user_email, u.status AS user_status, u.role AS user_role
             FROM volunteer_profiles vp
             INNER JOIN users u ON u.id = vp.user_id
             ORDER BY vp.updated_at DESC
             LIMIT " . max(1, $limit)
        );
    }

    /**
     * Volunteers that are available, verified and have usable coordinates,
     * joined with their user account. Candidate set for the matching engine.
     *
     * @return array<int, array>
     */
    public static function availableVerified(int $limit = 200): array
    {
        return Database::select(
            "SELECT vp.*, u.name, u.email, u.phone, u.status AS user_status
             FROM volunteer_profiles vp
             INNER JOIN users u ON u.id = vp.user_id
             WHERE vp.availability = 'AVAILABLE'
               AND vp.verification_status = 'VERIFIED'
               AND vp.latitude IS NOT NULL
               AND vp.longitude IS NOT NULL
               AND u.status = 'ACTIVE'
             LIMIT " . max(1, $limit)
        );
    }

    public static function updateAvailability(int $profileId, string $availability): int
    {
        return Database::execute(
            'UPDATE volunteer_profiles SET availability = :availability WHERE id = :id',
            ['availability' => $availability, 'id' => $profileId]
        );
    }

    public static function updateVerificationStatus(int $profileId, string $status): int
    {
        return Database::execute(
            'UPDATE volunteer_profiles SET verification_status = :status WHERE id = :id',
            ['status' => $status, 'id' => $profileId]
        );
    }

    public static function countAvailableVerified(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM volunteer_profiles
             WHERE availability = 'AVAILABLE' AND verification_status = 'VERIFIED'"
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function countByVerification(string $status): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS aggregate FROM volunteer_profiles WHERE verification_status = :status',
            ['status' => $status]
        );

        return (int) ($row['aggregate'] ?? 0);
    }
}
