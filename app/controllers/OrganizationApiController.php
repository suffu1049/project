<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\OrganizationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrganizationService;

/**
 * JSON organization endpoints (API.md §37–§38, §58).
 *
 * Own-profile read/update plus the admin verification queue. CSRF via
 * `GET /api/v1/csrf` → `X-CSRF-Token` on state-changing calls. Admin reads
 * never expose account email or phone.
 */
final class OrganizationApiController extends Controller
{
    private readonly OrganizationService $service;

    public function __construct()
    {
        $this->service = new OrganizationService();
    }

    /**
     * GET /api/v1/organizations/profile
     */
    public function profile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $profile = $this->service->profileFor((int) $user['id'], $user);
        } catch (OrganizationException $exception) {
            return $this->failed($exception);
        }

        return $this->json(OrganizationService::presentOwn($profile), 'Organization profile retrieved.');
    }

    /**
     * PATCH /api/v1/organizations/profile
     */
    public function updateProfile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $updated = $this->service->updateProfile($user, $request->all());
        } catch (OrganizationException $exception) {
            return $this->failed($exception);
        }

        return $this->json(OrganizationService::presentOwn($updated), 'Organization profile updated.');
    }

    /**
     * GET /api/v1/admin/organizations — admin list.
     */
    public function adminIndex(Request $request): Response
    {
        $status = $request->query('verification_status');

        try {
            $items = $this->service->adminList(is_string($status) && $status !== '' ? $status : null);
        } catch (OrganizationException $exception) {
            return $this->failed($exception);
        }

        return $this->json(['items' => $items], 'Organizations retrieved.');
    }

    /**
     * POST /api/v1/admin/organizations/{id}/verify — admin verification.
     */
    public function adminVerify(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $status = $request->input('verification_status', 'VERIFIED');
        $status = is_string($status) && $status !== '' ? $status : 'VERIFIED';

        try {
            $organization = $this->service->adminVerify((int) $id, $user, $status);
        } catch (OrganizationException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'organization_id'      => (int) $organization['id'],
            'verification_status'  => (string) $organization['verification_status'],
        ], 'Organization verified.');
    }

    /**
     * POST /api/v1/admin/organizations/{id}/reject — admin rejection (§58).
     */
    public function adminReject(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        try {
            $organization = $this->service->adminVerify((int) $id, $user, 'REJECTED', $reason);
        } catch (OrganizationException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'organization_id'      => (int) $organization['id'],
            'verification_status'  => (string) $organization['verification_status'],
        ], 'Organization rejected.');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function failed(OrganizationException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}