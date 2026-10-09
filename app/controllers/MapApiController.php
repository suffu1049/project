<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\LocationService;

/**
 * JSON map marker feed (Phase 8 polling).
 *
 * GET /api/v1/map/markers — role-filtered markers with precision metadata.
 */
final class MapApiController extends Controller
{
    private readonly LocationService $service;

    public function __construct()
    {
        $this->service = new LocationService();
    }

    /**
     * GET /api/v1/map/markers
     */
    public function markers(Request $request): Response
    {
        $user = $this->viewer($request);

        if ($user === []) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        $result = $this->service->markersForViewer($user);

        return $this->json($result, 'Map markers retrieved.');
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
