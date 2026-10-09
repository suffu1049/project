<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\NotificationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

/**
 * JSON notification endpoints (API.md §43–§46).
 *
 * GET  /api/v1/notifications
 * POST /api/v1/notifications/{id}/read
 * POST /api/v1/notifications/read-all
 */
final class NotificationApiController extends Controller
{
    private readonly NotificationService $service;

    public function __construct()
    {
        $this->service = new NotificationService();
    }

    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        try {
            $result = $this->service->listForUser((int) $user['id'], [
                'unread' => $request->query('unread', $request->input('unread')),
                'page'   => $request->query('page', $request->input('page')),
                'limit'  => $request->query('limit', $request->input('limit')),
            ]);
        } catch (NotificationException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Notifications retrieved.');
    }

    /**
     * POST /api/v1/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        try {
            $item = $this->service->markRead((int) $id, (int) $user['id']);
        } catch (NotificationException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'id'          => $item['id'],
            'read_status' => $item['read_status'],
        ], 'Notification marked as read.');
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function markAllRead(Request $request): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        try {
            $this->service->markAllRead((int) $user['id']);
        } catch (NotificationException $exception) {
            return $this->failed($exception);
        }

        return $this->json(null, 'Notifications marked as read.');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function failed(NotificationException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}
