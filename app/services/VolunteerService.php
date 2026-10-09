<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Service;
use App\Core\VolunteerException;
use App\Models\EmergencyRequestRepository;
use App\Models\VolunteerRepository;

/**
 * Volunteer profile, availability and eligibility (Phase 5).
 *
 * Business rules live here — controllers stay thin and repositories stay pure
 * persistence.
 *
 * Rules:
 *   - Only the volunteer (or an admin) may edit a profile.
 *   - Skills must be from the controlled vocabulary.
 *   - Service radius is validated (0.1–1000 km) and used for nearby requests.
 *   - Latitude/longitude must be provided together when either is set.
 *   - Unavailable volunteers are never treated as available.
 *   - Only VERIFIED volunteers see eligible request listings.
 *   - Admin verifies or rejects volunteers; verification is never client-set.
 */
final class VolunteerService extends Service
{
    /** Controlled skill vocabulary (Phase 5 capability model). */
    public const SKILLS = [
        'MEDICAL',
        'TRANSPORT',
        'FOOD_DISTRIBUTION',
        'SEARCH_SUPPORT',
        'FIRST_AID',
        'GENERAL',
        'DRIVING',
    ];

    public const VEHICLES = ['NONE', 'CAR', 'VAN', 'BIKE', 'AMBULANCE', 'TRUCK'];

    public const AVAILABILITY = ['AVAILABLE', 'BUSY', 'OFFLINE'];

    public const VERIFICATION_STATUSES = ['PENDING', 'VERIFIED', 'REJECTED'];

    /** Request statuses a volunteer may discover (open work). */
    public const OPEN_STATUSES = ['CREATED', 'VERIFIED', 'MATCHING', 'ESCALATED'];

    /** Skill tokens → emergency categories they can help with. */
    public const SKILL_CATEGORIES = [
        'MEDICAL'           => ['MEDICAL', 'ACCIDENT'],
        'FIRST_AID'         => ['MEDICAL', 'ACCIDENT'],
        'TRANSPORT'         => ['TRANSPORT', 'FLOOD', 'MISSING_STRANDED', 'FIRE'],
        'DRIVING'           => ['TRANSPORT', 'FLOOD', 'MISSING_STRANDED'],
        'FOOD_DISTRIBUTION' => ['FOOD_WATER', 'SHELTER'],
        'SEARCH_SUPPORT'    => ['MISSING_STRANDED', 'FLOOD'],
        'GENERAL'           => [
            'OTHER', 'SHELTER', 'FIRE', 'ACCIDENT', 'FLOOD',
            'FOOD_WATER', 'TRANSPORT', 'MISSING_STRANDED', 'MEDICAL',
        ],
    ];

    /** Fields a volunteer may edit on their own profile. */
    private const PROFILE_FIELDS = [
        'skills',
        'vehicle',
        'equipment',
        'availability',
        'service_radius_km',
        'latitude',
        'longitude',
    ];

    /**
     * Fetch (creating if needed) the profile for a volunteer user.
     *
     * @return array<string, mixed>
     */
    public function profileFor(int $userId, array $viewer): array
    {
        $this->assertVolunteerRole($viewer);

        $profile = VolunteerRepository::findByUserId($userId);

        if ($profile === null) {
            // Registration normally creates the row; this covers legacy accounts.
            $profileId = VolunteerRepository::create([
                'user_id'             => $userId,
                'skills'              => null,
                'vehicle'             => null,
                'equipment'           => null,
                'availability'        => 'OFFLINE',
                'service_radius_km'   => 10.00,
                'latitude'            => null,
                'longitude'           => null,
                'verification_status' => 'PENDING',
            ]);

            $profile = VolunteerRepository::find($profileId);

            if ($profile === null) {
                throw VolunteerException::notFound();
            }
        }

        return $profile;
    }

    /**
     * Update the caller's own volunteer profile (partial update semantics).
     *
     * @param array<string, mixed> $data validated subset of PROFILE_FIELDS
     * @return array<string, mixed>
     */
    public function updateProfile(array $viewer, array $data): array
    {
        $userId = (int) ($viewer['id'] ?? 0);
        $profile = $this->profileFor($userId, $viewer);

        // Only the volunteer may edit their own profile (admins use verify).
        if ((int) ($profile['user_id'] ?? 0) !== $userId) {
            throw VolunteerException::forbidden();
        }

        $attributes = $this->normalizeProfileAttributes($data, $profile);

        if ($attributes === []) {
            return $profile;
        }

        VolunteerRepository::update((int) $profile['id'], $attributes);

        $updated = VolunteerRepository::find((int) $profile['id']);

        if ($updated === null) {
            throw VolunteerException::notFound();
        }

        $this->log('info', 'Volunteer profile updated.', [
            'profile_id' => (int) $updated['id'],
            'fields'     => array_keys($attributes),
        ]);

        return $updated;
    }

    /**
     * Toggle availability for the caller's own profile.
     *
     * @return array<string, mixed>
     */
    public function setAvailability(array $viewer, string $availability): array
    {
        $availability = strtoupper(trim($availability));

        if (!in_array($availability, self::AVAILABILITY, true)) {
            throw VolunteerException::validation(
                ['availability' => ['Availability must be one of: ' . implode(', ', self::AVAILABILITY) . '.']],
                'Validation failed.'
            );
        }

        $userId = (int) ($viewer['id'] ?? 0);
        $profile = $this->profileFor($userId, $viewer);

        VolunteerRepository::updateAvailability((int) $profile['id'], $availability);

        $updated = VolunteerRepository::find((int) $profile['id']);

        if ($updated === null) {
            throw VolunteerException::notFound();
        }

        return $updated;
    }

    /**
     * Open emergency requests eligible for this volunteer.
     *
     * Availability and verification gate the listing: unavailable or
     * unverified volunteers are never treated as available. Distance is
     * applied when both sides have coordinates.
     *
     * @param array<string, mixed> $query optional filters: category, priority, radius
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function eligibleRequests(array $viewer, array $query = []): array
    {
        $userId = (int) ($viewer['id'] ?? 0);
        $profile = $this->profileFor($userId, $viewer);

        $availability = (string) ($profile['availability'] ?? 'OFFLINE');
        $verification = (string) ($profile['verification_status'] ?? 'PENDING');

        if ($verification !== 'VERIFIED') {
            return [
                'items' => [],
                'meta'  => [
                    'available'       => false,
                    'eligible'        => false,
                    'reason'          => 'NOT_VERIFIED',
                    'availability'    => $availability,
                    'verification'    => $verification,
                    'service_radius_km' => (float) ($profile['service_radius_km'] ?? 0),
                    'filters'         => $this->normalizeQuery($query),
                ],
            ];
        }

        if ($availability !== 'AVAILABLE') {
            return [
                'items' => [],
                'meta'  => [
                    'available'       => false,
                    'eligible'        => false,
                    'reason'          => 'NOT_AVAILABLE',
                    'availability'    => $availability,
                    'verification'    => $verification,
                    'service_radius_km' => (float) ($profile['service_radius_km'] ?? 0),
                    'filters'         => $this->normalizeQuery($query),
                ],
            ];
        }

        $filters = $this->normalizeQuery($query);
        $volunteerLat = $profile['latitude'] !== null ? (float) $profile['latitude'] : null;
        $volunteerLng = $profile['longitude'] !== null ? (float) $profile['longitude'] : null;
        $radiusKm = $filters['radius'] ?? ($profile['service_radius_km'] !== null ? (float) $profile['service_radius_km'] : null);

        $requests = EmergencyRequestRepository::active(200);
        $skills = self::skillTokens($profile['skills'] ?? null);

        $items = [];

        foreach ($requests as $request) {
            $category = (string) ($request['category'] ?? '');
            $status = (string) ($request['status'] ?? '');

            if (!in_array($status, self::OPEN_STATUSES, true)) {
                continue;
            }

            if ($filters['category'] !== null && $category !== $filters['category']) {
                continue;
            }

            if ($filters['priority'] !== null && (string) ($request['priority'] ?? '') !== $filters['priority']) {
                continue;
            }

            if (!$this->capabilityMatches($skills, $category)) {
                continue;
            }

            $distance = null;
            $requestLat = $request['latitude'] !== null ? (float) $request['latitude'] : null;
            $requestLng = $request['longitude'] !== null ? (float) $request['longitude'] : null;

            if ($volunteerLat !== null && $volunteerLng !== null && $requestLat !== null && $requestLng !== null) {
                $distance = self::distanceKm($volunteerLat, $volunteerLng, $requestLat, $requestLng);

                if ($radiusKm !== null && $distance > $radiusKm) {
                    continue;
                }
            }

            $items[] = $this->presentRequest($request, $distance);
        }

        // Nearest first when distance is known; otherwise priority order.
        usort($items, static function (array $a, array $b): int {
            $da = $a['distance_km'];
            $db = $b['distance_km'];

            if ($da !== null && $db !== null) {
                return $da <=> $db;
            }

            if ($da !== null && $db === null) {
                return -1;
            }

            if ($da === null && $db !== null) {
                return 1;
            }

            $priorityRank = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
            $pa = $priorityRank[(string) $a['priority']] ?? 9;
            $pb = $priorityRank[(string) $b['priority']] ?? 9;

            return $pa <=> $pb;
        });

        return [
            'items' => $items,
            'meta'  => [
                'available'         => true,
                'eligible'          => true,
                'reason'            => null,
                'availability'      => $availability,
                'verification'      => $verification,
                'service_radius_km' => $radiusKm,
                'has_location'      => $volunteerLat !== null && $volunteerLng !== null,
                'filters'           => $filters,
                'count'             => count($items),
            ],
        ];
    }

    /**
     * Admin: list volunteers (optional verification filter).
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminList(?string $verificationStatus = null): array
    {
        if ($verificationStatus !== null && $verificationStatus !== '' && !in_array($verificationStatus, self::VERIFICATION_STATUSES, true)) {
            throw VolunteerException::validation(
                ['verification_status' => ['Verification status must be one of: ' . implode(', ', self::VERIFICATION_STATUSES) . '.']],
                'Validation failed.'
            );
        }

        $rows = VolunteerRepository::allWithUsers($verificationStatus);

        return array_map(static fn (array $row): array => self::presentAdmin($row), $rows);
    }

    /**
     * Admin: restricted operational view of one volunteer (no phone/email).
     *
     * @return array<string, mixed>
     */
    public function adminShow(int $profileId): array
    {
        $row = VolunteerRepository::findWithUser($profileId);

        if ($row === null) {
            throw VolunteerException::notFound();
        }

        return self::presentAdmin($row);
    }

    /**
     * Admin: verify or reject a volunteer profile.
     *
     * @return array<string, mixed>
     */
    public function adminVerify(int $profileId, array $admin, string $verificationStatus): array
    {
        $this->assertAdmin($admin);

        $verificationStatus = strtoupper(trim($verificationStatus));

        if (!in_array($verificationStatus, ['VERIFIED', 'REJECTED'], true)) {
            throw VolunteerException::validation(
                ['verification_status' => ['Verification status must be VERIFIED or REJECTED.']],
                'Validation failed.'
            );
        }

        $row = VolunteerRepository::findWithUser($profileId);

        if ($row === null) {
            throw VolunteerException::notFound();
        }

        VolunteerRepository::updateVerificationStatus($profileId, $verificationStatus);

        $updated = VolunteerRepository::findWithUser($profileId);

        if ($updated === null) {
            throw VolunteerException::notFound();
        }

        \App\Models\AuditRepository::record(
            (int) ($admin['id'] ?? 0),
            $verificationStatus === 'VERIFIED' ? 'VERIFY_VOLUNTEER' : 'REJECT_VOLUNTEER',
            'volunteer_profile',
            $profileId,
            [
                'from'   => (string) ($row['verification_status'] ?? 'PENDING'),
                'to'     => $verificationStatus,
                'actor'  => (string) ($admin['role'] ?? ''),
            ]
        );

        $this->log('info', 'Volunteer verification updated.', [
            'profile_id'          => $profileId,
            'verification_status' => $verificationStatus,
            'actor_id'            => (int) ($admin['id'] ?? 0),
        ]);

        return self::presentAdmin($updated);
    }

    /**
     * Validate a profile payload using shared rules + skill vocabulary.
     *
     * Only fields present in the input are validated (partial updates).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateProfileUpdate(array $data): array
    {
        // HTML multi-selects post `skills[]` / `equipment[]` as arrays.
        if (isset($data['skills']) && is_array($data['skills'])) {
            $data['skills'] = implode(',', $data['skills']);
        }

        if (isset($data['equipment']) && is_array($data['equipment'])) {
            $data['equipment'] = implode(',', $data['equipment']);
        }

        $allRules = (array) Config::get('validation.volunteer_profile.rules', []);
        $labels = (array) Config::get('validation.volunteer_profile.labels', []);

        $rules = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data) && array_key_exists($field, $allRules)) {
                $rules[$field] = $allRules[$field];
            }
        }

        // availability and radius are optional on partial PATCH; full web form
        // still submits them so they validate against the same bounds.
        if (array_key_exists('availability', $rules)) {
            $rules['availability'] = 'nullable|in:' . implode(',', self::AVAILABILITY);
        }

        if ($rules === []) {
            return [];
        }

        $validator = validator($data, $rules, $labels);

        if ($validator->fails()) {
            throw VolunteerException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Validate an availability-only payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateAvailability(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.volunteer_availability.rules', []),
            (array) Config::get('validation.volunteer_availability.labels', [])
        );

        if ($validator->fails()) {
            throw VolunteerException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * API presentation of the caller's own profile.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function presentOwn(array $profile): array
    {
        return [
            'id'                 => (int) ($profile['id'] ?? 0),
            'user_id'            => (int) ($profile['user_id'] ?? 0),
            'skills'             => self::skillTokens($profile['skills'] ?? null),
            'vehicle'            => $profile['vehicle'] !== null ? (string) $profile['vehicle'] : null,
            'equipment'          => self::tokenList($profile['equipment'] ?? null),
            'availability'       => (string) ($profile['availability'] ?? 'OFFLINE'),
            'service_radius_km'  => (float) ($profile['service_radius_km'] ?? 0),
            'verification_status'=> (string) ($profile['verification_status'] ?? 'PENDING'),
            'location'           => [
                'latitude'  => $profile['latitude'] !== null ? (float) $profile['latitude'] : null,
                'longitude' => $profile['longitude'] !== null ? (float) $profile['longitude'] : null,
            ],
            'has_location'       => $profile['latitude'] !== null && $profile['longitude'] !== null,
            'created_at'         => (string) ($profile['created_at'] ?? ''),
            'updated_at'         => (string) ($profile['updated_at'] ?? ''),
        ];
    }

    /**
     * API presentation for admin/operational reads — never phone/email.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentAdmin(array $row): array
    {
        return [
            'id'                  => (int) ($row['id'] ?? 0),
            'user_id'             => (int) ($row['user_id'] ?? 0),
            'name'                => (string) ($row['user_name'] ?? $row['name'] ?? ''),
            'skills'              => self::skillTokens($row['skills'] ?? null),
            'vehicle'             => $row['vehicle'] !== null ? (string) $row['vehicle'] : null,
            'equipment'           => self::tokenList($row['equipment'] ?? null),
            'availability'        => (string) ($row['availability'] ?? 'OFFLINE'),
            'service_radius_km'   => (float) ($row['service_radius_km'] ?? 0),
            'verification_status' => (string) ($row['verification_status'] ?? 'PENDING'),
            'user_status'         => (string) ($row['user_status'] ?? $row['status'] ?? ''),
            'has_location'        => ($row['latitude'] ?? null) !== null && ($row['longitude'] ?? null) !== null,
            'created_at'          => (string) ($row['created_at'] ?? ''),
            'updated_at'          => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Present an emergency request for a volunteer (no exact coordinates).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function presentRequest(array $request, ?float $distanceKm): array
    {
        return [
            'id'              => (int) ($request['id'] ?? 0),
            'category'        => (string) ($request['category'] ?? ''),
            'description'     => (string) ($request['description'] ?? ''),
            'people_affected' => (int) ($request['people_affected'] ?? 0),
            'priority'        => (string) ($request['priority'] ?? 'LOW'),
            'priority_score'  => (int) ($request['priority_score'] ?? 0),
            'status'          => (string) ($request['status'] ?? ''),
            'address_text'    => $request['address_text'] !== null ? (string) $request['address_text'] : null,
            'distance_km'     => $distanceKm !== null ? round($distanceKm, 2) : null,
            'created_at'      => (string) ($request['created_at'] ?? ''),
        ];
    }

    /**
     * Capability heuristic used by Phase 5 request visibility.
     *
     * Full scoring lives in MatchingService (Phase 7).
     *
     * @param array<int, string> $skills
     */
    public function capabilityMatches(array $skills, string $category): bool
    {
        if ($skills === []) {
            // No declared skills yet → general assistance only.
            return in_array($category, ['OTHER', 'SHELTER', 'ACCIDENT'], true);
        }

        if (in_array('GENERAL', $skills, true)) {
            return true;
        }

        foreach ($skills as $skill) {
            $categories = self::SKILL_CATEGORIES[$skill] ?? [];

            if (in_array($category, $categories, true)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Normalize and validate profile attribute writes.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function normalizeProfileAttributes(array $data, array $profile): array
    {
        $validated = $this->validateProfileUpdate($data);
        $attributes = [];

        if (array_key_exists('skills', $validated)) {
            $attributes['skills'] = self::normalizeSkillCsv($validated['skills']);
        }

        if (array_key_exists('vehicle', $validated)) {
            $attributes['vehicle'] = self::normalizeVehicle($validated['vehicle']);
        }

        if (array_key_exists('equipment', $validated)) {
            $attributes['equipment'] = self::normalizeEquipmentCsv($validated['equipment']);
        }

        if (array_key_exists('availability', $validated) && $validated['availability'] !== null) {
            $availability = strtoupper(trim((string) $validated['availability']));

            if (!in_array($availability, self::AVAILABILITY, true)) {
                throw VolunteerException::validation(
                    ['availability' => ['Availability must be one of: ' . implode(', ', self::AVAILABILITY) . '.']]
                );
            }

            $attributes['availability'] = $availability;
        }

        if (array_key_exists('service_radius_km', $validated) && $validated['service_radius_km'] !== null) {
            $radius = (float) $validated['service_radius_km'];

            if ($radius < 0.1 || $radius > 1000) {
                throw VolunteerException::validation(
                    ['service_radius_km' => ['Service radius must be between 0.1 and 1000 km.']]
                );
            }

            $attributes['service_radius_km'] = $radius;
        }

        // Location: accept either raw input or the validated subset; both
        // coordinates must be present together.
        $latRaw = array_key_exists('latitude', $data) ? $data['latitude'] : ($validated['latitude'] ?? null);
        $lngRaw = array_key_exists('longitude', $data) ? $data['longitude'] : ($validated['longitude'] ?? null);

        $hasLatInput = $latRaw !== null && $latRaw !== '';
        $hasLngInput = $lngRaw !== null && $lngRaw !== '';

        if ($hasLatInput !== $hasLngInput) {
            throw VolunteerException::validation(
                ['latitude' => ['Latitude and longitude must be provided together.']],
                'Validation failed.'
            );
        }

        if ($hasLatInput) {
            $lat = (float) $latRaw;
            $lng = (float) $lngRaw;

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw VolunteerException::validation(
                    [
                        'latitude'  => ['Latitude must be between -90 and 90.'],
                        'longitude' => ['Longitude must be between -180 and 180.'],
                    ],
                    'Validation failed.'
                );
            }

            $attributes['latitude'] = $lat;
            $attributes['longitude'] = $lng;
        }

        // Never let the client flip verification via profile updates.
        unset($attributes['verification_status'], $attributes['user_id']);

        return $attributes;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{category: ?string, priority: ?string, radius: ?float}
     */
    private function normalizeQuery(array $query): array
    {
        $category = $query['category'] ?? null;
        $priority = $query['priority'] ?? null;
        $radius = $query['radius'] ?? null;

        $category = is_string($category) && $category !== '' ? strtoupper(trim($category)) : null;
        $priority = is_string($priority) && $priority !== '' ? strtoupper(trim($priority)) : null;

        return [
            'category' => $category,
            'priority' => $priority,
            'radius'   => is_numeric($radius) ? max(0.1, min(1000.0, (float) $radius)) : null,
        ];
    }

    private function assertVolunteerRole(array $viewer): void
    {
        $role = (string) ($viewer['role'] ?? '');

        if ($role !== 'VOLUNTEER') {
            throw VolunteerException::forbidden();
        }
    }

    private function assertAdmin(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? '') !== 'ADMIN') {
            throw VolunteerException::forbidden();
        }
    }

    /**
     * Haversine distance in kilometres.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1.0, sqrt($a)));
    }

    /**
     * Normalize skills input (array or CSV) to a validated CSV string.
     */
    public static function normalizeSkillCsv(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $tokens = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];

        foreach ($tokens as $token) {
            $token = strtoupper(trim((string) $token));

            if ($token === '') {
                continue;
            }

            if (!in_array($token, self::SKILLS, true)) {
                throw VolunteerException::invalidSkills(sprintf(
                    'Skill "%s" is not allowed. Allowed skills: %s.',
                    $token,
                    implode(', ', self::SKILLS)
                ));
            }

            $clean[] = $token;
        }

        $clean = array_values(array_unique($clean));

        if (count($clean) > 10) {
            throw VolunteerException::invalidSkills('You can list at most 10 skills.');
        }

        return $clean === [] ? null : implode(',', $clean);
    }

    public static function normalizeVehicle(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $vehicle = strtoupper(trim((string) $value));

        if (!in_array($vehicle, self::VEHICLES, true)) {
            throw VolunteerException::validation(
                ['vehicle' => ['Vehicle must be one of: ' . implode(', ', self::VEHICLES) . '.']],
                'Validation failed.'
            );
        }

        return $vehicle;
    }

    public static function normalizeEquipmentCsv(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $tokens = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];

        foreach ($tokens as $token) {
            $token = strtoupper(trim((string) $token));
            $token = preg_replace('/\s+/', '_', $token) ?? '';

            if ($token === '' || $token === 'NONE') {
                continue;
            }

            if (preg_match('/^[A-Z0-9_]{1,40}$/', $token) !== 1) {
                throw VolunteerException::validation(
                    ['equipment' => ['Equipment items may use letters, numbers and underscores (max 40 characters).']],
                    'Validation failed.'
                );
            }

            $clean[] = $token;
        }

        $clean = array_values(array_unique($clean));

        if (count($clean) > 10) {
            throw VolunteerException::validation(
                ['equipment' => ['You can list at most 10 equipment items.']],
                'Validation failed.'
            );
        }

        return $clean === [] ? null : implode(',', $clean);
    }

    /**
     * @return array<int, string>
     */
    public static function skillTokens(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return self::tokenList($value);
    }

    /**
     * @return array<int, string>
     */
    public static function tokenList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $tokens = is_array($value) ? $value : explode(',', (string) $value);
        $clean = [];

        foreach ($tokens as $token) {
            $token = strtoupper(trim((string) $token));

            if ($token !== '' && $token !== 'NONE') {
                $clean[] = $token;
            }
        }

        return array_values(array_unique($clean));
    }
}
