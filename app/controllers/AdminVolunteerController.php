<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\VolunteerException;
use App\Services\VolunteerService;

/**
 * Admin volunteer verification queue (Phase 5).
 *
 * Admins review pending profiles and verify/reject them. Volunteers never
 * set their own verification status.
 */
final class AdminVolunteerController extends Controller
{
    private readonly VolunteerService $service;

    public function __construct()
    {
        $this->service = new VolunteerService();
    }

    /**
     * GET /admin/volunteers
     */
    public function index(Request $request): Response
    {
        $status = $request->query('verification_status');
        $status = is_string($status) && $status !== '' ? $status : null;

        try {
            $items = $this->service->adminList($status);
        } catch (VolunteerException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('admin/volunteers'));
        }

        return $this->view('admin/volunteers/index', [
            'title'   => 'Volunteer verification',
            'user'    => $this->viewer($request),
            'items'   => $items,
            'filter'  => $status,
        ]);
    }

    /**
     * POST /admin/volunteers/{id}/verify
     */
    public function verify(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $status = (string) $request->input('verification_status', 'VERIFIED');
        $status = $status !== '' ? $status : 'VERIFIED';

        try {
            $this->service->adminVerify((int) $id, $user, $status);
        } catch (VolunteerException $exception) {
            flash('error', $exception->userMessage());

            return $this->redirect(url('admin/volunteers'));
        }

        flash('success', 'Volunteer verification updated.');

        return $this->redirect(url('admin/volunteers'));
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
