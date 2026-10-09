<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `request_events` table (request timeline / history).
 */
final class RequestEventRepository extends Repository
{
    protected static string $table = 'request_events';

    /** @return array<int, array> */
    public static function forRequest(int $requestId): array
    {
        return Database::select(
            'SELECT re.*, u.name AS actor_name
             FROM request_events re
             LEFT JOIN users u ON u.id = re.actor_id
             WHERE re.request_id = :request_id
             ORDER BY re.created_at ASC, re.id ASC',
            ['request_id' => $requestId]
        );
    }

    /**
     * Recent operational activity across all requests (admin activity feed).
     *
     * @return array<int, array>
     */
    public static function recentActivity(int $limit = 20): array
    {
        return Database::select(
            'SELECT re.*, u.name AS actor_name, er.category AS request_category,
                    er.priority AS request_priority, er.status AS request_status
             FROM request_events re
             JOIN emergency_requests er ON er.id = re.request_id
             LEFT JOIN users u ON u.id = re.actor_id
             ORDER BY re.created_at DESC, re.id DESC
             LIMIT ' . max(1, $limit)
        );
    }

    /**
     * Append a timeline event for a request.
     */
    public static function record(
        int $requestId,
        string $eventType,
        ?int $actorId = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $note = null
    ): int {
        return self::create([
            'request_id' => $requestId,
            'actor_id'   => $actorId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'event_type' => $eventType,
            'note'       => $note,
        ]);
    }
}
