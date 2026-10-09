<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Repository;
use RuntimeException;

/**
 * Persistence for the `resources` table.
 *
 * Allocation is transactional: the availability guard, the decrement and the
 * status recalculation all happen together, and available_quantity can never
 * go below zero (DATABASE.md §26).
 */
final class ResourceRepository extends Repository
{
    protected static string $table = 'resources';

    public const STATUS_AVAILABLE = 'AVAILABLE';
    public const STATUS_LIMITED = 'LIMITED';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Ratio below which a resource is considered "limited".
     */
    private const LIMITED_THRESHOLD = 0.2;

    /** @return array<int, array> */
    public static function forOrganization(int $organizationId, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM resources WHERE organization_id = :organization_id ORDER BY resource_type LIMIT ' . max(1, $limit),
            ['organization_id' => $organizationId]
        );
    }

    /** @return array<int, array> */
    public static function available(int $limit = 100): array
    {
        return Database::select(
            "SELECT * FROM resources WHERE status <> 'UNAVAILABLE' AND available_quantity > 0 ORDER BY resource_type LIMIT " . max(1, $limit)
        );
    }

    /**
     * Derive status from available/total quantities.
     */
    public static function statusForQuantity(int $available, int $quantity): string
    {
        if ($available <= 0) {
            return self::STATUS_UNAVAILABLE;
        }

        if ($quantity > 0 && ($available / $quantity) <= self::LIMITED_THRESHOLD) {
            return self::STATUS_LIMITED;
        }

        return self::STATUS_AVAILABLE;
    }

    /**
     * Recalculate and persist the status for a resource.
     */
    public static function refreshStatus(int $resourceId): void
    {
        $resource = self::find($resourceId);

        if ($resource === null) {
            return;
        }

        $status = self::statusForQuantity((int) $resource['available_quantity'], (int) $resource['quantity']);

        if ($status !== $resource['status']) {
            self::update($resourceId, ['status' => $status]);
        }
    }

    /**
     * Atomically allocate `quantity` units of a resource.
     *
     * Locks the row, verifies availability, decrements it, refreshes status and
     * commits. Throws when the requested amount is not available.
     */
    public static function allocate(int $resourceId, int $quantity): array
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Allocation quantity must be greater than zero.');
        }

        return self::transaction(static function () use ($resourceId, $quantity): array {
            $resource = Database::selectOne(
                'SELECT * FROM resources WHERE id = :id FOR UPDATE',
                ['id' => $resourceId]
            );

            if ($resource === null) {
                throw new RuntimeException('Resource not found.');
            }

            $available = (int) $resource['available_quantity'];

            if ($available < $quantity) {
                throw new RuntimeException('Insufficient resource quantity.');
            }

            $newAvailable = $available - $quantity;

            Database::execute(
                'UPDATE resources SET available_quantity = :available, status = :status WHERE id = :id',
                [
                    'available' => $newAvailable,
                    'status'    => self::statusForQuantity($newAvailable, (int) $resource['quantity']),
                    'id'        => $resourceId,
                ]
            );

            return [
                'resource_id'      => $resourceId,
                'allocated'        => $quantity,
                'available_before' => $available,
                'available_after'  => $newAvailable,
            ];
        });
    }

    /**
     * Paginated, visibility-scoped listing with whitelisted filters.
     *
     * Always joins organizations so rows carry `organization_name` and the
     * verification gate (`verifiedOnly`) can be applied in SQL. Filter values
     * are validated against their enum vocabulary by the service before they
     * reach this method, and LIMIT/OFFSET are validated integers.
     *
     * @return array{items: array<int, array>, pagination: array{page:int,limit:int,total:int,pages:int}}
     */
    public static function pageVisible(
        int $page = 1,
        int $perPage = 20,
        ?int $organizationId = null,
        ?string $resourceType = null,
        ?string $status = null,
        bool $verifiedOnly = false,
        bool $excludeUnavailable = false
    ): array {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];

        if ($organizationId !== null) {
            $where[] = 'r.organization_id = :organization_id';
            $bindings['organization_id'] = $organizationId;
        }

        if ($resourceType !== null) {
            $where[] = 'r.resource_type = :resource_type';
            $bindings['resource_type'] = $resourceType;
        }

        if ($status !== null) {
            $where[] = 'r.status = :status';
            $bindings['status'] = $status;
        }

        if ($verifiedOnly) {
            $where[] = "o.verification_status = 'VERIFIED'";
        }

        if ($excludeUnavailable) {
            $where[] = "r.status <> 'UNAVAILABLE'";
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $totalRow = Database::selectOne(
            'SELECT COUNT(*) AS aggregate
             FROM resources r
             JOIN organizations o ON o.id = r.organization_id' . $clause,
            $bindings
        );

        $items = Database::select(
            'SELECT r.*, o.name AS organization_name, o.verification_status AS organization_verification_status
             FROM resources r
             JOIN organizations o ON o.id = r.organization_id'
            . $clause .
            ' ORDER BY r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
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

    /**
     * Aggregate inventory stats for the organization dashboard.
     *
     * @return array{total_resources:int, total_quantity:int, available_quantity:int, available:int, limited:int, unavailable:int}
     */
    public static function statsForOrganization(int $organizationId): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS total_resources,
                    COALESCE(SUM(quantity), 0) AS total_quantity,
                    COALESCE(SUM(available_quantity), 0) AS available_quantity,
                    COALESCE(SUM(status = 'AVAILABLE'), 0) AS available,
                    COALESCE(SUM(status = 'LIMITED'), 0) AS limited,
                    COALESCE(SUM(status = 'UNAVAILABLE'), 0) AS unavailable
             FROM resources
             WHERE organization_id = :organization_id",
            ['organization_id' => $organizationId]
        );

        return [
            'total_resources'   => (int) ($row['total_resources'] ?? 0),
            'total_quantity'    => (int) ($row['total_quantity'] ?? 0),
            'available_quantity'=> (int) ($row['available_quantity'] ?? 0),
            'available'         => (int) ($row['available'] ?? 0),
            'limited'           => (int) ($row['limited'] ?? 0),
            'unavailable'       => (int) ($row['unavailable'] ?? 0),
        ];
    }

    /**
     * Atomically add `units` to both quantity and available_quantity.
     *
     * Locks the row for the duration of the update so concurrent replenishes
     * cannot lose an increment, and refreshes the derived status. Throws when
     * the resource does not exist.
     *
     * @return array<string, mixed> the updated resource row
     */
    public static function replenish(int $resourceId, int $units): array
    {
        if ($units <= 0) {
            throw new RuntimeException('Replenish quantity must be greater than zero.');
        }

        return self::transaction(static function () use ($resourceId, $units): array {
            $resource = Database::selectOne(
                'SELECT * FROM resources WHERE id = :id FOR UPDATE',
                ['id' => $resourceId]
            );

            if ($resource === null) {
                throw new RuntimeException('Resource not found.');
            }

            $quantity = (int) $resource['quantity'] + $units;
            $available = (int) $resource['available_quantity'] + $units;

            Database::execute(
                'UPDATE resources SET quantity = :quantity, available_quantity = :available, status = :status WHERE id = :id',
                [
                    'quantity'   => $quantity,
                    'available'  => $available,
                    'status'     => self::statusForQuantity($available, $quantity),
                    'id'         => $resourceId,
                ]
            );

            $updated = self::find($resourceId);

            if ($updated === null) {
                throw new RuntimeException('Resource not found.');
            }

            return $updated;
        });
    }

    /**
     * Aggregate availability per resource type (for the admin summary).
     *
     * @return array<string, array{total:int, available:int}>
     */
    public static function summaryByType(): array
    {
        $rows = Database::select(
            'SELECT resource_type, SUM(quantity) AS total, SUM(available_quantity) AS available
             FROM resources GROUP BY resource_type'
        );

        $summary = [];

        foreach ($rows as $row) {
            $summary[(string) $row['resource_type']] = [
                'total'     => (int) $row['total'],
                'available' => (int) $row['available'],
            ];
        }

        return $summary;
    }

    /**
     * Platform-wide inventory totals for the admin control center.
     *
     * @return array{total_resources:int, total_quantity:int, available_quantity:int, available:int, limited:int, unavailable:int, low_stock:int}
     */
    public static function adminStats(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS total_resources,
                    COALESCE(SUM(quantity), 0) AS total_quantity,
                    COALESCE(SUM(available_quantity), 0) AS available_quantity,
                    COALESCE(SUM(status = 'AVAILABLE'), 0) AS available,
                    COALESCE(SUM(status = 'LIMITED'), 0) AS limited,
                    COALESCE(SUM(status = 'UNAVAILABLE'), 0) AS unavailable,
                    COALESCE(SUM(available_quantity <= FLOOR(quantity * 0.2)), 0) AS low_stock
             FROM resources"
        );

        return [
            'total_resources'    => (int) ($row['total_resources'] ?? 0),
            'total_quantity'     => (int) ($row['total_quantity'] ?? 0),
            'available_quantity' => (int) ($row['available_quantity'] ?? 0),
            'available'          => (int) ($row['available'] ?? 0),
            'limited'            => (int) ($row['limited'] ?? 0),
            'unavailable'        => (int) ($row['unavailable'] ?? 0),
            'low_stock'          => (int) ($row['low_stock'] ?? 0),
        ];
    }

    /**
     * Resources owned by verified organizations, joined with their org name.
     *
     * @return array<int, array>
     */
    public static function adminOverview(int $limit = 100): array
    {
        return Database::select(
            "SELECT r.*, o.name AS organization_name, o.verification_status AS organization_verification_status
             FROM resources r
             JOIN organizations o ON o.id = r.organization_id
             ORDER BY FIELD(r.status, 'UNAVAILABLE', 'LIMITED', 'AVAILABLE'), r.resource_type, r.resource_name
             LIMIT " . max(1, $limit)
        );
    }

    public static function availableCount(): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS aggregate FROM resources
             WHERE status <> 'UNAVAILABLE' AND available_quantity > 0"
        );

        return (int) ($row['aggregate'] ?? 0);
    }
}
