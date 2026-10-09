<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\OrganizationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrganizationService;

/**
 * Admin organization verification queue (Phase 6).
 *
 * Admins review pending organizations and verify/reject them. Organizations
 * never set their own verification status.
 */
final class AdminOrganizationController extends Controller
{
    private readonly OrganizationService $service;

    public function __construct()
    {
        $this->service = new OrganizationService();
    }

    /**
     * GET /admin/organizations
     */
    public function index(Request $request): Response
    {
        $status = $request->query('verification_status');
        $status = is_string($status) && $status !== '' ? $status : null;

        try {
            $items = $this->service->adminList($status);
        } catch (OrganizationException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('admin/organizations'));
        }

        return $this->view('admin/organizations/index', [
            'title'  => 'Organization verification',
            'user'   => $this->viewer($request),
            'items'  => $items,
            'filter' => $status,
        ]);
    }

    /**
     * POST /admin/organizations/{id}/verify
     *
     * `verification_status` may be VERIFIED (default) or REJECTED; an optional
     * `reason` is stored in the audit trail for rejections.
     */
    public function verify(Request $request, string $id): Response
    {
        $user = $this->viewer($request);

        $status = (string) $request->input('verification_status', 'VERIFIED');
        $status = $status !== '' ? $status : 'VERIFIED';
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $this->service->adminVerify((int) $id, $user, $status, $reason);
        } catch (OrganizationException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('admin/organizations'));
        }

        flash('success', 'Organization verification updated.');

        return $this->redirect(url('admin/organizations'));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }
}