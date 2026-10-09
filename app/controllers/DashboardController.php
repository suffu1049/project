<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * Role dashboards.
 *
 * Phase 3 delivers the protected landing for each role; feature panels are
 * added by later phases (emergency requests, volunteer, resources, admin).
 */
final class DashboardController extends Controller
{
    public function citizen(Request $request): Response
    {
        return $this->dashboard($request, 'CITIZEN', 'Citizen dashboard', [
            ['title' => 'Request help', 'body' => 'Create an emergency request with location details and an optional photo.', 'href' => url('requests/create'), 'action' => 'New request'],
            ['title' => 'Track requests', 'body' => 'See live status, priority reasons and the full request timeline.', 'href' => url('requests'), 'action' => 'My requests'],
        ]);
    }

    public function volunteer(Request $request): Response
    {
        return $this->dashboard($request, 'VOLUNTEER', 'Volunteer dashboard', [
            ['title' => 'My profile', 'body' => 'Skills, vehicle, equipment, service radius and verification status.', 'href' => url('volunteer/profile'), 'action' => 'Edit profile'],
            ['title' => 'Availability', 'body' => 'Toggle AVAILABLE / BUSY / OFFLINE so operations can see when you can help.', 'href' => url('volunteer/dashboard'), 'action' => 'Open dashboard'],
            ['title' => 'Nearby requests', 'body' => 'Open compatible emergencies within your service radius.', 'href' => url('volunteer/requests'), 'action' => 'View requests'],
        ]);
    }

    public function organization(Request $request): Response
    {
        return $this->dashboard($request, 'ORGANIZATION', 'Organization dashboard', [
            ['title' => 'Inventory', 'body' => 'Resource cards, quantities, availability and status for your organization.', 'href' => url('organization/dashboard'), 'action' => 'Open dashboard'],
            ['title' => 'Profile & verification', 'body' => 'Organization type, contact details, location and verification status.', 'href' => url('organization/profile'), 'action' => 'Edit profile'],
        ]);
    }

    private function dashboard(Request $request, string $role, string $heading, array $cards): Response
    {
        $user = $request->attribute('user') ?? auth_user();

        return $this->view('dashboard/index', [
            'title'   => $heading,
            'heading' => $heading,
            'role'    => $role,
            'user'    => $user,
            'cards'   => $cards,
        ]);
    }
}
