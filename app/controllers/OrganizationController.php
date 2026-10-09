<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\OrganizationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResourceException;
use App\Services\OrganizationService;
use App\Services\ResourceService;

/**
 * Browser organization workflows (Phase 6).
 *
 * Dashboard (verification + inventory overview + allocation actions) and
 * profile editing. Business rules live in OrganizationService/ResourceService;
 * forms follow PRG.
 */
final class OrganizationController extends Controller
{
    private readonly OrganizationService $organizations;
    private readonly ResourceService $resources;

    public function __construct()
    {
        $this->organizations = new OrganizationService();
        $this->resources = new ResourceService();
    }

    /**
     * GET /organization/dashboard — profile, stats, inventory, allocations.
     */
    public function dashboard(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $profile = $this->organizations->profileFor((int) $user['id'], $user);
            $stats = $this->organizations->inventoryStats((int) $profile['id']);
            $inventory = $this->resources->list($user, [
                'page'  => $request->query('page'),
                'limit' => 50,
            ]);
            $targets = (string) $profile['verification_status'] === 'VERIFIED'
                ? $this->resources->allocationTargets($user)
                : [];
        } catch (OrganizationException|ResourceException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('organization/dashboard', [
            'title'    => 'Organization dashboard',
            'user'     => $user,
            'profile'  => $profile,
            'stats'    => $stats,
            'items'    => $inventory['items'],
            'pagination' => $inventory['pagination'],
            'targets'  => $targets,
            'types'    => ResourceService::TYPES,
            'errors'   => pull_flash('form_errors') ?? [],
        ]);
    }

    /**
     * GET /organization/profile — profile edit form.
     */
    public function profile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $profile = $this->organizations->profileFor((int) $user['id'], $user);
        } catch (OrganizationException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('organization/profile', [
            'title'    => 'Organization profile',
            'user'     => $user,
            'profile'  => $profile,
            'types'    => OrganizationService::TYPES,
            'errors'   => pull_flash('form_errors') ?? [],
        ]);
    }

    /**
     * POST /organization/profile — save profile fields.
     */
    public function updateProfile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $this->organizations->updateProfile($user, $request->all());
        } catch (OrganizationException $exception) {
            if ($exception->errorCode === 'VALIDATION_ERROR') {
                flash('form_errors', $exception->errors !== [] ? $exception->errors : ['name' => [$exception->userMessage()]]);
                flash('_old_input', $request->all());
            } else {
                flash('error', $exception->userMessage());
            }

            return $this->redirect(url('organization/profile'));
        }

        pull_flash('_old_input');
        flash('success', 'Organization profile updated.');

        return $this->redirect(url('organization/profile'));
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
     * Shared transport mapping: JSON clients get the error envelope, browser
     * clients get safe 403/404 pages or a flash + redirect.
     */
    private function error(Request $request, OrganizationException|ResourceException $exception): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
        }

        if ($exception->status === 404) {
            return $this->view('errors/404', [
                'status'  => 404,
                'message' => 'Organization profile not found.',
            ], 'layouts/app', 404);
        }

        if ($exception->status === 403) {
            return $this->view('errors/403', [
                'status'  => 403,
                'message' => $exception->userMessage(),
            ], 'layouts/app', 403);
        }

        flash('error', $exception->userMessage());

        return $this->redirect(url('organization/dashboard'));
    }
}