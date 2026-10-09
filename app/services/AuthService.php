<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AuthException;
use App\Core\Config;
use App\Core\Service;
use App\Models\OrganizationRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;

/**
 * Authentication service.
 *
 * Responsibilities: registration, credential verification, session lifecycle.
 *
 * Security notes:
 *  - Passwords are always hashed with password_hash() and checked with
 *    password_verify(); plaintext is never stored or logged.
 *  - The session id is regenerated on login and on logout (session fixation).
 *  - Login failures return one generic message so accounts cannot be
 *    enumerated by timing or wording.
 */
final class AuthService extends Service
{
    /**
     * Register a new account and its role profile.
     *
     * ADMIN can never be selected during public registration.
     *
     * @param array<string, mixed> $data validated input (name, email, phone, password, role)
     * @return array<string, mixed> the created user row (without the password hash)
     * @throws AuthException
     */
    public function register(array $data): array
    {
        $role = (string) ($data['role'] ?? '');

        if (!in_array($role, (array) Config::get('app.public_roles', []), true)) {
            throw AuthException::invalidRole();
        }

        $email = self::normalizeEmail((string) ($data['email'] ?? ''));

        if (UserRepository::emailExists($email)) {
            throw AuthException::emailExists();
        }

        $userId = UserRepository::create([
            'name'          => trim((string) ($data['name'] ?? '')),
            'email'         => $email,
            'phone'         => self::nullableString($data['phone'] ?? null),
            'password_hash' => password_hash((string) ($data['password'] ?? ''), PASSWORD_DEFAULT),
            'role'          => $role,
            'status'        => 'ACTIVE',
        ]);

        $this->createRoleProfile($userId, $role, trim((string) ($data['name'] ?? '')));

        $user = $this->userById($userId);

        if ($user === null) {
            throw new AuthException('INTERNAL_ERROR', 'Unable to create the account.', 500);
        }

        return $user;
    }

    /**
     * Verify credentials and start an authenticated session.
     *
     * @return array<string, mixed> the authenticated user (without the password hash)
     * @throws AuthException
     */
    public function login(string $email, string $password): array
    {
        $user = UserRepository::findByEmail(self::normalizeEmail($email));

        // Same generic message whether the account exists or not.
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw AuthException::invalidCredentials();
        }

        if (($user['status'] ?? '') !== 'ACTIVE') {
            throw AuthException::suspended();
        }

        $this->authenticate($user);

        return self::sanitize($user);
    }

    /**
     * Start a session for an already-verified user row.
     */
    public function authenticate(array $user): void
    {
        // Prevent session fixation: a new id on privilege change.
        $this->regenerateSession();

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user'] = self::sanitize($user);
    }

    /**
     * End the authenticated session and rotate the id.
     */
    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user']);

        $this->regenerateSession();
    }

    /**
     * Fresh user row for the current session id, or null.
     *
     * Re-reading from the database means a suspended account is locked out
     * immediately rather than only at the next login.
     *
     * @return array<string, mixed>|null
     */
    public function userById(int $id): ?array
    {
        $user = UserRepository::find($id);

        if ($user === null || ($user['status'] ?? '') !== 'ACTIVE') {
            return null;
        }

        return self::sanitize($user);
    }

    public function validateRegistration(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.user.rules', []),
            (array) Config::get('validation.user.labels', [])
        );

        if ($validator->fails()) {
            throw AuthException::validation($validator->errors());
        }

        return $validator->validated();
    }

    public function validateLogin(array $data): array
    {
        $validator = validator(
            $data,
            (array) Config::get('validation.login.rules', []),
            (array) Config::get('validation.login.labels', [])
        );

        if ($validator->fails()) {
            throw AuthException::validation($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Create the role-specific profile row for a new account.
     */
    private function createRoleProfile(int $userId, string $role, string $name): void
    {
        match ($role) {
            'VOLUNTEER' => VolunteerRepository::create([
                'user_id'             => $userId,
                'skills'              => null,
                'vehicle'             => null,
                'equipment'           => null,
                'availability'        => 'OFFLINE',
                'service_radius_km'   => 10.00,
                'latitude'            => null,
                'longitude'           => null,
                'verification_status' => 'PENDING',
            ]),
            'ORGANIZATION' => OrganizationRepository::create([
                'user_id'             => $userId,
                'name'                => $name,
                'type'                => 'OTHER',
                'contact'             => null,
                'description'         => null,
                'verification_status' => 'PENDING',
                'latitude'            => null,
                'longitude'           => null,
                'address_text'        => null,
            ]),
            default => null,
        };
    }

    private function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Strip the password hash before the user data is exposed or sessioned.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function sanitize(array $user): array
    {
        unset($user['password_hash']);

        return $user;
    }
}
