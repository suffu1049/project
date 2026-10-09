<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\VolunteerException;
use App\Services\VolunteerService;

/**
 * JSON volunteer endpoints (API.md §25–§30, §59).
 *
 * Volunteer profile, availability, eligible request list, admin operational
 * read and admin verification. CSRF via `GET /api/v1/csrf` → `X-CSRF-Token`
 * on state-changing calls.
 */
final class VolunteerApiController extends Controller
{
    private readonly VolunteerService $service;

    public function __construct()
    {
        $this->service = new VolunteerService();
    }

    /**
     * GET /api/v1/volunteers/profile
     */
    public function profile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $profile = $this->service->profileFor((int) $user['id'], $user);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json(VolunteerService::presentOwn($profile), 'Volunteer profile retrieved.');
    }

    /**
     * PATCH /api/v1/volunteers/profile
     */
    public function updateProfile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $data = $this->service->validateProfileUpdate($request->all());
            $updated = $this->service->updateProfile($user, $data);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json(VolunteerService::presentOwn($updated), 'Volunteer profile updated.');
    }

    /**
     * POST /api/v1/volunteers/availability
     */
    public function availability(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $data = $this->service->validateAvailability($request->all());
            $updated = $this->service->setAvailability($user, (string) $data['availability']);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json(
            ['availability' => (string) $updated['availability']],
            'Availability updated.'
        );
    }

    /**
     * GET /api/v1/volunteers/requests
     */
    public function requests(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->eligibleRequests($user, [
                'category' => $request->query('category'),
                'priority' => $request->query('priority'),
                'radius'   => $request->query('radius'),
            ]);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json($result, 'Volunteer requests retrieved.');
    }

    /**
     * GET /api/v1/volunteers/{id} — admin operational read (no phone/email).
     */
    public function show(Request $request, string $id): Response
    {
        try {
            $profile = $this->service->adminShow((int) $id);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json($profile, 'Volunteer retrieved.');
    }

    /**
     * GET /api/v1/admin/volunteers — admin list.
     */
    public function adminIndex(Request $request): Response
    {
        $status = $request->query('verification_status');

        try {
            $items = $this->service->adminList(is_string($status) ? $status : null);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json(['items' => $items], 'Volunteers retrieved.');
    }

    /**
     * POST /api/v1/admin/volunteers/{id}/verify — admin verification.
     */
    public function adminVerify(Request $request, string $id): Response
    {
        $user = $this->viewer($request);
        $status = $request->input('verification_status', 'VERIFIED');
        $status = is_string($status) && $status !== '' ? $status : 'VERIFIED';

        try {
            $profile = $this->service->adminVerify((int) $id, $user, $status);
        } catch (VolunteerException $exception) {
            return $this->failed($exception);
        }

        return $this->json([
            'volunteer_id'        => (int) $profile['id'],
            'verification_status' => (string) $profile['verification_status'],
        ], 'Volunteer verified.');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function failed(VolunteerException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}
