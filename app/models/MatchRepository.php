<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `matches` table.
 *
 * A match must reference a volunteer or a resource (DATABASE.md §11). Accept /
 * reject transitions and duplicate prevention live in MatchingService (Phase 7).
 */
final class MatchRepository extends Repository
{
    protected static string $table = 'matches';

    /** Statuses that keep a request actively assigned / suggested. */
    public const ACTIVE_STATUSES = ['SUGGESTED', 'NOTIFIED', 'ACCEPTED'];

    /** Statuses that block a new acceptance for the same request. */
    public const BLOCKING_STATUSES = ['SUGGESTED', 'NOTIFIED', 'ACCEPTED'];

    /** @return array<int, array> */
    public static function forRequest(int $requestId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM matches WHERE request_id = :request_id ORDER BY match_score DESC, distance_km ASC, id ASC LIMIT ' . max(1, $limit),
            ['request_id' => $requestId]
        );
    }

    /**
     * An accepted match for a request, if any.
     */
    public static function acceptedForRequest(int $requestId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM matches WHERE request_id = :request_id AND status = 'ACCEPTED' LIMIT 1",
            ['request_id' => $requestId]
        );
    }

    /**
     * Count matches that are still active (suggested / notified / accepted).
     */
    public static function activeCount(): int
    {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM matches WHERE status IN ({$placeholders})",
            self::ACTIVE_STATUSES
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function hasActiveMatch(int $requestId): bool
    {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM matches WHERE request_id = ? AND status IN ({$placeholders})",
            array_merge([$requestId], self::ACTIVE_STATUSES)
        );

        return (int) ($row['aggregate'] ?? 0) > 0;
    }

    /**
     * Volunteer already accepted on another open request (double-assignment guard).
     */
    public static function volunteerHasOtherAcceptance(int $volunteerId, int $excludeMatchId = 0): bool
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate
             FROM matches m
             INNER JOIN emergency_requests er ON er.id = m.request_id
             WHERE m.volunteer_id = :volunteer_id
               AND m.status = 'ACCEPTED'
               AND m.id <> :exclude_id
               AND er.status IN ('CREATED','VERIFIED','MATCHING','ACCEPTED','IN_PROGRESS','ESCALATED')",
            ['volunteer_id' => $volunteerId, 'exclude_id' => $excludeMatchId]
        );

        return (int) ($row['aggregate'] ?? 0) > 0;
    }

    /**
     * Open (non-terminal) match for the same volunteer on the same request.
     */
    public static function openVolunteerMatch(int $requestId, int $volunteerId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM matches
             WHERE request_id = :request_id AND volunteer_id = :volunteer_id
               AND status IN ('SUGGESTED','NOTIFIED','ACCEPTED')
             ORDER BY id DESC LIMIT 1",
            ['request_id' => $requestId, 'volunteer_id' => $volunteerId]
        );
    }

    /**
     * Open (non-terminal) match for the same resource on the same request.
     */
    public static function openResourceMatch(int $requestId, int $resourceId): ?array
    {
        return Database::selectOne(
            "SELECT * FROM matches
             WHERE request_id = :request_id AND resource_id = :resource_id
               AND status IN ('SUGGESTED','NOTIFIED','ACCEPTED')
             ORDER BY id DESC LIMIT 1",
            ['request_id' => $requestId, 'resource_id' => $resourceId]
        );
    }

    /**
     * Matches visible to a volunteer profile.
     *
     * @return array<int, array>
     */
    public static function forVolunteer(int $volunteerId, ?string $status = null, int $limit = 50): array
    {
        if ($status !== null && $status !== '') {
            return Database::select(
                'SELECT m.* FROM matches m WHERE m.volunteer_id = :volunteer_id AND m.status = :status
                 ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . max(1, $limit),
                ['volunteer_id' => $volunteerId, 'status' => $status]
            );
        }

        return Database::select(
            'SELECT m.* FROM matches m WHERE m.volunteer_id = :volunteer_id
             ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . max(1, $limit),
            ['volunteer_id' => $volunteerId]
        );
    }

    /**
     * Matches whose resource belongs to an organization.
     *
     * @return array<int, array>
     */
    public static function forOrganizationResources(int $organizationId, ?string $status = null, int $limit = 50): array
    {
        if ($status !== null && $status !== '') {
            return Database::select(
                'SELECT m.* FROM matches m
                 INNER JOIN resources r ON r.id = m.resource_id
                 WHERE r.organization_id = :organization_id AND m.status = :status
                 ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . max(1, $limit),
                ['organization_id' => $organizationId, 'status' => $status]
            );
        }

        return Database::select(
            'SELECT m.* FROM matches m
             INNER JOIN resources r ON r.id = m.resource_id
             WHERE r.organization_id = :organization_id
             ORDER BY m.updated_at DESC, m.id DESC LIMIT ' . max(1, $limit),
            ['organization_id' => $organizationId]
        );
    }

    /**
     * Create a match, enforcing that at least one target is set.
     *
     * @param array<string, mixed> $attributes
     */
    public static function createMatch(array $attributes): int
    {
        $hasVolunteer = !empty($attributes['volunteer_id']);
        $hasResource = !empty($attributes['resource_id']);

        if (!$hasVolunteer && !$hasResource) {
            throw new \InvalidArgumentException('A match must reference a volunteer or a resource.');
        }

        return self::create($attributes);
    }

    public static function updateStatus(int $id, string $status): int
    {
        return self::update($id, ['status' => $status]);
    }

    /**
     * Persist scoring fields on an existing match row.
     *
     * @param array<string, mixed> $attributes
     */
    public static function updateScores(int $id, array $attributes): int
    {
        return self::update($id, $attributes);
    }
}
