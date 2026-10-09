<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\NotificationException;
use App\Core\Service;
use App\Models\NotificationRepository;
use App\Models\OrganizationRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * In-app notification service (Phase 8).
 *
 * Events (MASTER_PROMPT §21 / PHASES(3).md):
 *   REQUEST_CREATED · MATCH_FOUND · MATCH_ACCEPTED · STATUS_CHANGED
 *   RESOURCE_ALLOCATED · REQUEST_RESOLVED · ESCALATED
 *
 * Delivery is best-effort: a notification failure never rolls back the
 * primary domain action. Recipients are resolved server-side from the
 * domain graph — clients cannot choose who gets notified.
 */
final class NotificationService extends Service
{
    public const TYPE_REQUEST_CREATED = 'REQUEST_CREATED';
    public const TYPE_MATCH_FOUND = 'MATCH_FOUND';
    public const TYPE_MATCH_ACCEPTED = 'MATCH_ACCEPTED';
    public const TYPE_STATUS_CHANGED = 'STATUS_CHANGED';
    public const TYPE_RESOURCE_ALLOCATED = 'RESOURCE_ALLOCATED';
    public const TYPE_REQUEST_RESOLVED = 'REQUEST_RESOLVED';
    public const TYPE_ESCALATED = 'ESCALATED';

    public const TYPES = [
        self::TYPE_REQUEST_CREATED,
        self::TYPE_MATCH_FOUND,
        self::TYPE_MATCH_ACCEPTED,
        self::TYPE_STATUS_CHANGED,
        self::TYPE_RESOURCE_ALLOCATED,
        self::TYPE_REQUEST_RESOLVED,
        self::TYPE_ESCALATED,
    ];

    /**
     * Create one notification. Never throws to the caller.
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null
    ): int {
        if ($userId <= 0 || $title === '' || $message === '') {
            return 0;
        }

        try {
            return NotificationRepository::notify($userId, $type, $title, $message, $entityType, $entityId);
        } catch (\Throwable $exception) {
            $this->log('warning', 'Notification delivery failed.', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param array<int, int> $userIds
     */
    public function notifyMany(array $userIds, string $type, string $title, string $message, ?string $entityType = null, ?int $entityId = null): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0) {
                $this->notify($userId, $type, $title, $message, $entityType, $entityId);
            }
        }
    }

    /**
     * Notify every ACTIVE admin (optionally skipping the actor).
     */
    public function notifyAdmins(
        string $type,
        string $title,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $exceptUserId = null
    ): void {
        $ids = UserRepository::idsByRole('ADMIN', 'ACTIVE');

        if ($exceptUserId !== null) {
            $ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $exceptUserId));
        }

        $this->notifyMany($ids, $type, $title, $message, $entityType, $entityId);
    }

    // ---------------------------------------------------------------------
    // Domain event helpers
    // ---------------------------------------------------------------------

    /**
     * New emergency request → admins (API.md / PHASES events).
     *
     * @param array<string, mixed> $request
     */
    public function requestCreated(array $request, int $actorId): void
    {
        $this->notifyAdmins(
            self::TYPE_REQUEST_CREATED,
            'New emergency request',
            sprintf(
                'Request #%d (%s) was created — priority %s.',
                (int) ($request['id'] ?? 0),
                (string) ($request['category'] ?? 'EMERGENCY'),
                (string) ($request['priority'] ?? 'LOW')
            ),
            'emergency_request',
            (int) ($request['id'] ?? 0),
            $actorId
        );
    }

    /**
     * Status transition → request owner (and admins on escalation/resolution).
     *
     * @param array<string, mixed> $request
     */
    public function statusChanged(array $request, string $from, string $to, int $actorId): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        $ownerId = (int) ($request['user_id'] ?? 0);
        $label = sprintf('Request #%d is now %s.', $requestId, $to);

        if ($to === 'RESOLVED') {
            $this->notify(
                $ownerId,
                self::TYPE_REQUEST_RESOLVED,
                'Request resolved',
                sprintf('Your emergency request #%d has been resolved. Thank you for coordinating with us.', $requestId),
                'emergency_request',
                $requestId
            );

            $this->notifyAdmins(
                self::TYPE_REQUEST_RESOLVED,
                'Request resolved',
                $label,
                'emergency_request',
                $requestId,
                $actorId
            );

            return;
        }

        if ($to === 'ESCALATED') {
            if ($ownerId !== $actorId) {
                $this->notify(
                    $ownerId,
                    self::TYPE_ESCALATED,
                    'Request escalated',
                    sprintf('Your emergency request #%d has been escalated for priority handling.', $requestId),
                    'emergency_request',
                    $requestId
                );
            }

            $this->notifyAdmins(
                self::TYPE_ESCALATED,
                'Request escalated',
                $label,
                'emergency_request',
                $requestId,
                $actorId
            );

            return;
        }

        if ($ownerId !== $actorId && $ownerId > 0) {
            $this->notify(
                $ownerId,
                self::TYPE_STATUS_CHANGED,
                'Request status updated',
                $label,
                'emergency_request',
                $requestId
            );
        }

        if ($to === 'ACCEPTED' || $to === 'IN_PROGRESS') {
            $this->notifyAdmins(
                self::TYPE_STATUS_CHANGED,
                'Request status updated',
                $label,
                'emergency_request',
                $requestId,
                $actorId
            );
        }
    }

    /**
     * New match suggestion → volunteer or resource organization.
     *
     * @param array<string, mixed> $candidate match row / suggestion payload
     * @param array<string, mixed> $request
     */
    public function matchSuggested(array $candidate, array $request): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        $category = (string) ($request['category'] ?? 'emergency');
        $distance = $candidate['distance_km'] ?? null;
        $distanceText = $distance !== null
            ? sprintf(' about %s km away', number_format((float) $distance, 1))
            : '';

        if (!empty($candidate['volunteer_id'])) {
            $profile = VolunteerRepository::find((int) $candidate['volunteer_id']);

            if ($profile === null) {
                return;
            }

            $this->notify(
                (int) $profile['user_id'],
                self::TYPE_MATCH_FOUND,
                'New emergency match',
                sprintf(
                    'A %s emergency%s matches your profile. Review and accept from your matches page.',
                    $category,
                    $distanceText
                ),
                'emergency_request',
                $requestId
            );

            return;
        }

        if (!empty($candidate['resource_id'])) {
            $resource = ResourceRepository::find((int) $candidate['resource_id']);

            if ($resource === null) {
                return;
            }

            $org = OrganizationRepository::find((int) $resource['organization_id']);

            if ($org === null) {
                return;
            }

            $this->notify(
                (int) $org['user_id'],
                self::TYPE_MATCH_FOUND,
                'Resource match suggested',
                sprintf(
                    '%s may be needed for a %s emergency%s.',
                    (string) $resource['resource_name'],
                    $category,
                    $distanceText
                ),
                'emergency_request',
                $requestId
            );
        }
    }

    /**
     * Match accepted → request owner (+ resource org when applicable).
     *
     * @param array<string, mixed> $match
     * @param array<string, mixed>|null $request
     */
    public function matchAccepted(array $match, ?array $request, int $actorId): void
    {
        $requestId = (int) ($match['request_id'] ?? 0);
        $request = $request ?? [];

        if ($request === [] || (int) ($request['id'] ?? 0) !== $requestId) {
            return;
        }

        $ownerId = (int) ($request['user_id'] ?? 0);

        if ($ownerId > 0 && $ownerId !== $actorId) {
            $this->notify(
                $ownerId,
                self::TYPE_MATCH_ACCEPTED,
                'Help is on the way',
                sprintf(
                    'A helper accepted your %s request #%d.',
                    (string) ($request['category'] ?? 'emergency'),
                    $requestId
                ),
                'emergency_request',
                $requestId
            );
        }

        if (!empty($match['resource_id'])) {
            $resource = ResourceRepository::find((int) $match['resource_id']);

            if ($resource !== null) {
                $org = OrganizationRepository::find((int) $resource['organization_id']);

                if ($org !== null && (int) $org['user_id'] !== $actorId) {
                    $this->notify(
                        (int) $org['user_id'],
                        self::TYPE_MATCH_ACCEPTED,
                        'Resource allocation accepted',
                        sprintf(
                            'Your %s stock was accepted for request #%d.',
                            (string) $resource['resource_name'],
                            $requestId
                        ),
                        'emergency_request',
                        $requestId
                    );
                }
            }
        }
    }

    /**
     * Resource allocated → request owner.
     *
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $request
     */
    public function resourceAllocated(array $resource, array $request, int $quantity, int $actorId): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        $ownerId = (int) ($request['user_id'] ?? 0);

        if ($ownerId > 0 && $ownerId !== $actorId) {
            $this->notify(
                $ownerId,
                self::TYPE_RESOURCE_ALLOCATED,
                'Resources allocated',
                sprintf(
                    '%d %s of %s allocated to your request #%d.',
                    $quantity,
                    (string) ($resource['unit'] ?? 'units'),
                    (string) ($resource['resource_name'] ?? 'resource'),
                    $requestId
                ),
                'emergency_request',
                $requestId
            );
        }
    }

    // ---------------------------------------------------------------------
    // Read side
    // ---------------------------------------------------------------------

    /**
     * Paginated notification list for the current user.
     *
     * @param array<string, mixed> $query unread, page, limit
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listForUser(int $userId, array $query = []): array
    {
        $unreadOnly = filter_var($query['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? min(100, max(1, (int) $query['limit'])) : 20;

        $rows = NotificationRepository::forUser($userId, $unreadOnly, $page, $limit);
        $total = NotificationRepository::countForUser($userId, $unreadOnly);
        $unread = NotificationRepository::unreadCount($userId);

        return [
            'items' => array_map(static fn (array $row): array => self::present($row), $rows),
            'meta'  => [
                'page'         => $page,
                'limit'        => $limit,
                'total'        => $total,
                'pages'        => $total === 0 ? 0 : (int) ceil($total / $limit),
                'unread_count' => $unread,
                'unread_only'  => $unreadOnly,
            ],
        ];
    }

    public function unreadCount(int $userId): int
    {
        try {
            return NotificationRepository::unreadCount($userId);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Mark one notification read (owner only — IDOR-safe).
     *
     * @return array<string, mixed>
     */
    public function markRead(int $notificationId, int $userId): array
    {
        $row = NotificationRepository::find($notificationId);

        // Same non-enumeration policy as emergency requests: hide existence.
        if ($row === null || (int) $row['user_id'] !== $userId) {
            throw NotificationException::notFound();
        }

        NotificationRepository::markRead($notificationId, $userId);
        $updated = NotificationRepository::find($notificationId);

        if ($updated === null) {
            throw NotificationException::failed();
        }

        return self::present($updated);
    }

    /**
     * Mark every unread notification for the user as read.
     */
    public function markAllRead(int $userId): int
    {
        try {
            return NotificationRepository::markAllRead($userId);
        } catch (\Throwable $exception) {
            throw NotificationException::failed($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'type'                 => (string) ($row['type'] ?? ''),
            'title'                => (string) ($row['title'] ?? ''),
            'message'              => (string) ($row['message'] ?? ''),
            'related_entity_type'  => $row['related_entity_type'] !== null ? (string) $row['related_entity_type'] : null,
            'related_entity_id'    => $row['related_entity_id'] !== null ? (int) $row['related_entity_id'] : null,
            'read_status'          => (bool) ($row['read_status'] ?? false),
            'created_at'           => (string) ($row['created_at'] ?? ''),
        ];
    }
}
