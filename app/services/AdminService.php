<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AdminException;
use App\Core\Service;
use App\Models\AuditRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\OrganizationRepository;
use App\Models\RequestEventRepository;
use App\Models\ResourceRepository;
use App\Models\VolunteerRepository;

/**
 * Admin control center (Phase 9).
 *
 * Aggregates operational state (KPIs, queues, inventory, activity) and performs
 * audited administrative actions. Read methods are cheap aggregate queries —
 * no N+1 — and every mutating action enforces ADMIN server-side and records an
 * audit row plus a request timeline event where applicable.
 *
 * Business rules that already exist elsewhere are reused: request status
 * transitions go through EmergencyService, volunteer/organization verification
 * through their own services. This service only orchestrates + audits.
 */
final class AdminService extends Service
{
    public const REQUEST_STATUSES = [
        'CREATED',
        'VERIFIED',
        'MATCHING',
        'ACCEPTED',
        'IN_PROGRESS',
        'RESOLVED',
        'ESCALATED',
        'CANCELLED',
    ];

    public const REQUEST_PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    public const REQUEST_CATEGORIES = [
        'MEDICAL',
        'ACCIDENT',
        'FLOOD',
        'FIRE',
        'FOOD_WATER',
        'SHELTER',
        'TRANSPORT',
        'MISSING_STRANDED',
        'OTHER',
    ];

    /**
     * KPI cards for the control center (API.md §55).
     *
     * @return array<string, int>
     */
    public function kpis(): array
    {
        return [
            'total_requests'       => EmergencyRequestRepository::totalCount(),
            'active_emergencies'   => EmergencyRequestRepository::activeCount(),
            'critical_requests'    => EmergencyRequestRepository::criticalCount(),
            'high_priority'        => EmergencyRequestRepository::highPriorityCount(),
            'in_progress'          => EmergencyRequestRepository::inProgressCount(),
            'resolved_requests'    => EmergencyRequestRepository::resolvedCount(),
            'available_volunteers' => VolunteerRepository::countAvailableVerified(),
            'pending_volunteers'   => VolunteerRepository::countByVerification('PENDING'),
            'available_resources'  => ResourceRepository::availableCount(),
            'verified_organizations' => OrganizationRepository::countVerified(),
            'pending_organizations'  => OrganizationRepository::countByVerificationStatus('PENDING'),
            'active_matches'       => MatchRepository::activeCount(),
        ];
    }

    /**
     * Full dashboard payload: KPIs + status/priority breakdowns + queues.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        return [
            'kpis'         => $this->kpis(),
            'by_status'    => EmergencyRequestRepository::countsByStatus(),
            'by_priority'  => EmergencyRequestRepository::countsByPriority(),
            'by_category'  => EmergencyRequestRepository::countsByCategory(),
            'critical_queue' => array_slice(EmergencyRequestRepository::active(8), 0, 8),
            'activity'     => $this->activity(8),
            'resources'    => ResourceRepository::adminStats(),
            'resource_shortages' => $this->resourceShortages(6),
        ];
    }

    /**
     * Filtered, paginated operational request queue (API.md §56).
     *
     * @param array<string, mixed> $query
     * @return array{items: array<int, array>, pagination: array<string, int>, filters: array<string, mixed>}
     */
    public function requestQueue(array $query): array
    {
        $filters = [
            'status'    => $this->enumFilter($query['status'] ?? null, self::REQUEST_STATUSES),
            'priority'  => $this->enumFilter($query['priority'] ?? null, self::REQUEST_PRIORITIES),
            'category'  => $this->enumFilter($query['category'] ?? null, self::REQUEST_CATEGORIES),
            'date_from' => $this->dateFilter($query['date_from'] ?? null),
            'date_to'   => $this->dateFilter($query['date_to'] ?? null),
            'search'    => $this->searchFilter($query['search'] ?? null),
        ];

        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? min(100, max(1, (int) $query['limit'])) : 20;

        $result = EmergencyRequestRepository::adminPage($page, $limit, $filters);

        return [
            'items'      => $result['items'],
            'pagination' => $result['pagination'],
            'filters'    => $filters,
        ];
    }

    /**
     * Platform resource overview (API.md §57).
     *
     * @return array<string, mixed>
     */
    public function resourceOverview(): array
    {
        return [
            'summary'     => ResourceRepository::summaryByType(),
            'stats'       => ResourceRepository::adminStats(),
            'items'       => ResourceRepository::adminOverview(100),
            'shortages'   => $this->resourceShortages(20),
        ];
    }

    /**
     * Resources that are limited/unavailable or below 20% availability.
     *
     * @return array<int, array>
     */
    public function resourceShortages(int $limit = 10): array
    {
        $items = ResourceRepository::adminOverview(200);

        $short = array_values(array_filter($items, static function (array $row): bool {
            $available = (int) $row['available_quantity'];
            $quantity = (int) $row['quantity'];

            return $available <= 0
                || (string) $row['status'] === 'LIMITED'
                || ($quantity > 0 && ($available / $quantity) <= 0.2);
        }));

        usort($short, static function (array $a, array $b): int {
            $ratioA = (int) $a['quantity'] > 0 ? (int) $a['available_quantity'] / (int) $a['quantity'] : 0.0;
            $ratioB = (int) $b['quantity'] > 0 ? (int) $b['available_quantity'] / (int) $b['quantity'] : 0.0;

            return $ratioA <=> $ratioB;
        });

        return array_slice($short, 0, max(1, $limit));
    }

    /**
     * Recent operational activity (request timeline events + audit trail).
     *
     * @return array<int, array>
     */
    public function activity(int $limit = 20): array
    {
        return RequestEventRepository::recentActivity($limit);
    }

    /**
     * Audit trail with pagination (API.md §59).
     *
     * @param array<string, mixed> $query
     * @return array{items: array<int, array>, pagination: array<string, int>}
     */
    public function auditLogs(array $query): array
    {
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? min(100, max(1, (int) $query['limit'])) : 30;

        $filters = [
            'action'      => $this->searchFilter($query['action'] ?? null),
            'entity_type' => $this->searchFilter($query['entity_type'] ?? null),
        ];

        return AuditRepository::adminPage($page, $limit, $filters);
    }

    // ---------------------------------------------------------------------
    // Audited admin actions
    // ---------------------------------------------------------------------

    /**
     * Verify an emergency request (CREATED → VERIFIED) through the workflow.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function verifyRequest(int $requestId, array $admin, ?string $note = null): array
    {
        $this->assertAdmin($admin);

        $service = new EmergencyService();
        $updated = $service->changeStatus($requestId, $admin, 'VERIFIED', $note ?? 'Request verified by operations.');

        $this->audit($admin, 'VERIFY_REQUEST', 'emergency_request', $requestId, [
            'status' => 'VERIFIED',
        ]);

        return $updated;
    }

    /**
     * Escalate a request to operational priority handling.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function escalateRequest(int $requestId, array $admin, ?string $reason = null): array
    {
        $this->assertAdmin($admin);

        $service = new EmergencyService();
        $updated = $service->changeStatus($requestId, $admin, 'ESCALATED', $reason ?? 'Escalated by operations.');

        $this->audit($admin, 'ESCALATE_REQUEST', 'emergency_request', $requestId, [
            'status' => 'ESCALATED',
            'reason' => $reason,
        ]);

        return $updated;
    }

    /**
     * Apply an arbitrary valid workflow transition (admin-authorized).
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function changeRequestStatus(int $requestId, array $admin, string $status, ?string $note = null): array
    {
        $this->assertAdmin($admin);

        $status = strtoupper(trim($status));

        if (!in_array($status, self::REQUEST_STATUSES, true)) {
            throw AdminException::validation(
                ['status' => ['Status must be one of: ' . implode(', ', self::REQUEST_STATUSES) . '.']],
                'Validation failed.'
            );
        }

        $service = new EmergencyService();
        $updated = $service->changeStatus($requestId, $admin, $status, $note);

        $this->audit($admin, 'CHANGE_REQUEST_STATUS', 'emergency_request', $requestId, [
            'status' => $status,
        ]);

        return $updated;
    }

    /**
     * Verify or reject a volunteer profile (delegates to VolunteerService).
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function verifyVolunteer(int $profileId, array $admin, string $status, ?string $reason = null): array
    {
        $this->assertAdmin($admin);

        $service = new VolunteerService();
        $result = $service->adminVerify($profileId, $admin, strtoupper(trim($status)));

        $this->audit($admin, 'VERIFY_VOLUNTEER', 'volunteer_profile', $profileId, [
            'status' => strtoupper(trim($status)),
            'reason' => $reason,
        ]);

        return $result;
    }

    /**
     * Verify or reject an organization (delegates to OrganizationService).
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function verifyOrganization(int $organizationId, array $admin, string $status, ?string $reason = null): array
    {
        $this->assertAdmin($admin);

        $service = new OrganizationService();
        $result = $service->adminVerify($organizationId, $admin, strtoupper(trim($status)), $reason);

        $this->audit($admin, 'VERIFY_ORGANIZATION', 'organization', $organizationId, [
            'status' => strtoupper(trim($status)),
            'reason' => $reason,
        ]);

        return $result;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $admin
     */
    private function assertAdmin(array $admin): void
    {
        if ((string) ($admin['role'] ?? '') !== 'ADMIN') {
            throw AdminException::forbidden();
        }
    }

    /**
     * Record an admin action in the audit trail with request context.
     *
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $metadata
     */
    private function audit(array $admin, string $action, ?string $entityType, ?int $entityId, array $metadata = []): void
    {
        try {
            AuditRepository::record(
                (int) ($admin['id'] ?? 0),
                $action,
                $entityType,
                $entityId,
                array_filter($metadata, static fn ($value): bool => $value !== null && $value !== '')
            );
        } catch (\Throwable $exception) {
            $this->log('warning', 'Admin audit write failed.', [
                'action' => $action,
                'error'  => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, string> $allowed
     */
    private function enumFilter(mixed $value, array $allowed): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function dateFilter(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function searchFilter(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 100);
    }
}
