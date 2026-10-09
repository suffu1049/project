<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;

/**
 * Persistence for the `audit_logs` table.
 *
 * Audit metadata must never contain secrets; callers pass only safe context
 * (entity ids, statuses, reasons).
 */
final class AuditRepository extends Repository
{
    protected static string $table = 'audit_logs';

    /**
     * Record an auditable action.
     *
     * @param array<string, mixed> $metadata
     */
    public static function record(
        ?int $actorId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        return self::create([
            'actor_id'    => $actorId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'metadata'    => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent !== null ? substr($userAgent, 0, 500) : null,
        ]);
    }

    /** @return array<int, array> */
    public static function recent(int $limit = 50): array
    {
        return Database::select(
            'SELECT al.*, u.name AS actor_name
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.actor_id
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT ' . max(1, $limit)
        );
    }

    /**
     * Paginated audit trail for the admin control center (API.md §59).
     *
     * Filter values are validated/bound by the service before reaching here;
     * LIMIT/OFFSET are validated integers.
     *
     * @param array<string, mixed> $filters action, entity_type
     * @return array{items: array<int, array>, pagination: array{page:int,limit:int,total:int,pages:int}}
     */
    public static function adminPage(int $page = 1, int $perPage = 30, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $bindings['action'] = (string) $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $bindings['entity_type'] = (string) $filters['entity_type'];
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $totalRow = Database::selectOne(
            'SELECT COUNT(*) AS aggregate FROM audit_logs al' . $clause,
            $bindings
        );

        $items = Database::select(
            'SELECT al.*, u.name AS actor_name, u.role AS actor_role
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.actor_id'
            . $clause .
            ' ORDER BY al.created_at DESC, al.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
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

    /** @return array<int, array> */
    public static function forEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM audit_logs WHERE entity_type = :entity_type AND entity_id = :entity_id ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }
}
