<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Service;
use App\Models\EmergencyRequestRepository;
use App\Models\MatchRepository;
use App\Models\OrganizationRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * Location, geo math and role-aware map markers (Phase 8).
 *
 * Privacy (ARCHITECTURE §25 / MASTER_PROMPT §20):
 *   - Exact private coordinates are never shown to unauthorized roles.
 *   - Citizens: own requests. Admins: operational map (all active work).
 *   - Volunteers: exact coords only after an accepted/completed match;
 *     otherwise approximate area markers (~2 dp).
 *   - Organizations: own org/resource coords exact; other verified orgs
 *     approximate. Volunteer home markers are admin-only.
 */
final class LocationService extends Service
{
    public const EARTH_RADIUS_KM = 6371.0;

    public const MARKER_EMERGENCY = 'EMERGENCY';
    public const MARKER_VOLUNTEER = 'VOLUNTEER';
    public const MARKER_RESOURCE  = 'RESOURCE';
    public const MARKER_ORGANIZATION = 'ORGANIZATION';

    public const PRECISION_EXACT = 'exact';
    public const PRECISION_APPROXIMATE = 'approximate';

    /** Decimals used when redacting coordinates (~1.1 km at the equator). */
    public const APPROX_DECIMALS = 2;

    public static function isValidLatitude(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= -90.0 && (float) $value <= 90.0;
    }

    public static function isValidLongitude(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= -180.0 && (float) $value <= 180.0;
    }

    /**
     * Validate a coordinate pair. Returns an errors map, or null when valid.
     *
     * @return array<string, string>|null
     */
    public static function validatePair(mixed $lat, mixed $lng): ?array
    {
        $latEmpty = $lat === null || $lat === '';
        $lngEmpty = $lng === null || $lng === '';

        if ($latEmpty && $lngEmpty) {
            return null;
        }

        $errors = [];

        if ($latEmpty || $lngEmpty) {
            $errors['location'] = 'Latitude and longitude must be provided together.';
            return $errors;
        }

        if (!self::isValidLatitude($lat)) {
            $errors['latitude'] = 'Latitude must be between -90 and 90.';
        }

        if (!self::isValidLongitude($lng)) {
            $errors['longitude'] = 'Longitude must be between -180 and 180.';
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * Great-circle distance in kilometres.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $radians = static fn (float $deg): float => deg2rad($deg);

        $dLat = $radians($lat2 - $lat1);
        $dLng = $radians($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos($radians($lat1)) * cos($radians($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Round coordinates for approximate display.
     *
     * @return array{latitude: float|null, longitude: float|null}
     */
    public static function roundCoords(?float $lat, ?float $lng, int $decimals = self::APPROX_DECIMALS): array
    {
        return [
            'latitude'  => $lat === null ? null : round($lat, $decimals),
            'longitude' => $lng === null ? null : round($lng, $decimals),
        ];
    }

    /**
     * Role-aware exact-coordinate visibility for an emergency request.
     */
    public static function canSeeExactRequestCoords(array $request, array $viewer, bool $hasAcceptedMatch = false): bool
    {
        $role = (string) ($viewer['role'] ?? '');

        if ($role === 'ADMIN') {
            return true;
        }

        if ((int) ($request['user_id'] ?? 0) === (int) ($viewer['id'] ?? 0) && $role === 'CITIZEN') {
            return true;
        }

        // Volunteers unlock exact coords only after acceptance/completion.
        if ($role === 'VOLUNTEER' && $hasAcceptedMatch) {
            return true;
        }

        // Organizations unlock exact coords when they hold an accepted resource match.
        if ($role === 'ORGANIZATION' && $hasAcceptedMatch) {
            return true;
        }

        return false;
    }

    /**
     * Build role-filtered map markers for the operational map (Phase 8).
     *
     * @param array<string, mixed> $viewer session user
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function markersForViewer(array $viewer): array
    {
        $role = (string) ($viewer['role'] ?? '');
        $userId = (int) ($viewer['id'] ?? 0);
        $items = [];
        $counts = [
            'EMERGENCY'     => 0,
            'VOLUNTEER'     => 0,
            'RESOURCE'      => 0,
            'ORGANIZATION'  => 0,
        ];

        if ($role === '') {
            return [
                'items' => [],
                'meta'  => [
                    'role'                   => 'GUEST',
                    'counts'                 => $counts,
                    'provider'               => (string) Config::get('services.maps.provider', 'openstreetmap'),
                    'poll_interval_seconds'  => (int) Config::get('services.maps.poll_interval_seconds', 12),
                    'precision_note'         => 'Sign in to view operational map markers.',
                ],
            ];
        }

        $items = array_merge(
            $items,
            $this->emergencyMarkers($viewer, $role, $userId, $counts),
            $this->volunteerMarkers($role, $counts),
            $this->resourceMarkers($viewer, $role, $userId, $counts),
            $this->organizationMarkers($viewer, $role, $userId, $counts)
        );

        return [
            'items' => $items,
            'meta'  => [
                'role'                   => $role,
                'counts'                 => $counts,
                'total'                  => count($items),
                'provider'               => (string) Config::get('services.maps.provider', 'openstreetmap'),
                'poll_interval_seconds'  => (int) Config::get('services.maps.poll_interval_seconds', 12),
                'precision_note'         => 'Approximate markers are rounded to ~1 km for privacy.',
            ],
        ];
    }

    /**
     * Single emergency request marker for detail-page maps.
     *
     * @return array<string, mixed>|null
     */
    public function requestMarker(array $request, array $viewer, bool $hasAcceptedMatch = false): ?array
    {
        if ($request['latitude'] === null || $request['longitude'] === null) {
            return null;
        }

        $exact = self::canSeeExactRequestCoords($request, $viewer, $hasAcceptedMatch);
        $lat = (float) $request['latitude'];
        $lng = (float) $request['longitude'];
        $rounded = self::roundCoords($lat, $lng);

        return [
            'id'          => 'emergency-' . (int) $request['id'],
            'type'        => self::MARKER_EMERGENCY,
            'label'       => sprintf('%s emergency #%d', (string) $request['category'], (int) $request['id']),
            'description' => mb_substr((string) $request['description'], 0, 140),
            'latitude'    => $exact ? $lat : (float) $rounded['latitude'],
            'longitude'   => $exact ? $lng : (float) $rounded['longitude'],
            'precision'   => $exact ? self::PRECISION_EXACT : self::PRECISION_APPROXIMATE,
            'priority'    => (string) ($request['priority'] ?? ''),
            'status'      => (string) ($request['status'] ?? ''),
            'icon'        => '!',
            'color'       => 'red',
            'href'        => url('requests/' . (int) $request['id']),
        ];
    }

    // ---------------------------------------------------------------------
    // Internal marker builders
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $viewer
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    private function emergencyMarkers(array $viewer, string $role, int $userId, array &$counts): array
    {
        if (!in_array($role, ['ADMIN', 'CITIZEN', 'VOLUNTEER', 'ORGANIZATION'], true)) {
            return [];
        }

        if ($role === 'ADMIN') {
            $requests = EmergencyRequestRepository::active(100);
        } elseif ($role === 'CITIZEN') {
            $requests = EmergencyRequestRepository::forUser($userId, 100);
        } else {
            $requests = $this->volunteerVisibleRequests($viewer, $role);
        }

        $markers = [];
        $seen = [];

        foreach ($requests as $request) {
            $id = (int) $request['id'];

            if (isset($seen[$id]) || $request['latitude'] === null || $request['longitude'] === null) {
                continue;
            }

            $status = (string) $request['status'];

            if (in_array($status, ['RESOLVED', 'CANCELLED'], true) && $role !== 'CITIZEN') {
                // Operational map focuses on active work; citizens keep history markers.
                if ($role === 'ADMIN') {
                    continue;
                }
            }

            $hasAccepted = $this->hasAcceptedMatchForViewer($request, $viewer);
            $marker = $this->requestMarker($request, $viewer, $hasAccepted);

            if ($marker === null) {
                continue;
            }

            $seen[$id] = true;
            $markers[] = $marker;
            $counts['EMERGENCY']++;
        }

        return $markers;
    }

    /**
     * Requests a volunteer may place on the map: accepted/completed (exact)
     * plus open eligible requests (approximate area).
     *
     * @param array<string, mixed> $viewer
     * @return array<int, array<string, mixed>>
     */
    private function volunteerVisibleRequests(array $viewer, string $role): array
    {
        $userId = (int) ($viewer['id'] ?? 0);
        $out = [];

        if ($role === 'VOLUNTEER') {
            $profile = VolunteerRepository::findByUserId($userId);

            if ($profile === null) {
                return [];
            }

            // Accepted / completed assignments → exact coordinates.
            foreach (MatchRepository::forVolunteer((int) $profile['id'], null, 100) as $match) {
                if (!in_array((string) $match['status'], ['ACCEPTED', 'COMPLETED'], true)) {
                    continue;
                }

                $request = EmergencyRequestRepository::find((int) $match['request_id']);

                if ($request !== null) {
                    $out[] = $request;
                }
            }

            // Open eligible requests → approximate area markers only.
            $service = new VolunteerService();

            try {
                $eligible = $service->eligibleRequests($viewer, []);
            } catch (\Throwable) {
                $eligible = ['items' => []];
            }

            foreach (($eligible['items'] ?? []) as $item) {
                $request = EmergencyRequestRepository::find((int) ($item['id'] ?? 0));

                if ($request !== null) {
                    $out[] = $request;
                }
            }

            return $out;
        }

        if ($role === 'ORGANIZATION') {
            $org = OrganizationRepository::findByUserId($userId);

            if ($org === null) {
                return [];
            }

            foreach (MatchRepository::forOrganizationResources((int) $org['id'], null, 100) as $match) {
                if (!in_array((string) $match['status'], ['ACCEPTED', 'COMPLETED'], true)) {
                    continue;
                }

                $request = EmergencyRequestRepository::find((int) $match['request_id']);

                if ($request !== null) {
                    $out[] = $request;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $viewer
     */
    private function hasAcceptedMatchForViewer(array $request, array $viewer): bool
    {
        $role = (string) ($viewer['role'] ?? '');
        $userId = (int) ($viewer['id'] ?? 0);
        $requestId = (int) $request['id'];

        if ($role === 'VOLUNTEER') {
            $profile = VolunteerRepository::findByUserId($userId);

            if ($profile === null) {
                return false;
            }

            $match = MatchRepository::openVolunteerMatch($requestId, (int) $profile['id']);

            if ($match !== null && in_array((string) $match['status'], ['ACCEPTED', 'COMPLETED'], true)) {
                return true;
            }

            foreach (MatchRepository::forVolunteer((int) $profile['id'], null, 100) as $row) {
                if ((int) $row['request_id'] === $requestId && in_array((string) $row['status'], ['ACCEPTED', 'COMPLETED'], true)) {
                    return true;
                }
            }

            return false;
        }

        if ($role === 'ORGANIZATION') {
            $org = OrganizationRepository::findByUserId($userId);

            if ($org === null) {
                return false;
            }

            foreach (MatchRepository::forOrganizationResources((int) $org['id'], null, 100) as $row) {
                if ((int) $row['request_id'] === $requestId && in_array((string) $row['status'], ['ACCEPTED', 'COMPLETED'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Available+verified volunteer markers — admin operational view only.
     *
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    private function volunteerMarkers(string $role, array &$counts): array
    {
        if ($role !== 'ADMIN') {
            return [];
        }

        $markers = [];

        foreach (VolunteerRepository::availableVerified(200) as $profile) {
            if ($profile['latitude'] === null || $profile['longitude'] === null) {
                continue;
            }

            $markers[] = [
                'id'          => 'volunteer-' . (int) $profile['id'],
                'type'        => self::MARKER_VOLUNTEER,
                'label'       => 'Volunteer: ' . (string) ($profile['name'] ?? 'Responder'),
                'description' => implode(', ', VolunteerService::skillTokens($profile['skills'] ?? null)),
                'latitude'    => (float) $profile['latitude'],
                'longitude'   => (float) $profile['longitude'],
                'precision'   => self::PRECISION_EXACT,
                'priority'    => '',
                'status'      => (string) ($profile['availability'] ?? ''),
                'icon'        => 'V',
                'color'       => 'green',
                'href'        => url('admin/volunteers'),
            ];
            $counts['VOLUNTEER']++;
        }

        return $markers;
    }

    /**
     * Resource markers. Owners/admins see exact coords; others get approximate.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    private function resourceMarkers(array $viewer, string $role, int $userId, array &$counts): array
    {
        if (!in_array($role, ['ADMIN', 'ORGANIZATION', 'CITIZEN', 'VOLUNTEER'], true)) {
            return [];
        }

        $resources = ResourceRepository::available(150);
        $ownOrgId = null;

        if ($role === 'ORGANIZATION') {
            $org = OrganizationRepository::findByUserId($userId);
            $ownOrgId = $org !== null ? (int) $org['id'] : null;
        }

        $markers = [];

        foreach ($resources as $resource) {
            if ($resource['latitude'] === null || $resource['longitude'] === null) {
                continue;
            }

            $orgId = (int) $resource['organization_id'];
            $org = OrganizationRepository::find($orgId);

            if ($org === null || (string) $org['verification_status'] !== 'VERIFIED') {
                continue;
            }

            // Public-facing map shows verified stock depots as approximate only.
            $exact = $role === 'ADMIN' || ($role === 'ORGANIZATION' && $ownOrgId === $orgId);
            $lat = (float) $resource['latitude'];
            $lng = (float) $resource['longitude'];
            $rounded = self::roundCoords($lat, $lng);
            $type = (string) $resource['resource_type'];

            $markers[] = [
                'id'          => 'resource-' . (int) $resource['id'],
                'type'        => self::MARKER_RESOURCE,
                'label'       => (string) $resource['resource_name'],
                'description' => sprintf(
                    '%s · %d available · %s',
                    $type,
                    (int) $resource['available_quantity'],
                    (string) $org['name']
                ),
                'latitude'    => $exact ? $lat : (float) $rounded['latitude'],
                'longitude'   => $exact ? $lng : (float) $rounded['longitude'],
                'precision'   => $exact ? self::PRECISION_EXACT : self::PRECISION_APPROXIMATE,
                'priority'    => '',
                'status'      => (string) $resource['status'],
                'icon'        => $this->resourceIcon($type),
                'color'       => $this->resourceColor($type),
                'href'        => $role === 'ORGANIZATION' && $ownOrgId === $orgId
                    ? url('organization/resources/' . (int) $resource['id'] . '/edit')
                    : null,
            ];
            $counts['RESOURCE']++;
        }

        return $markers;
    }

    /**
     * Organization markers for verified entities.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, int> $counts
     * @return array<int, array<string, mixed>>
     */
    private function organizationMarkers(array $viewer, string $role, int $userId, array &$counts): array
    {
        if (!in_array($role, ['ADMIN', 'ORGANIZATION', 'CITIZEN', 'VOLUNTEER'], true)) {
            return [];
        }

        $orgs = OrganizationRepository::byVerificationStatus('VERIFIED', 50);
        $ownOrgId = null;

        if ($role === 'ORGANIZATION') {
            $own = OrganizationRepository::findByUserId($userId);
            $ownOrgId = $own !== null ? (int) $own['id'] : null;
        }

        // Ensure the caller's own org appears even if still pending verification.
        if ($role === 'ORGANIZATION' && $ownOrgId !== null) {
            $own = OrganizationRepository::find($ownOrgId);
            $exists = false;

            foreach ($orgs as $org) {
                if ((int) $org['id'] === $ownOrgId) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists && $own !== null) {
                array_unshift($orgs, $own);
            }
        }

        $markers = [];

        foreach ($orgs as $org) {
            if ($org['latitude'] === null || $org['longitude'] === null) {
                continue;
            }

            $exact = $role === 'ADMIN' || ($role === 'ORGANIZATION' && (int) $org['id'] === $ownOrgId);
            $lat = (float) $org['latitude'];
            $lng = (float) $org['longitude'];
            $rounded = self::roundCoords($lat, $lng);

            $markers[] = [
                'id'          => 'org-' . (int) $org['id'],
                'type'        => self::MARKER_ORGANIZATION,
                'label'       => (string) $org['name'],
                'description' => sprintf(
                    '%s · %s',
                    (string) ($org['type'] ?? 'Organization'),
                    (string) $org['verification_status']
                ),
                'latitude'    => $exact ? $lat : (float) $rounded['latitude'],
                'longitude'   => $exact ? $lng : (float) $rounded['longitude'],
                'precision'   => $exact ? self::PRECISION_EXACT : self::PRECISION_APPROXIMATE,
                'priority'    => '',
                'status'      => (string) $org['verification_status'],
                'icon'        => 'O',
                'color'       => 'purple',
                'href'        => $role === 'ADMIN' ? url('admin/organizations') : null,
            ];
            $counts['ORGANIZATION']++;
        }

        return $markers;
    }

    private function resourceIcon(string $type): string
    {
        return match ($type) {
            'MEDICAL_SUPPORT', 'MEDICAL' => '+',
            'SHELTER', 'FOOD', 'WATER', 'FOOD_WATER' => 'S',
            default => 'R',
        };
    }

    private function resourceColor(string $type): string
    {
        return match ($type) {
            'MEDICAL_SUPPORT', 'MEDICAL' => 'blue',
            'SHELTER', 'FOOD', 'WATER', 'FOOD_WATER' => 'yellow',
            default => 'blue',
        };
    }
}
