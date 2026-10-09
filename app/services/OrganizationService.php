<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\OrganizationException;
use App\Core\Service;
use App\Models\AuditRepository;
use App\Models\OrganizationRepository;
use App\Models\ResourceRepository;
use App\Models\UserRepository;

/**
 * Organization profile, verification and inventory overview (Phase 6).
 *
 * Business rules live here — controllers stay thin and repositories stay pure
 * persistence.
 *
 * Rules:
 *   - Only the organization (ORGANIZATION role) may edit its own profile.
 *   - Verification status is set exclusively by an admin (never client-set).
 *   - Profile edits are allowed while PENDING/REJECTED (the org is improving
 *     its submission); resource mutations additionally require VERIFIED.
 *   - Admin reads never expose account email or phone (DATABASE.md §29).
 */
final class OrganizationService extends Service
{
    /** Controlled organization type vocabulary. */
    public const TYPES = ['NGO', 'HOSPITAL', 'SHELTER', 'FOOD_PROVIDER', 'COMMUNITY_GROUP', 'OTHER'];

    public const VERIFICATION_STATUSES = ['PENDING', 'VERIFIED', 'REJECTED'];

    /** Fields an organization may edit on its own profile. */
    private const PROFILE_FIELDS = [
        'name',
        'type',
        'contact',
        'description',
        'address_text',
        'latitude',
        'longitude',
    ];

    /**
     * Fetch (creating if needed) the profile for an organization user.
     *
     * @return array<string, mixed>
     */
    public function profileFor(int $userId, array $viewer): array
    {
        $this->assertOrganizationRole($viewer);

        if ((int) ($viewer['id'] ?? 0) !== $userId) {
            // Organizations can only ever read their own profile.
            throw OrganizationException::forbidden();
        }

        $profile = OrganizationRepository::findByUserId($userId);

        if ($profile === null) {
            // Registration normally creates the row; this covers legacy accounts.
            $user = UserRepository::find($userId);
            $profileId = OrganizationRepository::create([
                'user_id'             => $userId,
                'name'                => trim((string) ($user['name'] ?? '')) !== '' ? (string) $user['name'] : 'Organization',
                'type'                => 'OTHER',
                'contact'             => null,
                'description'         => null,
                'verification_status' => 'PENDING',
                'latitude'            => null,
                'longitude'           => null,
                'address_text'        => null,
            ]);

            $profile = OrganizationRepository::find($profileId);
        }

        if ($profile === null) {
            throw OrganizationException::notFound();
        }

        return $profile;
    }

    /**
     * Update the caller's own organization profile (partial update semantics).
     *
     * @param array<string, mixed> $data validated subset of PROFILE_FIELDS
     * @return array<string, mixed>
     */
    public function updateProfile(array $viewer, array $data): array
    {
        $userId = (int) ($viewer['id'] ?? 0);
        $profile = $this->profileFor($userId, $viewer);

        $attributes = $this->normalizeProfileAttributes($data, $profile);

        if ($attributes === []) {
            return $profile;
        }

        OrganizationRepository::update((int) $profile['id'], $attributes);

        $updated = OrganizationRepository::find((int) $profile['id']);

        if ($updated === null) {
            throw OrganizationException::notFound();
        }

        $this->log('info', 'Organization profile updated.', [
            'organization_id' => (int) $updated['id'],
            'fields'          => array_keys($attributes),
        ]);

        return $updated;
    }

    /**
     * Validate an organization profile payload (partial update semantics).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateProfile(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.organization_update.rules', []),
            (array) Config::get('validation.organization_update.labels', [])
        );

        if ($validator->fails()) {
            throw OrganizationException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Admin list of organizations with inventory counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminList(?string $verificationStatus = null): array
    {
        if ($verificationStatus !== null && !in_array($verificationStatus, self::VERIFICATION_STATUSES, true)) {
            throw OrganizationException::invalidVerificationStatus(
                'Verification status must be one of: ' . implode(', ', self::VERIFICATION_STATUSES) . '.'
            );
        }

        $rows = OrganizationRepository::adminList($verificationStatus);

        return array_map(static fn (array $row): array => self::presentAdmin($row), $rows);
    }

    /**
     * Admin single read (no account email/phone).
     *
     * @return array<string, mixed>
     */
    public function adminShow(int $organizationId): array
    {
        $profile = OrganizationRepository::find($organizationId);

        if ($profile === null) {
            throw OrganizationException::notFound();
        }

        $counts = ResourceRepository::statsForOrganization($organizationId);

        return self::presentAdmin(array_merge($profile, [
            'resource_count'     => $counts['total_resources'],
            'total_quantity'     => $counts['total_quantity'],
            'available_quantity' => $counts['available_quantity'],
        ]));
    }

    /**
     * Verify or reject an organization (admin only).
     *
     * @return array<string, mixed> the updated organization row
     */
    public function adminVerify(int $organizationId, array $viewer, string $status, ?string $reason = null): array
    {
        $this->assertAdmin($viewer);

        $status = strtoupper(trim($status));

        if (!in_array($status, ['VERIFIED', 'REJECTED'], true)) {
            throw OrganizationException::invalidVerificationStatus(
                'Verification status must be VERIFIED or REJECTED.'
            );
        }

        $profile = OrganizationRepository::find($organizationId);

        if ($profile === null) {
            throw OrganizationException::notFound();
        }

        OrganizationRepository::setVerificationStatus($organizationId, $status);

        AuditRepository::record(
            (int) $viewer['id'],
            $status === 'VERIFIED' ? 'VERIFY_ORGANIZATION' : 'REJECT_ORGANIZATION',
            'organization',
            $organizationId,
            [
                'from'   => (string) $profile['verification_status'],
                'to'     => $status,
                'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
            ]
        );

        $updated = OrganizationRepository::find($organizationId);

        if ($updated === null) {
            throw OrganizationException::notFound();
        }

        $this->log('info', 'Organization verification updated.', [
            'organization_id' => $organizationId,
            'status'          => $status,
            'actor_id'        => (int) $viewer['id'],
        ]);

        return $updated;
    }

    /**
     * Inventory overview for the organization dashboard.
     *
     * @return array{total_resources:int, total_quantity:int, available_quantity:int, available:int, limited:int, unavailable:int}
     */
    public function inventoryStats(int $organizationId): array
    {
        return ResourceRepository::statsForOrganization($organizationId);
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
            'id'                  => (int) ($profile['id'] ?? 0),
            'user_id'             => (int) ($profile['user_id'] ?? 0),
            'name'                => (string) ($profile['name'] ?? ''),
            'type'                => (string) ($profile['type'] ?? 'OTHER'),
            'contact'             => $profile['contact'] !== null ? (string) $profile['contact'] : null,
            'description'         => $profile['description'] !== null ? (string) $profile['description'] : null,
            'address_text'        => $profile['address_text'] !== null ? (string) $profile['address_text'] : null,
            'verification_status' => (string) ($profile['verification_status'] ?? 'PENDING'),
            'location'            => [
                'latitude'  => $profile['latitude'] !== null ? (float) $profile['latitude'] : null,
                'longitude' => $profile['longitude'] !== null ? (float) $profile['longitude'] : null,
            ],
            'has_location'        => ($profile['latitude'] ?? null) !== null && ($profile['longitude'] ?? null) !== null,
            'created_at'          => (string) ($profile['created_at'] ?? ''),
            'updated_at'          => (string) ($profile['updated_at'] ?? ''),
        ];
    }

    /**
     * Admin/operational presentation — never account email or phone.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentAdmin(array $row): array
    {
        return [
            'id'                  => (int) ($row['id'] ?? 0),
            'user_id'             => (int) ($row['user_id'] ?? 0),
            'name'                => (string) ($row['name'] ?? ''),
            'user_name'           => (string) ($row['user_name'] ?? ''),
            'type'                => (string) ($row['type'] ?? 'OTHER'),
            'contact'             => $row['contact'] !== null ? (string) $row['contact'] : null,
            'description'         => $row['description'] !== null ? (string) $row['description'] : null,
            'address_text'        => $row['address_text'] !== null ? (string) $row['address_text'] : null,
            'verification_status' => (string) ($row['verification_status'] ?? 'PENDING'),
            'has_location'        => ($row['latitude'] ?? null) !== null && ($row['longitude'] ?? null) !== null,
            'resource_count'      => (int) ($row['resource_count'] ?? 0),
            'total_quantity'      => (int) ($row['total_quantity'] ?? 0),
            'available_quantity'  => (int) ($row['available_quantity'] ?? 0),
            'created_at'          => (string) ($row['created_at'] ?? ''),
            'updated_at'          => (string) ($row['updated_at'] ?? ''),
        ];
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
        $validated = $this->validateProfile($data);
        $attributes = [];

        foreach (array_diff(self::PROFILE_FIELDS, ['latitude', 'longitude']) as $field) {
            // Partial update: only touch fields the caller actually sent.
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $validated[$field] ?? null;
            $attributes[$field] = $value === null ? null : (is_string($value) ? trim($value) : $value);
        }

        if (array_key_exists('type', $attributes) && $attributes['type'] !== null) {
            $type = strtoupper((string) $attributes['type']);

            if (!in_array($type, self::TYPES, true)) {
                throw OrganizationException::validation(
                    ['type' => ['Organization type must be one of: ' . implode(', ', self::TYPES) . '.']],
                    'Validation failed.'
                );
            }

            $attributes['type'] = $type;
        }

        // name and type are NOT NULL columns: explicitly clearing them is a
        // validation error rather than a database error.
        foreach (['name' => 'Organization name is required.', 'type' => 'Organization type is required.'] as $required => $message) {
            if (array_key_exists($required, $attributes) && ($attributes[$required] === null || $attributes[$required] === '')) {
                throw OrganizationException::validation([$required => [$message]], 'Validation failed.');
            }
        }

        // Latitude/longitude must be provided together when either is set.
        $latRaw = array_key_exists('latitude', $data) ? $data['latitude'] : ($validated['latitude'] ?? null);
        $lngRaw = array_key_exists('longitude', $data) ? $data['longitude'] : ($validated['longitude'] ?? null);

        $hasLatInput = $latRaw !== null && $latRaw !== '';
        $hasLngInput = $lngRaw !== null && $lngRaw !== '';

        if ($hasLatInput !== $hasLngInput) {
            throw OrganizationException::validation(
                ['latitude' => ['Latitude and longitude must be provided together.']],
                'Validation failed.'
            );
        }

        if ($hasLatInput) {
            $lat = (float) $latRaw;
            $lng = (float) $lngRaw;

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw OrganizationException::validation(
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

        // Never let the client flip verification or ownership via profile edits.
        unset($attributes['verification_status'], $attributes['user_id']);

        return $attributes;
    }

    private function assertOrganizationRole(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? '') !== 'ORGANIZATION') {
            throw OrganizationException::forbidden();
        }
    }

    private function assertAdmin(array $viewer): void
    {
        if ((string) ($viewer['role'] ?? '') !== 'ADMIN') {
            throw OrganizationException::forbidden();
        }
    }
}