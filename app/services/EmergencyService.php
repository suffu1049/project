<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\EmergencyException;
use App\Core\Service;
use App\Models\AttachmentRepository;
use App\Models\EmergencyRequestRepository;
use App\Models\RequestEventRepository;

/**
 * Emergency request workflow (Phase 4).
 *
 * Business rules live here — controllers stay thin and repositories stay pure
 * persistence. Multi-step state changes run inside a database transaction.
 *
 * Status state machine (MASTER_PROMPT §11 / ARCHITECTURE §9):
 *
 *   CREATED → VERIFIED → MATCHING → ACCEPTED → IN_PROGRESS → RESOLVED
 *   CREATED / VERIFIED / MATCHING → CANCELLED
 *   CREATED / VERIFIED / MATCHING → ESCALATED
 *
 * Ownership:
 *   - The request owner (citizen) may view, edit (while open) and cancel.
 *   - ADMIN may perform any operational action (verify, escalate, status).
 *   - Other users cannot read another user's private request data.
 */
final class EmergencyService extends Service
{
    /** Statuses the owner may still edit. */
    private const EDITABLE_STATUSES = ['CREATED', 'VERIFIED', 'MATCHING'];

    /** Statuses the owner/admin may cancel. */
    private const CANCELLABLE_STATUSES = ['CREATED', 'VERIFIED', 'MATCHING'];

    /** All valid request statuses (must match emergency_requests.status enum). */
    public const STATUSES = [
        'CREATED',
        'VERIFIED',
        'MATCHING',
        'ACCEPTED',
        'IN_PROGRESS',
        'RESOLVED',
        'ESCALATED',
        'CANCELLED',
    ];

    /**
     * Allowed status transitions.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'CREATED'    => ['VERIFIED', 'CANCELLED', 'ESCALATED'],
        'VERIFIED'   => ['MATCHING', 'CANCELLED', 'ESCALATED'],
        'MATCHING'   => ['ACCEPTED', 'ESCALATED', 'CANCELLED'],
        'ACCEPTED'   => ['IN_PROGRESS'],
        'IN_PROGRESS'=> ['RESOLVED'],
        'RESOLVED'   => [],
        'ESCALATED'  => ['CANCELLED'],
        'CANCELLED'  => [],
    ];

    /** Editable request fields (workflow fields are never client-writable). */
    private const EDITABLE_FIELDS = [
        'description',
        'people_affected',
        'latitude',
        'longitude',
        'location_accuracy_m',
        'address_text',
    ];

    public function __construct(
        private readonly PriorityService $priorityService = new PriorityService(),
        private readonly NotificationService $notifications = new NotificationService(),
    ) {
    }

    /**
     * Create a new emergency request for a citizen.
     *
     * Runs in a transaction: insert request → record REQUEST_CREATED event.
     *
     * @param array<string, mixed> $data validated input
     * @return array<string, mixed> the created request row
     */
    public function create(int $userId, array $data): array
    {
        $category = (string) ($data['category'] ?? '');
        $description = trim((string) ($data['description'] ?? ''));
        $people = max(1, (int) ($data['people_affected'] ?? 1));

        $priority = $this->priorityService->calculate([
            'category'        => $category,
            'description'     => $description,
            'people_affected' => $people,
        ]);

        $request = Database::transaction(function () use ($userId, $data, $priority): array {
            $requestId = EmergencyRequestRepository::create([
                'user_id'              => $userId,
                'category'             => $data['category'],
                'description'          => trim((string) $data['description']),
                'people_affected'      => max(1, (int) ($data['people_affected'] ?? 1)),
                'priority'             => $priority['priority'],
                'priority_score'       => $priority['priority_score'],
                'priority_reason'      => $priority['priority_reason'],
                'status'               => 'CREATED',
                'latitude'             => self::nullableFloat($data['latitude'] ?? null),
                'longitude'            => self::nullableFloat($data['longitude'] ?? null),
                'location_accuracy_m'  => self::nullableFloat($data['location_accuracy_m'] ?? null),
                'address_text'         => self::nullableString($data['address_text'] ?? null),
            ]);

            RequestEventRepository::record(
                $requestId,
                'REQUEST_CREATED',
                $userId,
                null,
                'CREATED',
                sprintf(
                    'Request created. Priority %s (score %d).',
                    $priority['priority'],
                    $priority['priority_score']
                )
            );

            $row = EmergencyRequestRepository::find($requestId);

            if ($row === null) {
                throw new EmergencyException('REQUEST_CREATION_FAILED', 'Unable to create the emergency request.', 500);
            }

            $this->log('info', 'Emergency request created.', [
                'request_id' => $requestId,
                'priority'   => $priority['priority'],
                'score'      => $priority['priority_score'],
            ]);

            return $row;
        });

        // Best-effort in-app notification to operators (Phase 8).
        $this->notifications->requestCreated($request, $userId);

        return $request;
    }

    /**
     * Fetch a request the user is allowed to see.
     *
     * Owners see their own requests; admins see everything. Everyone else
     * gets a not-found response (no existence leak for private records).
     *
     * @return array<string, mixed>
     */
    public function findVisible(int $requestId, array $viewer): array
    {
        $request = EmergencyRequestRepository::find($requestId);

        if ($request === null) {
            throw EmergencyException::notFound();
        }

        if (!$this->canView($request, $viewer)) {
            // Hide existence of other users' requests from non-owners.
            throw EmergencyException::notFound();
        }

        return $request;
    }

    /**
     * List requests visible to the current user.
     *
     * - ADMIN: all requests (newest first)
     * - everyone else: only their own requests
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForViewer(array $viewer, int $limit = 50): array
    {
        $role = (string) ($viewer['role'] ?? '');
        $userId = (int) ($viewer['id'] ?? 0);

        if ($role === 'ADMIN') {
            return EmergencyRequestRepository::recent($limit);
        }

        return EmergencyRequestRepository::forUser($userId, $limit);
    }

    /**
     * Update the citizen-editable fields of an open request.
     *
     * @param array<string, mixed> $data validated subset of EDITABLE_FIELDS
     * @return array<string, mixed>
     */
    public function update(int $requestId, array $viewer, array $data): array
    {
        $request = $this->findVisible($requestId, $viewer);

        if (!$this->canEdit($request, $viewer)) {
            throw EmergencyException::cannotEdit();
        }

        $status = (string) $request['status'];

        if (!in_array($status, self::EDITABLE_STATUSES, true)) {
            throw EmergencyException::cannotEdit();
        }

        $attributes = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($field === 'description') {
                $attributes[$field] = trim((string) $value);
                continue;
            }

            if ($field === 'people_affected') {
                $attributes[$field] = max(1, (int) $value);
                continue;
            }

            if ($field === 'address_text') {
                $attributes[$field] = self::nullableString($value);
                continue;
            }

            $attributes[$field] = self::nullableFloat($value);
        }

        if ($attributes === []) {
            return $request;
        }

        // Re-score when the inputs that affect priority change.
        if (isset($attributes['description']) || isset($attributes['people_affected'])) {
            $priority = $this->priorityService->calculate([
                'category'        => (string) $request['category'],
                'description'     => (string) ($attributes['description'] ?? $request['description']),
                'people_affected' => (int) ($attributes['people_affected'] ?? $request['people_affected']),
            ]);

            $attributes['priority']        = $priority['priority'];
            $attributes['priority_score']  = $priority['priority_score'];
            $attributes['priority_reason'] = $priority['priority_reason'];
        }

        return Database::transaction(function () use ($requestId, $viewer, $attributes, $request): array {
            EmergencyRequestRepository::update($requestId, $attributes);

            RequestEventRepository::record(
                $requestId,
                'REQUEST_UPDATED',
                (int) ($viewer['id'] ?? 0),
                (string) $request['status'],
                (string) $request['status'],
                'Request details updated by the requester.'
            );

            $updated = EmergencyRequestRepository::find($requestId);

            if ($updated === null) {
                throw EmergencyException::notFound();
            }

            return $updated;
        });
    }

    /**
     * Cancel a request that has not been resolved or already cancelled.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $requestId, array $viewer, ?string $note = null): array
    {
        $request = $this->findVisible($requestId, $viewer);
        $status = (string) $request['status'];

        if ($status === 'CANCELLED') {
            throw EmergencyException::alreadyCancelled();
        }

        if ($status === 'RESOLVED') {
            throw EmergencyException::alreadyResolved();
        }

        if (!$this->canCancel($request, $viewer)) {
            throw EmergencyException::forbidden();
        }

        return $this->transition($requestId, $viewer, 'CANCELLED', $note ?? 'Request cancelled.');
    }

    /**
     * Perform a validated status transition.
     *
     * Used by cancel (citizen/admin) and by operational actions
     * (verify / escalate / status) that are admin-scoped at the HTTP layer.
     *
     * @return array<string, mixed>
     */
    public function changeStatus(int $requestId, array $viewer, string $newStatus, ?string $note = null): array
    {
        $newStatus = strtoupper(trim($newStatus));

        if (!in_array($newStatus, self::STATUSES, true)) {
            throw EmergencyException::validation(
                ['status' => ['Status must be one of: ' . implode(', ', self::STATUSES) . '.']],
                'Validation failed.'
            );
        }

        $request = $this->findVisible($requestId, $viewer);

        // Citizens may only cancel; every other transition is operational.
        if ((string) ($viewer['role'] ?? '') !== 'ADMIN' && $newStatus !== 'CANCELLED') {
            throw EmergencyException::forbidden();
        }

        if ((string) ($viewer['role'] ?? '') !== 'ADMIN' && !$this->owns($request, $viewer)) {
            throw EmergencyException::forbidden();
        }

        return $this->transition($requestId, $viewer, $newStatus, $note);
    }

    /**
     * Append a request timeline event (read side for detail pages / API).
     *
     * @return array<int, array<string, mixed>>
     */
    public function events(int $requestId, array $viewer): array
    {
        $this->findVisible($requestId, $viewer);

        return RequestEventRepository::forRequest($requestId);
    }

    /**
     * Safely store an uploaded attachment for a request.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array<string, mixed> the created attachment row
     */
    public function attach(int $requestId, array $viewer, array $file): array
    {
        $request = $this->findVisible($requestId, $viewer);

        if (!$this->canView($request, $viewer)) {
            throw EmergencyException::forbidden();
        }

        $allowed = (array) Config::get('services.attachments.allowed_mime', [
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);
        $maxBytes = (int) Config::get('services.attachments.max_bytes', 5 * 1024 * 1024);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw EmergencyException::attachmentRejected('No file was uploaded.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw EmergencyException::attachmentRejected('The upload failed. Please try again.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            // is_uploaded_file fails in CLI tests; fall back to is_file for test harnesses.
            if ($tmpName === '' || !is_file($tmpName)) {
                throw EmergencyException::attachmentRejected('The uploaded file is not valid.');
            }
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw EmergencyException::attachmentRejected('The uploaded file is empty.');
        }

        if ($size > $maxBytes) {
            throw EmergencyException::attachmentRejected('File exceeds the 5 MB upload limit.');
        }

        // Real MIME detection — never trust the client-provided type alone.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmpName) ?: '');

        if (!in_array($mime, $allowed, true)) {
            throw EmergencyException::attachmentRejected('Only JPG, PNG and WebP images are allowed.');
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'bin',
        };

        $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativeDir = 'uploads/emergency';
        $absoluteDir = storage_path($relativeDir);

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new EmergencyException('INTERNAL_ERROR', 'Unable to store the attachment.', 500);
        }

        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $safeName;
        $relativePath = $relativeDir . '/' . $safeName;

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $absolutePath)
            : rename($tmpName, $absolutePath);

        if (!$moved && !copy($tmpName, $absolutePath)) {
            throw new EmergencyException('INTERNAL_ERROR', 'Unable to store the attachment.', 500);
        }

        $attachmentId = AttachmentRepository::record(
            $requestId,
            (int) ($viewer['id'] ?? 0),
            self::safeFileName((string) ($file['name'] ?? $safeName)),
            $relativePath,
            $mime,
            $size
        );

        RequestEventRepository::record(
            $requestId,
            'ATTACHMENT_ADDED',
            (int) ($viewer['id'] ?? 0),
            (string) $request['status'],
            (string) $request['status'],
            'Attachment uploaded.'
        );

        $attachment = AttachmentRepository::find($attachmentId);

        if ($attachment === null) {
            throw new EmergencyException('INTERNAL_ERROR', 'Unable to record the attachment.', 500);
        }

        return $attachment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attachments(int $requestId, array $viewer): array
    {
        $this->findVisible($requestId, $viewer);

        return AttachmentRepository::forRequest($requestId);
    }

    /**
     * Validate registration-style input for a request using shared rules.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateCreation(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.emergency_request.rules', []),
            (array) Config::get('validation.emergency_request.labels', [])
        );

        if ($validator->fails()) {
            throw EmergencyException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Validate a partial update payload.
     *
     * Only fields present in the input are validated — omitted columns are
     * never written (partial update semantics).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateUpdate(array $data): array
    {
        $allRules = (array) Config::get('validation.emergency_request.rules', []);
        $labels = (array) Config::get('validation.emergency_request.labels', []);

        $rules = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $data) && array_key_exists($field, $allRules)) {
                $rules[$field] = $allRules[$field];
            }
        }

        if ($rules === []) {
            return [];
        }

        $validator = validator($data, $rules, $labels);

        if ($validator->fails()) {
            throw EmergencyException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * @return list<string>
     */
    public static function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Apply a state transition inside a transaction.
     *
     * @return array<string, mixed>
     */
    private function transition(int $requestId, array $viewer, string $newStatus, ?string $note): array
    {
        $request = EmergencyRequestRepository::find($requestId);

        if ($request === null) {
            throw EmergencyException::notFound();
        }

        $current = (string) $request['status'];

        if ($current === 'CANCELLED') {
            throw EmergencyException::alreadyCancelled();
        }

        if ($current === 'RESOLVED' && $newStatus !== 'RESOLVED') {
            throw EmergencyException::alreadyResolved();
        }

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw EmergencyException::invalidTransition($current, $newStatus);
        }

        $actorId = (int) ($viewer['id'] ?? 0);
        $resolvedAt = $newStatus === 'RESOLVED' ? date('Y-m-d H:i:s') : null;

        $result = Database::transaction(function () use ($requestId, $current, $newStatus, $actorId, $note, $resolvedAt): array {
            EmergencyRequestRepository::updateStatus($requestId, $newStatus, $resolvedAt);

            RequestEventRepository::record(
                $requestId,
                'STATUS_CHANGED',
                $actorId,
                $current,
                $newStatus,
                $note
            );

            $updated = EmergencyRequestRepository::find($requestId);

            if ($updated === null) {
                throw EmergencyException::notFound();
            }

            $this->log('info', 'Emergency request status changed.', [
                'request_id' => $requestId,
                'from'       => $current,
                'to'         => $newStatus,
            ]);

            return $updated;
        });

        // Best-effort notifications after the transaction commits (Phase 8).
        $this->notifications->statusChanged($result, $current, $newStatus, $actorId);

        return $result;
    }

    private function canView(array $request, array $viewer): bool
    {
        return $this->owns($request, $viewer) || (string) ($viewer['role'] ?? '') === 'ADMIN';
    }

    private function canEdit(array $request, array $viewer): bool
    {
        if ((string) ($viewer['role'] ?? '') === 'ADMIN') {
            return true;
        }

        return $this->owns($request, $viewer)
            && in_array((string) $request['status'], self::EDITABLE_STATUSES, true);
    }

    private function canCancel(array $request, array $viewer): bool
    {
        if ((string) ($viewer['role'] ?? '') === 'ADMIN') {
            return true;
        }

        return $this->owns($request, $viewer)
            && in_array((string) $request['status'], self::CANCELLABLE_STATUSES, true);
    }

    private function owns(array $request, array $viewer): bool
    {
        return (int) ($request['user_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Strip any path components from a client-supplied filename.
     */
    private static function safeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'file';

        return mb_substr($name, 0, 255);
    }
}
