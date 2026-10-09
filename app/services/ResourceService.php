<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\ResourceException;
use App\Core\Service;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use RuntimeException;

/**
 * Resource inventory, allocation and visibility (Phase 6).
 *
 * Business rules live here — controllers stay thin and repositories stay pure
 * persistence.
 *
 * Rules:
 *   - Only a VERIFIED organization (or an admin) may mutate resources, and
 *     only the owning organization may mutate its own rows (no IDOR).
 *   - available_quantity can never exceed quantity and never goes negative.
 *   - Allocation is transactional: row lock + decrement + request event +
 *     audit entry commit (or roll back) together.
 *   - Admin verifies organizations; resource status never lets a client
 *     fabricate inventory that the CHECK constraint would reject.
 */
final class ResourceService extends Service
{
    /** Controlled resource type vocabulary (matches the schema ENUM). */
    public const TYPES = ['FOOD', 'WATER', 'SHELTER', 'TRANSPORT', 'MEDICAL_SUPPORT', 'BLANKETS', 'OTHER'];

    public const STATUSES = ['AVAILABLE', 'LIMITED', 'UNAVAILABLE'];

    /** Upper bound keeping writes inside the schema's INT UNSIGNED column. */
    public const MAX_QUANTITY = 1000000000;

    /**
     * Paginated resource list scoped to what the viewer may see (API.md §39).
     *
     * Visibility:
     *   - ADMIN        → the whole inventory.
     *   - ORGANIZATION → its own inventory (all statuses); asking for another
     *                    organization's id is rejected (no IDOR).
     *   - other roles  → public inventory: VERIFIED organizations only and
     *                    never UNAVAILABLE rows, no private coordinates.
     *
     * @param array<string, mixed> $query filters: resource_type, status, organization_id, page, limit
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page:int,limit:int,total:int,pages:int}}
     */
    public function list(array $viewer, array $query = []): array
    {
        $role = (string) ($viewer['role'] ?? '');

        $resourceType = $this->normalizeEnum($query['resource_type'] ?? null, self::TYPES, 'resource_type');
        $status = $this->normalizeEnum($query['status'] ?? null, self::STATUSES, 'status');

        $organizationId = null;
        if (isset($query['organization_id']) && $query['organization_id'] !== '' && $query['organization_id'] !== null) {
            if (!is_numeric($query['organization_id']) || (int) $query['organization_id'] < 1) {
                throw ResourceException::validation(['organization_id' => ['Organization id must be a positive number.']]);
            }

            $organizationId = (int) $query['organization_id'];
        }

        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 20;

        $verifiedOnly = false;
        $excludeUnavailable = false;
        $includePrivate = false;

        if ($role === 'ADMIN') {
            $includePrivate = true;
        } elseif ($role === 'ORGANIZATION') {
            $organization = $this->ownOrganization($viewer);

            if ($organizationId !== null && $organizationId !== (int) $organization['id']) {
                throw ResourceException::forbidden();
            }

            $organizationId = (int) $organization['id'];
            $includePrivate = true;
        } else {
            // Public inventory read (CITIZEN / VOLUNTEER / API clients).
            $verifiedOnly = true;
            $excludeUnavailable = true;
        }

        $result = ResourceRepository::pageVisible(
            $page,
            $limit,
            $organizationId,
            $resourceType,
            $status,
            $verifiedOnly,
            $excludeUnavailable
        );

        return [
            'items'      => array_map(
                static fn (array $row): array => self::presentResource($row, $includePrivate),
                $result['items']
            ),
            'pagination' => $result['pagination'],
        ];
    }

    /**
     * Open emergency requests an organization can allocate inventory to.
     *
     * Returns operational identifiers only (no requester contact details, no
     * exact private coordinates) — enough to contribute resources safely.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allocationTargets(array $viewer): array
    {
        $this->assertCanManage($viewer);

        return array_map(
            static fn (array $row): array => [
                'id'              => (int) $row['id'],
                'category'        => (string) $row['category'],
                'priority'        => (string) $row['priority'],
                'status'          => (string) $row['status'],
                'people_affected' => (int) $row['people_affected'],
                'address_text'    => $row['address_text'] !== null ? (string) $row['address_text'] : null,
            ],
            EmergencyRequestRepository::active(20)
        );
    }

    /**
     * Create a resource for the caller's verified organization.
     *
     * @param array<string, mixed> $data validated resource payload
     * @return array<string, mixed> the created row
     */
    public function create(array $viewer, array $data): array
    {
        if ((string) ($viewer['role'] ?? '') !== 'ORGANIZATION') {
            // Admins have no inventory of their own; creation is org-scoped.
            throw ResourceException::forbidden();
        }

        $organization = $this->ownOrganization($viewer);
        $this->assertVerified($organization);

        $validated = $this->validateCreation($data);

        $quantity = (int) $validated['quantity'];
        $available = (int) $validated['available_quantity'];

        $this->assertQuantityBounds($quantity, $available);

        $resourceId = ResourceRepository::create([
            'organization_id'    => (int) $organization['id'],
            'resource_type'      => (string) $validated['resource_type'],
            'resource_name'      => trim((string) $validated['resource_name']),
            'quantity'           => $quantity,
            'available_quantity' => $available,
            'unit'               => self::nullableTrim($validated['unit'] ?? null),
            'status'             => ResourceRepository::statusForQuantity($available, $quantity),
            'latitude'           => $validated['latitude'] ?? null,
            'longitude'          => $validated['longitude'] ?? null,
        ]);

        AuditRepository::record(
            (int) $viewer['id'],
            'CREATE_RESOURCE',
            'resource',
            $resourceId,
            [
                'organization_id' => (int) $organization['id'],
                'resource_type'   => (string) $validated['resource_type'],
                'quantity'        => $quantity,
                'available'       => $available,
            ]
        );

        $this->log('info', 'Resource created.', ['resource_id' => $resourceId]);

        $created = ResourceRepository::find($resourceId);

        if ($created === null) {
            throw ResourceException::notFound();
        }

        return $created;
    }

    /**
     * Update a resource (partial semantics): quantities, unit, status, name.
     *
     * Explicit `status` wins (supports "mark unavailable" while stock exists);
     * otherwise status is re-derived from the quantities.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(array $viewer, int $resourceId, array $data): array
    {
        $resource = ResourceRepository::find($resourceId);

        if ($resource === null) {
            throw ResourceException::notFound();
        }

        $this->authorizeMutation($viewer, $resource);

        $validated = $this->validateUpdate($data);

        $quantity = array_key_exists('quantity', $validated) && $validated['quantity'] !== null
            ? (int) $validated['quantity']
            : (int) $resource['quantity'];
        $available = array_key_exists('available_quantity', $validated) && $validated['available_quantity'] !== null
            ? (int) $validated['available_quantity']
            : (int) $resource['available_quantity'];

        $this->assertQuantityBounds($quantity, $available);

        $attributes = [
            'quantity'           => $quantity,
            'available_quantity' => $available,
        ];

        // resource_type/resource_name are NOT NULL columns: explicitly
        // clearing them is a validation error rather than a database error.
        foreach (['resource_type', 'resource_name'] as $required) {
            if (array_key_exists($required, $data) && ($validated[$required] ?? null) === null) {
                throw ResourceException::validation(
                    [$required => [ucfirst(str_replace('_', ' ' . $required)) . ' is required.']],
                    'Validation failed.'
                );
            }
        }

        if (array_key_exists('resource_type', $validated) && $validated['resource_type'] !== null) {
            $attributes['resource_type'] = (string) $validated['resource_type'];
        }

        if (array_key_exists('resource_name', $validated) && $validated['resource_name'] !== null) {
            $attributes['resource_name'] = trim((string) $validated['resource_name']);
        }

        if (array_key_exists('unit', $data)) {
            $attributes['unit'] = self::nullableTrim($validated['unit'] ?? null);
        }

        // Location is only rewritten when the caller sent coordinates; both
        // empty clears them, one-sided input is rejected.
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $attributes += $this->normalizeLocationAttributes($validated);
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $attributes['status'] = strtoupper((string) $validated['status']);
        } else {
            $attributes['status'] = ResourceRepository::statusForQuantity($available, $quantity);
        }

        ResourceRepository::update($resourceId, $attributes);

        AuditRepository::record(
            (int) ($viewer['id'] ?? 0),
            'UPDATE_RESOURCE',
            'resource',
            $resourceId,
            ['fields' => array_keys($attributes)]
        );

        $updated = ResourceRepository::find($resourceId);

        if ($updated === null) {
            throw ResourceException::notFound();
        }

        return $updated;
    }

    /**
     * Add units to both quantity and available_quantity (restock workflow).
     *
     * @return array<string, mixed>
     */
    public function replenish(array $viewer, int $resourceId, int $units): array
    {
        $resource = ResourceRepository::find($resourceId);

        if ($resource === null) {
            throw ResourceException::notFound();
        }

        $this->authorizeMutation($viewer, $resource);

        if ($units < 1 || $units > self::MAX_QUANTITY) {
            throw ResourceException::validation(
                ['units' => ['Replenish units must be between 1 and ' . self::MAX_QUANTITY . '.']],
                'Validation failed.'
            );
        }

        try {
            $updated = ResourceRepository::replenish($resourceId, $units);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'not found')) {
                throw ResourceException::notFound();
            }

            throw $exception;
        }

        AuditRepository::record(
            (int) ($viewer['id'] ?? 0),
            'REPLENISH_RESOURCE',
            'resource',
            $resourceId,
            ['units' => $units, 'quantity' => (int) $updated['quantity']]
        );

        return $updated;
    }

    /**
     * Allocate quantity to an emergency request — one atomic unit of work:
     * row lock + decrement + status refresh + request event + audit entry.
     *
     * @param array<string, mixed> $data {request_id: int, quantity: int}
     * @return array{resource: array<string, mixed>, allocation: array<string, mixed>}
     */
    public function allocate(array $viewer, int $resourceId, array $data): array
    {
        $resource = ResourceRepository::find($resourceId);

        if ($resource === null) {
            throw ResourceException::notFound();
        }

        $this->authorizeMutation($viewer, $resource);

        if ((string) $resource['status'] === 'UNAVAILABLE') {
            throw ResourceException::unavailable(sprintf(
                'Resource "%s" is marked unavailable and cannot be allocated.',
                (string) $resource['resource_name']
            ));
        }

        $validated = $this->validateAllocation($data);
        $requestId = (int) $validated['request_id'];
        $quantity = (int) $validated['quantity'];

        $request = EmergencyRequestRepository::find($requestId);

        if ($request === null) {
            throw ResourceException::requestNotFound();
        }

        if (!in_array((string) $request['status'], EmergencyRequestRepository::ACTIVE_STATUSES, true)) {
            throw ResourceException::requestClosed(sprintf(
                'Request #%d is %s; resources can only be allocated to active requests.',
                $requestId,
                (string) $request['status']
            ));
        }

        $actorId = (int) ($viewer['id'] ?? 0);
        $note = sprintf(
            '%d %s of %s allocated to request #%d.',
            $quantity,
            (string) ($resource['unit'] ?? 'units'),
            (string) $resource['resource_name'],
            $requestId
        );

        try {
            // Database::transaction() joins nested repository transactions, so
            // the decrement, the timeline event and the audit entry commit or
            // roll back together (API.md §42).
            $allocation = Database::transaction(function () use ($resourceId, $quantity, $requestId, $request, $actorId, $note, $resource): array {
                $result = ResourceRepository::allocate($resourceId, $quantity);

                RequestEventRepository::record(
                    $requestId,
                    'RESOURCE_ALLOCATED',
                    $actorId,
                    (string) $request['status'],
                    (string) $request['status'],
                    $note
                );

                AuditRepository::record(
                    $actorId,
                    'ALLOCATE_RESOURCE',
                    'resource',
                    $resourceId,
                    [
                        'request_id'       => $requestId,
                        'quantity'         => $quantity,
                        'available_before' => (int) $resource['available_quantity'],
                        'available_after'  => (int) $result['available_after'],
                    ]
                );

                return $result;
            });
        } catch (ResourceException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Insufficient')) {
                throw ResourceException::insufficient(sprintf(
                    'Only %d of %s are available to allocate.',
                    (int) $resource['available_quantity'],
                    (string) $resource['resource_name']
                ));
            }

            throw $exception;
        }

        $updated = ResourceRepository::find($resourceId);

        if ($updated === null) {
            throw ResourceException::notFound();
        }

        // Phase 8: notify the request owner after a successful allocation.
        (new NotificationService())->resourceAllocated($updated, $request, $quantity, $actorId);

        $this->log('info', 'Resource allocated.', [
            'resource_id' => $resourceId,
            'request_id'  => $requestId,
            'quantity'    => $quantity,
        ]);

        return [
            'resource'   => self::presentResource($updated, true),
            'allocation' => $allocation,
        ];
    }

    /**
     * Single resource read for editors and public inventory pages.
     *
     * @return array<string, mixed>
     */
    public function findVisible(array $viewer, int $resourceId): array
    {
        $resource = ResourceRepository::find($resourceId);

        if ($resource === null) {
            throw ResourceException::notFound();
        }

        $role = (string) ($viewer['role'] ?? '');

        if ($role === 'ADMIN') {
            return self::presentResource($resource, true);
        }

        if ($role === 'ORGANIZATION') {
            $organization = $this->ownOrganization($viewer);

            if ((int) $organization['id'] !== (int) $resource['organization_id']) {
                throw ResourceException::forbidden();
            }

            return self::presentResource($resource, true);
        }

        // Public single read: only verified, available inventory.
        if ((string) $resource['status'] === 'UNAVAILABLE') {
            throw ResourceException::notFound();
        }

        $organization = OrganizationRepository::find((int) $resource['organization_id']);

        if ($organization === null || (string) $organization['verification_status'] !== 'VERIFIED') {
            throw ResourceException::notFound();
        }

        return self::presentResource($resource, false);
    }

    /**
     * Validate a full resource payload (creation).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateCreation(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.resource.rules', []),
            (array) Config::get('validation.resource.labels', [])
        );

        if ($validator->fails()) {
            throw ResourceException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Validate a partial resource payload (PATCH / edit form).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateUpdate(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.resource_update.rules', []),
            (array) Config::get('validation.resource_update.labels', [])
        );

        if ($validator->fails()) {
            throw ResourceException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Validate an allocation payload (API.md §42).
     *
     * @param array<string, mixed> $data
     * @return array{request_id: int, quantity: int}
     */
    public function validateAllocation(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.resource_allocation.rules', []),
            (array) Config::get('validation.resource_allocation.labels', [])
        );

        if ($validator->fails()) {
            throw ResourceException::validation($validator->errors());
        }

        $validated = $validator->validated();

        return [
            'request_id' => (int) $validated['request_id'],
            'quantity'   => (int) $validated['quantity'],
        ];
    }

    /**
     * API presentation of a resource row.
     *
     * Private coordinates are only exposed to the owning organization and
     * admins; public inventory readers get operational fields only.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentResource(array $row, bool $includePrivate = false): array
    {
        $data = [
            'id'                 => (int) ($row['id'] ?? 0),
            'organization_id'    => (int) ($row['organization_id'] ?? 0),
            'resource_type'      => (string) ($row['resource_type'] ?? ''),
            'resource_name'      => (string) ($row['resource_name'] ?? ''),
            'quantity'           => (int) ($row['quantity'] ?? 0),
            'available_quantity' => (int) ($row['available_quantity'] ?? 0),
            'unit'               => $row['unit'] !== null ? (string) $row['unit'] : null,
            'status'             => (string) ($row['status'] ?? 'AVAILABLE'),
            'created_at'         => (string) ($row['created_at'] ?? ''),
            'updated_at'         => (string) ($row['updated_at'] ?? ''),
        ];

        if (isset($row['organization_name'])) {
            $data['organization_name'] = (string) $row['organization_name'];
        }

        if ($includePrivate) {
            $data['latitude'] = $row['latitude'] !== null ? (float) $row['latitude'] : null;
            $data['longitude'] = $row['longitude'] !== null ? (float) $row['longitude'] : null;
        }

        return $data;
    }

    /**
     * The organization row linked to the viewer's account.
     *
     * @return array<string, mixed>
     */
    private function ownOrganization(array $viewer): array
    {
        $organization = OrganizationRepository::findByUserId((int) ($viewer['id'] ?? 0));

        if ($organization === null) {
            throw ResourceException::forbidden('No organization profile is linked to this account.');
        }

        return $organization;
    }

    /**
     * Owner-or-admin guard for every resource mutation (no IDOR).
     */
    private function authorizeMutation(array $viewer, array $resource): void
    {
        $role = (string) ($viewer['role'] ?? '');

        if ($role === 'ADMIN') {
            return;
        }

        if ($role !== 'ORGANIZATION') {
            throw ResourceException::forbidden();
        }

        $organization = $this->ownOrganization($viewer);

        if ((int) $organization['id'] !== (int) $resource['organization_id']) {
            throw ResourceException::forbidden();
        }

        $this->assertVerified($organization);
    }

    /**
     * Guard for endpoints that require a verified organization (or an admin).
     */
    private function assertCanManage(array $viewer): void
    {
        $role = (string) ($viewer['role'] ?? '');

        if ($role === 'ADMIN') {
            return;
        }

        if ($role !== 'ORGANIZATION') {
            throw ResourceException::forbidden();
        }

        $this->assertVerified($this->ownOrganization($viewer));
    }

    /**
     * Only verified organizations contribute inventory (API.md §40).
     *
     * @param array<string, mixed> $organization
     */
    private function assertVerified(array $organization): void
    {
        if ((string) ($organization['verification_status'] ?? 'PENDING') !== 'VERIFIED') {
            throw ResourceException::notVerified();
        }
    }

    /**
     * Quantity rules (API.md §61): non-negative, within the column range and
     * available_quantity never greater than quantity.
     */
    private function assertQuantityBounds(int $quantity, int $available): void
    {
        if ($quantity < 0 || $quantity > self::MAX_QUANTITY) {
            throw ResourceException::validation(
                ['quantity' => ['Total quantity must be between 0 and ' . self::MAX_QUANTITY . '.']],
                'Validation failed.'
            );
        }

        if ($available < 0 || $available > self::MAX_QUANTITY) {
            throw ResourceException::validation(
                ['available_quantity' => ['Available quantity must be between 0 and ' . self::MAX_QUANTITY . '.']],
                'Validation failed.'
            );
        }

        if ($available > $quantity) {
            throw ResourceException::validation(
                ['available_quantity' => ['Available quantity cannot exceed total quantity.']],
                'Validation failed.'
            );
        }
    }

    /**
     * Normalize an optional enum filter; empty input becomes null.
     *
     * @param array<int, string> $allowed
     */
    private function normalizeEnum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, $allowed, true)) {
            throw ResourceException::validation(
                [$field => [ucfirst(str_replace('_', ' ', $field)) . ' must be one of: ' . implode(', ', $allowed) . '.']],
                'Validation failed.'
            );
        }

        return $normalized;
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Latitude/longitude must be provided together; both empty clears the
     * stored coordinates.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeLocationAttributes(array $validated): array
    {
        if (!array_key_exists('latitude', $validated) && !array_key_exists('longitude', $validated)) {
            return [];
        }

        $lat = $validated['latitude'] ?? null;
        $lng = $validated['longitude'] ?? null;

        if (($lat === null) !== ($lng === null)) {
            throw ResourceException::validation(
                ['latitude' => ['Latitude and longitude must be provided together.']],
                'Validation failed.'
            );
        }

        if ($lat === null) {
            return ['latitude' => null, 'longitude' => null];
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw ResourceException::validation(
                [
                    'latitude'  => ['Latitude must be between -90 and 90.'],
                    'longitude' => ['Longitude must be between -180 and 180.'],
                ],
                'Validation failed.'
            );
        }

        return ['latitude' => $lat, 'longitude' => $lng];
    }
}