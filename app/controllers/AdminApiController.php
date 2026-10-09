<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminException;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminService;
use App\Services\AnalyticsService;

/**
 * Admin JSON API (Phase 9) — API.md §54–§59.
 *
 *   GET /api/v1/admin/dashboard
 *   GET /api/v1/admin/requests
 *   GET /api/v1/admin/resources
 *   GET /api/v1/admin/audit-logs
 *
 * All routes are guarded by RoleMiddleware(['ADMIN']) and the service re-checks
 * the role, so a hidden UI element is never the only protection.
 */
final class AdminApiController extends Controller
{
    private readonly AdminService $service;

    private readonly AnalyticsService $analytics;

    public function __construct()
    {
        $this->service = new AdminService();
        $this->analytics = new AnalyticsService();
    }

    /**
     * GET /api/v1/admin/dashboard
     */
    public function dashboard(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        try {
            $data = $this->service->dashboard();
        } catch (AdminException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'kpis'        => $data['kpis'],
            'by_status'   => $data['by_status'],
            'by_priority' => $data['by_priority'],
            'by_category' => $data['by_category'],
            'resources'   => $data['resources'],
        ], 'Dashboard data retrieved.');
    }

    /**
     * GET /api/v1/admin/requests
     */
    public function requests(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $result = $this->service->requestQueue($request->queryAll());

        return $this->json([
            'items'      => $result['items'],
            'pagination' => $result['pagination'],
            'filters'    => $result['filters'],
        ], 'Operational requests retrieved.');
    }

    /**
     * GET /api/v1/admin/resources
     */
    public function resources(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $data = $this->service->resourceOverview();

        return $this->json([
            'summary'   => $data['summary'],
            'stats'     => $data['stats'],
            'shortages' => $data['shortages'],
        ], 'Resource overview retrieved.');
    }

    /**
     * GET /api/v1/admin/audit-logs
     */
    public function auditLogs(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $result = $this->service->auditLogs($request->queryAll());

        return $this->json([
            'items'      => $result['items'],
            'pagination' => $result['pagination'],
        ], 'Audit events retrieved.');
    }

    /**
     * GET /api/v1/admin/analytics — operational analytics (API.md §58).
     */
    public function analytics(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $period = (string) ($request->query('period') ?? '');
        $overview = $this->analytics->overview($period);

        return $this->json($this->analytics->apiPayload($overview), 'Analytics retrieved.');
    }

    /**
     * GET /api/v1/admin/activity — recent timeline events (dashboard feed).
     */
    public function activity(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        return $this->json([
            'items' => $this->service->activity(30),
        ], 'Recent activity retrieved.');
    }

    /**
     * Return a 401/403 response when the caller is not an authenticated admin.
     */
    private function denied(Request $request): ?Response
    {
        $user = $request->attribute('user') ?? auth_user();

        if (!is_array($user) || $user === []) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        if ((string) ($user['role'] ?? '') !== 'ADMIN') {
            return $this->jsonError('You are not authorized to perform this action.', 'FORBIDDEN', 403);
        }

        return null;
    }

    private function failed(AdminException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}
