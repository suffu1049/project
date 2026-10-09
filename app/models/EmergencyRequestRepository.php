<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `emergency_requests` table.
 *
 * Status transition rules belong to EmergencyService (Phase 4); this class
 * only reads and writes rows.
 */
final class EmergencyRequestRepository extends Repository
{
    protected static string $table = 'emergency_requests';

    /** Statuses that count as "open" operational work. */
    public const ACTIVE_STATUSES = ['CREATED', 'VERIFIED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS', 'ESCALATED'];

    /** @return array<int, array> */
    public static function forUser(int $userId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM emergency_requests WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . max(1, $limit),
            ['user_id' => $userId]
        );
    }

    /** @return array<int, array> */
    public static function active(int $limit = 50): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));

        return Database::select(
            "SELECT * FROM emergency_requests WHERE status IN ({$placeholders}) ORDER BY FIELD(priority, 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW'), created_at DESC LIMIT " . max(1, $limit),
            self::ACTIVE_STATUSES
        );
    }

    /** @return array<int, array> */
    public static function recent(int $limit = 10): array
    {
        return Database::select(
            'SELECT * FROM emergency_requests ORDER BY created_at DESC LIMIT ' . max(1, $limit)
        );
    }

    public static function activeCount(): int
    {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));

        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM emergency_requests WHERE status IN ({$placeholders})",
            self::ACTIVE_STATUSES
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function criticalCount(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM emergency_requests WHERE priority = 'CRITICAL' AND status NOT IN ('RESOLVED', 'CANCELLED')"
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function resolvedCount(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM emergency_requests WHERE status = 'RESOLVED'"
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Counts grouped by status.
     *
     * @return array<string, int>
     */
    public static function countsByStatus(): array
    {
        $rows = Database::select('SELECT status, COUNT(*) AS total FROM emergency_requests GROUP BY status');

        return array_column($rows, 'total', 'status');
    }

    /**
     * Counts grouped by priority.
     *
     * @return array<string, int>
     */
    public static function countsByPriority(): array
    {
        $rows = Database::select('SELECT priority, COUNT(*) AS total FROM emergency_requests GROUP BY priority');

        return array_column($rows, 'total', 'priority');
    }

    /**
     * Counts grouped by category.
     *
     * @return array<string, int>
     */
    public static function countsByCategory(): array
    {
        $rows = Database::select('SELECT category, COUNT(*) AS total FROM emergency_requests GROUP BY category');

        return array_column($rows, 'total', 'category');
    }

    /**
     * Update only the workflow-controlled status fields.
     */
    public static function updateStatus(int $id, string $status, ?string $resolvedAt = null): int
    {
        return Database::execute(
            'UPDATE emergency_requests SET status = :status, resolved_at = :resolved_at WHERE id = :id',
            ['status' => $status, 'resolved_at' => $resolvedAt, 'id' => $id]
        );
    }

    public static function totalCount(): int
    {
        $row = Database::selectOne('SELECT COUNT(*) AS aggregate FROM emergency_requests');

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function inProgressCount(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM emergency_requests WHERE status = 'IN_PROGRESS'"
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    public static function highPriorityCount(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM emergency_requests
             WHERE priority IN ('HIGH', 'CRITICAL') AND status NOT IN ('RESOLVED', 'CANCELLED')"
        );

        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * Admin operational queue with whitelisted filters and pagination.
     *
     * Filter values are validated against their enum vocabulary by the service
     * before reaching this method; the free-text search is always bound as a
     * parameter and LIMIT/OFFSET are validated integers.
     *
     * @param array<string, mixed> $filters status, priority, category, date_from, date_to, search
     * @return array{items: array<int, array>, pagination: array{page:int,limit:int,total:int,pages:int}}
     */
    public static function adminPage(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            $where[] = 'er.status = :status';
            $bindings['status'] = (string) $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[] = 'er.priority = :priority';
            $bindings['priority'] = (string) $filters['priority'];
        }

        if (!empty($filters['category'])) {
            $where[] = 'er.category = :category';
            $bindings['category'] = (string) $filters['category'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'er.created_at >= :date_from';
            $bindings['date_from'] = (string) $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'er.created_at <= :date_to';
            $bindings['date_to'] = (string) $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $where[] = '(er.description LIKE :search_desc OR er.address_text LIKE :search_addr OR CAST(er.id AS CHAR) = :search_id)';
            $bindings['search_desc'] = '%' . (string) $filters['search'] . '%';
            $bindings['search_addr'] = '%' . (string) $filters['search'] . '%';
            $bindings['search_id'] = (string) $filters['search'];
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $totalRow = Database::selectOne(
            'SELECT COUNT(*) AS aggregate FROM emergency_requests er' . $clause,
            $bindings
        );

        $items = Database::select(
            'SELECT er.*, u.name AS requester_name, u.email AS requester_email
             FROM emergency_requests er
             JOIN users u ON u.id = er.user_id'
            . $clause .
            " ORDER BY FIELD(er.status, 'ESCALATED','IN_PROGRESS','ACCEPTED','MATCHING','VERIFIED','CREATED','RESOLVED','CANCELLED'),
                       FIELD(er.priority, 'CRITICAL','HIGH','MEDIUM','LOW'),
                       er.created_at DESC, er.id DESC
             LIMIT " . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        $total = (int) ($totalRow['aggregate'] ?? 0);

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
}
