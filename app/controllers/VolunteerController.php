<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\VolunteerException;
use App\Services\VolunteerService;

/**
 * Browser volunteer workflows (Phase 5).
 *
 * Dashboard, profile editing, availability toggles and the nearby-request
 * list. Business rules live in VolunteerService; forms follow PRG.
 */
final class VolunteerController extends Controller
{
    private readonly VolunteerService $service;

    public function __construct()
    {
        $this->service = new VolunteerService();
    }

    /**
     * GET /volunteer/dashboard — profile summary + availability + nearby count.
     */
    public function dashboard(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $profile = $this->service->profileFor((int) $user['id'], $user);
            $eligible = $this->service->eligibleRequests($user);
        } catch (VolunteerException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('volunteer/dashboard', [
            'title'    => 'Volunteer dashboard',
            'user'     => $user,
            'profile'  => $profile,
            'eligible' => $eligible,
            'skills'   => VolunteerService::skillTokens($profile['skills'] ?? null),
            'equipment'=> VolunteerService::tokenList($profile['equipment'] ?? null),
        ]);
    }

    /**
     * GET /volunteer/profile — profile edit form.
     */
    public function profile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $profile = $this->service->profileFor((int) $user['id'], $user);
        } catch (VolunteerException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('volunteer/profile', [
            'title'     => 'Volunteer profile',
            'user'      => $user,
            'profile'   => $profile,
            'skills'    => VolunteerService::skillTokens($profile['skills'] ?? null),
            'equipment' => VolunteerService::tokenList($profile['equipment'] ?? null),
            'errors'    => pull_flash('form_errors') ?? [],
        ]);
    }

    /**
     * POST /volunteer/profile — save profile fields.
     */
    public function updateProfile(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $data = $this->service->validateProfileUpdate($request->all());
            // Web form always submits availability + radius; require them on
            // first-class profile save when present as empty strings.
            if (array_key_exists('availability', $data) && $data['availability'] === null) {
                $data['availability'] = null;
            }

            $this->service->updateProfile($user, $data);
        } catch (VolunteerException $exception) {
            if ($exception->errorCode === 'VALIDATION_ERROR' || $exception->errorCode === 'INVALID_VOLUNTEER_SKILLS') {
                flash('form_errors', $exception->errors !== [] ? $exception->errors : ['skills' => [$exception->userMessage()]]);
                flash('_old_input', $request->all());
            } else {
                flash('error', $exception->userMessage());
            }

            return $this->redirect(url('volunteer/profile'));
        }

        pull_flash('_old_input');
        flash('success', 'Volunteer profile updated.');

        return $this->redirect(url('volunteer/profile'));
    }

    /**
     * POST /volunteer/availability — toggle AVAILABLE / BUSY / OFFLINE.
     */
    public function availability(Request $request): Response
    {
        $user = $this->viewer($request);
        $availability = (string) $request->input('availability', '');

        try {
            $data = $this->service->validateAvailability(['availability' => $availability]);
            $this->service->setAvailability($user, (string) $data['availability']);
        } catch (VolunteerException $exception) {
            if ($exception->errorCode === 'VALIDATION_ERROR') {
                flash('form_errors', $exception->errors);
                flash('_old_input', $request->all());
            } else {
                flash('error', $exception->userMessage());
            }

            return $this->redirect(url('volunteer/dashboard'));
        }

        flash('success', 'Availability updated to ' . $availability . '.');

        return $this->redirect(url('volunteer/dashboard'));
    }

    /**
     * GET /volunteer/requests — open compatible requests nearby.
     */
    public function requests(Request $request): Response
    {
        $user = $this->viewer($request);

        try {
            $result = $this->service->eligibleRequests($user, [
                'category' => $request->query('category'),
                'priority' => $request->query('priority'),
            ]);
        } catch (VolunteerException $exception) {
            return $this->error($request, $exception);
        }

        return $this->view('volunteer/requests', [
            'title'    => 'Nearby requests',
            'user'     => $user,
            'items'    => $result['items'],
            'meta'     => $result['meta'],
            'errors'   => pull_flash('form_errors') ?? [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewer(Request $request): array
    {
        $user = $request->attribute('user') ?? auth_user();

        return is_array($user) ? $user : [];
    }

    private function error(Request $request, VolunteerException $exception): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
        }

        if ($exception->status === 404) {
            return $this->view('errors/404', [
                'status'  => 404,
                'message' => 'Volunteer profile not found.',
            ], 'layouts/app', 404);
        }

        if ($exception->status === 403) {
            return $this->view('errors/403', [
                'status'  => 403,
                'message' => $exception->userMessage(),
            ], 'layouts/app', 403);
        }

        flash('error', $exception->userMessage());

        return $this->redirect(url('volunteer/dashboard'));
    }
}
