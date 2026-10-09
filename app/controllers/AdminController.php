<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminException;
use App\Core\Controller;
use App\Core\EmergencyException;
use App\Core\OrganizationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\VolunteerException;
use App\Services\AdminService;
use App\Services\AnalyticsService;
use App\Services\LocationService;

/**
 * Admin control center (Phase 9).
 *
 * Every route behind this controller is guarded by RoleMiddleware(['ADMIN']);
 * the service re-checks the role server-side, so authorization never depends on
 * hidden UI. Mutations follow Post/Redirect/Get and write an audit row.
 */
final class AdminController extends Controller
{
    private readonly AdminService $service;

    private readonly AnalyticsService $analytics;

    public function __construct()
    {
        $this->service = new AdminService();
        $this->analytics = new AnalyticsService();
    }

    /**
     * GET /admin (also /admin/dashboard) — the control center.
     */
    public function dashboard(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $data = $this->service->dashboard();
        } catch (\Throwable $exception) {
            flash('error', 'Dashboard data is temporarily unavailable.');

            return $this->view('admin/dashboard', [
                'title' => 'Control center',
                'user'  => $user,
                'data'  => $this->emptyDashboard(),
            ]);
        }

        $leafletCss = (string) config('services.maps.leaflet_css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        $leafletJs = (string) config('services.maps.leaflet_js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');

        return $this->view('admin/dashboard', [
            'title'      => 'Control center',
            'user'       => $user,
            'data'       => $data,
            'mapMeta'    => (new LocationService())->markersForViewer($user)['meta'],
            'headExtra'  => '<link rel="stylesheet" href="' . e($leafletCss) . '">',
            'scripts'    => '<script src="' . e($leafletJs) . '" defer></script>'
                . '<script src="' . e(asset('js/map.js')) . '" defer></script>',
        ]);
    }

    /**
     * GET /admin/requests — filtered operational queue.
     */
    public function requests(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->requestQueue($request->queryAll());
        } catch (AdminException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('admin'));
        }

        return $this->view('admin/requests', [
            'title'      => 'Request queue',
            'user'       => $user,
            'items'      => $result['items'],
            'pagination' => $result['pagination'],
            'filters'    => $result['filters'],
        ]);
    }

    /**
     * GET /admin/resources — platform inventory overview.
     */
    public function resources(Request $request): Response
    {
        $user = $this->viewer($request);

        return $this->view('admin/resources', [
            'title' => 'Resource overview',
            'user'  => $user,
            'data'  => $this->service->resourceOverview(),
        ]);
    }

    /**
     * GET /admin/activity — recent timeline events + audit trail.
     */
    public function activity(Request $request): Response
    {
        $user = $this->viewer($request);

        return $this->view('admin/activity', [
            'title'  => 'Activity & audit',
            'user'   => $user,
            'events' => $this->service->activity(30),
            'audit'  => $this->service->auditLogs($request->queryAll()),
        ]);
    }

    /**
     * GET /admin/analytics — operational analytics dashboard (Phase 11).
     *
     * API.md §58: `period` may be day|week|month|year; anything else falls back
     * to the default so a mistyped link never errors out.
     */
    public function analytics(Request $request): Response
    {
        $user = $this->viewer($request);
        $period = (string) ($request->query('period') ?? '');

        $chartJs = (string) config('services.charts.chartjs_js', 'https://unpkg.com/chart.js@4.4.1/dist/chart.umd.js');

        try {
            $data = $this->analytics->overview($period);
        } catch (\Throwable) {
            flash('error', 'Analytics data is temporarily unavailable.');

            return $this->view('admin/analytics', [
                'title'   => 'Analytics',
                'user'    => $user,
                'data'    => $this->emptyAnalytics(),
                'scripts' => '<script src="' . e(asset('js/charts.js')) . '" defer></script>',
            ]);
        }

        return $this->view('admin/analytics', [
            'title'   => 'Analytics',
            'user'    => $user,
            'data'    => $data,
            'scripts' => '<script src="' . e($chartJs) . '" defer></script>'
                . '<script src="' . e(asset('js/charts.js')) . '" defer></script>',
        ]);
    }

    /**
     * POST /admin/requests/{id}/verify — verify an emergency request.
     */
    public function verifyRequest(Request $request, string $id): Response
    {
        return $this->mutate(
            $request,
            fn (array $user): array => $this->service->verifyRequest((int) $id, $user),
            'Request verified.',
            'admin/requests'
        );
    }

    /**
     * POST /admin/requests/{id}/escalate — escalate a request.
     */
    public function escalateRequest(Request $request, string $id): Response
    {
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        return $this->mutate(
            $request,
            fn (array $user): array => $this->service->escalateRequest((int) $id, $user, $reason),
            'Request escalated.',
            'admin/requests'
        );
    }

    /**
     * POST /admin/requests/{id}/status — apply a workflow transition.
     */
    public function changeRequestStatus(Request $request, string $id): Response
    {
        $status = (string) $request->input('status', '');
        $note = $request->input('note');
        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        return $this->mutate(
            $request,
            fn (array $user): array => $this->service->changeRequestStatus((int) $id, $user, $status, $note),
            'Request status updated.',
            'admin/requests'
        );
    }

    /**
     * Shared PRG wrapper for audited admin actions.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $action
     */
    private function mutate(Request $request, callable $action, string $success, string $redirect): Response
    {
        $user = $this->viewer($request);

        try {
            $action($user);
        } catch (AdminException | EmergencyException | VolunteerException | OrganizationException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url($redirect));
        } catch (\Throwable) {
            flash('error', 'The action could not be completed.');

            return $this->redirect(url($redirect));
        }

        flash('success', $success);

        return $this->redirect(url($redirect));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAnalytics(): array
    {
        return [
            'period'   => (string) config('services.charts.default_period', 'week'),
            'periods'  => AnalyticsService::PERIODS,
            'window'   => ['from' => null, 'to' => null, 'from_label' => '—'],
            'requests' => [
                'total'       => 0,
                'active'      => 0,
                'by_category' => [],
                'by_priority' => [],
                'by_status'   => [],
                'over_time'   => ['labels' => [], 'values' => [], 'total' => 0],
                'resolution'  => ['resolved' => 0, 'tracked' => 0, 'average_hours' => null, 'fastest_hours' => null, 'slowest_hours' => null],
            ],
            'resources' => [
                'stats'        => [],
                'by_type'      => [],
                'allocated'    => 0,
                'availability' => ['labels' => [], 'available' => [], 'total' => []],
            ],
            'volunteers' => ['available' => 0, 'pending' => 0, 'accepted' => 0, 'completed' => 0, 'rejected' => 0],
            'all_time'   => ['total' => 0, 'active' => 0, 'resolved' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDashboard(): array
    {
        return [
            'kpis'               => [],
            'by_status'          => [],
            'by_priority'        => [],
            'by_category'        => [],
            'critical_queue'     => [],
            'activity'           => [],
            'resources'          => [],
            'resource_shortages' => [],
        ];
    }
}
