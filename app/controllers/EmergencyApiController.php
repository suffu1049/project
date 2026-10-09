<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\EmergencyException;
use App\Core\Request;
use App\Core\Response;
use App\Services\EmergencyService;

/**
 * JSON emergency-request endpoints (API.md §15–§24).
 *
 * Every state-changing endpoint requires CSRF via `GET /api/v1/csrf` →
 * `X-CSRF-Token`. Authorization is enforced in EmergencyService (owner vs
 * admin) on top of route-level role guards.
 */
final class EmergencyApiController extends Controller
{
    private readonly EmergencyService $service;

    public function __construct()
    {
        $this->service = new EmergencyService();
    }

    /**
     * GET /api/v1/requests — list requests visible to the caller.
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $items = $this->service->listForViewer($user);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        $status = $request->query('status');
        $priority = $request->query('priority');
        $category = $request->query('category');

        $knownStatuses = ['CREATED', 'VERIFIED', 'MATCHING', 'ACCEPTED', 'IN_PROGRESS', 'RESOLVED', 'CANCELLED', 'ESCALATED'];
        $knownPriorities = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
        $knownCategories = ['MEDICAL', 'ACCIDENT', 'FLOOD', 'FIRE', 'FOOD_WATER', 'SHELTER', 'TRANSPORT', 'MISSING_STRANDED', 'OTHER'];

        if (is_string($status) && $status !== '' && !in_array($status, $knownStatuses, true)) {
            return $this->jsonError(
                'Status filter must be one of: ' . implode(', ', $knownStatuses) . '.',
                'VALIDATION_ERROR',
                422,
                ['status' => ['Invalid status filter.']]
            );
        }

        if (is_string($priority) && $priority !== '' && !in_array($priority, $knownPriorities, true)) {
            return $this->jsonError(
                'Priority filter must be one of: ' . implode(', ', $knownPriorities) . '.',
                'VALIDATION_ERROR',
                422,
                ['priority' => ['Invalid priority filter.']]
            );
        }

        if (is_string($category) && $category !== '' && !in_array($category, $knownCategories, true)) {
            return $this->jsonError(
                'Category filter must be one of: ' . implode(', ', $knownCategories) . '.',
                'VALIDATION_ERROR',
                422,
                ['category' => ['Invalid category filter.']]
            );
        }

        if (is_string($status) && $status !== '') {
            $items = array_values(array_filter($items, static fn (array $row): bool => (string) $row['status'] === $status));
        }

        if (is_string($priority) && $priority !== '') {
            $items = array_values(array_filter($items, static fn (array $row): bool => (string) $row['priority'] === $priority));
        }

        if (is_string($category) && $category !== '') {
            $items = array_values(array_filter($items, static fn (array $row): bool => (string) $row['category'] === $category));
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $total = count($items);
        $pages = $total === 0 ? 0 : (int) ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        $slice = array_slice($items, $offset, $limit);

        return $this->json([
            'items'      => array_map(static fn (array $row): array => self::present($row), $slice),
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $pages,
            ],
        ], 'Requests retrieved successfully.');
    }

    /**
     * POST /api/v1/requests — create a request (Citizen).
     */
    public function store(Request $request): Response
    {
        $user = $this->viewer($request);

        if ((string) ($user['role'] ?? '') !== 'CITIZEN') {
            return $this->jsonError('Only citizens can create emergency requests.', 'FORBIDDEN', 403);
        }

        try {
            $data = $this->service->validateCreation($request->all());
            $created = $this->service->create((int) $user['id'], $data);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ['request' => self::present($created)],
            'Emergency request created successfully.',
            201
        );
    }

    /**
     * GET /api/v1/requests/{id}
     */
    public function show(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $item = $this->service->findVisible((int) $id, $user);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(self::present($item), 'Request retrieved successfully.');
    }

    /**
     * PATCH /api/v1/requests/{id}
     */
    public function update(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $data = $this->service->validateUpdate($request->all());
            $updated = $this->service->update((int) $id, $user, $data);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(self::present($updated), 'Request updated successfully.');
    }

    /**
     * POST /api/v1/requests/{id}/cancel
     */
    public function cancel(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $note = $request->input('note');
        $note = is_string($note) && $note !== '' ? $note : null;

        try {
            $updated = $this->service->cancel((int) $id, $user, $note);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ['id' => (int) $updated['id'], 'status' => (string) $updated['status']],
            'Request cancelled successfully.'
        );
    }

    /**
     * POST /api/v1/requests/{id}/verify — Admin.
     */
    public function verify(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $updated = $this->service->changeStatus((int) $id, $user, 'VERIFIED', 'Request verified by operations.');
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ['id' => (int) $updated['id'], 'status' => (string) $updated['status']],
            'Request verified.'
        );
    }

    /**
     * POST /api/v1/requests/{id}/escalate — Admin.
     */
    public function escalate(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $reason = $request->input('reason');
        $note = is_string($reason) && $reason !== '' ? $reason : 'Escalated by operations.';

        try {
            $updated = $this->service->changeStatus((int) $id, $user, 'ESCALATED', $note);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ['id' => (int) $updated['id'], 'status' => (string) $updated['status']],
            'Request escalated.'
        );
    }

    /**
     * POST /api/v1/requests/{id}/status — Admin (workflow state machine).
     */
    public function status(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $newStatus = strtoupper(trim((string) $request->input('status', '')));
        $note = $request->input('note');
        $note = is_string($note) && $note !== '' ? $note : null;

        if ($newStatus === '') {
            return $this->jsonError('Validation failed.', 'VALIDATION_ERROR', 422, ['status' => 'Status is required.']);
        }

        try {
            $updated = $this->service->changeStatus((int) $id, $user, $newStatus, $note);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ['id' => (int) $updated['id'], 'status' => (string) $updated['status']],
            'Request status updated.'
        );
    }

    /**
     * GET /api/v1/requests/{id}/events
     */
    public function events(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $events = $this->service->events((int) $id, $user);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        $items = array_map(static fn (array $event): array => [
            'id'          => (int) $event['id'],
            'event_type'  => (string) $event['event_type'],
            'old_status'  => $event['old_status'] !== null ? (string) $event['old_status'] : null,
            'new_status'  => $event['new_status'] !== null ? (string) $event['new_status'] : null,
            'note'        => $event['note'] !== null ? (string) $event['note'] : null,
            'actor_id'    => $event['actor_id'] !== null ? (int) $event['actor_id'] : null,
            'actor_name'  => $event['actor_name'] ?? null,
            'created_at'  => (string) $event['created_at'],
        ], $events);

        return $this->json($items, 'Request events retrieved.');
    }

    /**
     * GET /api/v1/requests/{id}/attachments
     */
    public function attachments(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        try {
            $rows = $this->service->attachments((int) $id, $user);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        $items = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'file_name'  => (string) $row['file_name'],
            'mime_type'  => (string) $row['mime_type'],
            'file_size'  => (int) $row['file_size'],
            'created_at' => (string) $row['created_at'],
        ], $rows);

        return $this->json($items, 'Attachments retrieved.');
    }

    /**
     * POST /api/v1/requests/{id}/attachments
     */
    public function storeAttachment(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $file = $request->file('file');

        try {
            if ($file === null) {
                throw EmergencyException::attachmentRejected('No file was uploaded.');
            }

            $attachment = $this->service->attach((int) $id, $user, $file);
        } catch (EmergencyException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'id'         => (int) $attachment['id'],
            'file_name'  => (string) $attachment['file_name'],
            'mime_type'  => (string) $attachment['mime_type'],
            'file_size'  => (int) $attachment['file_size'],
        ], 'Attachment uploaded.', 201);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $reason = trim((string) ($row['priority_reason'] ?? ''));
        $reasonLines = $reason === '' ? [] : (preg_split('/\r\n|\r|\n/', $reason) ?: []);

        return [
            'id'               => (int) $row['id'],
            'user_id'          => (int) $row['user_id'],
            'category'         => (string) $row['category'],
            'description'      => (string) $row['description'],
            'people_affected'  => (int) $row['people_affected'],
            'priority'         => (string) $row['priority'],
            'priority_score'   => (int) $row['priority_score'],
            'priority_reason'  => $reasonLines,
            'status'           => (string) $row['status'],
            'location'         => [
                'latitude'            => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                'longitude'           => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'location_accuracy_m' => $row['location_accuracy_m'] !== null ? (float) $row['location_accuracy_m'] : null,
                'address_text'        => $row['address_text'] !== null ? (string) $row['address_text'] : null,
            ],
            'created_at'       => (string) $row['created_at'],
            'updated_at'       => (string) $row['updated_at'],
            'resolved_at'      => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function failed(EmergencyException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}
