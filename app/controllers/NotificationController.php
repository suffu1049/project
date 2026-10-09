<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\NotificationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;

/**
 * Browser notification centre (Phase 8).
 *
 * List, mark-read and mark-all-read follow PRG for state changes.
 * Authorization is owner-scoped inside NotificationService (IDOR-safe).
 */
final class NotificationController extends Controller
{
    private readonly NotificationService $service;

    public function __construct()
    {
        $this->service = new NotificationService();
    }

    /**
     * GET /notifications
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->redirect(url('login'));
        }

        $unreadOnly = filter_var($request->query('unread', '0'), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->service->listForUser((int) $user['id'], [
                'unread' => $unreadOnly,
                'page'   => $request->query('page'),
                'limit'  => $request->query('limit') ?? 30,
            ]);
        } catch (NotificationException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('notifications/index', [
            'title'       => 'Notifications',
            'user'        => $user,
            'items'       => $result['items'],
            'meta'        => $result['meta'],
            'unreadOnly'  => $unreadOnly,
        ]);
    }

    /**
     * POST /notifications/{id}/read
     */
    public function markRead(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->redirect(url('login'));
        }

        try {
            $this->service->markRead((int) $id, (int) $user['id']);
            flash('success', 'Notification marked as read.');
        } catch (NotificationException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('notifications'));
    }

    /**
     * POST /notifications/read-all
     */
    public function markAllRead(Request $request): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->redirect(url('login'));
        }

        try {
            $count = $this->service->markAllRead((int) $user['id']);
            flash('success', $count > 0
                ? sprintf('%d notification%s marked as read.', $count, $count === 1 ? '' : 's')
                : 'No unread notifications.');
        } catch (NotificationException $exception) {
            flash('error', $exception->userMessage());
        }

        return $this->redirect(url('notifications'));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function error(Request $request, NotificationException $exception): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
        }

        flash('error', $exception->userMessage());

        return $this->redirect(url('notifications'));
    }
}
