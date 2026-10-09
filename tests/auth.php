<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 3 authentication & RBAC tests.
 *
 * Requires a migrated + seeded database. Run with:
 *     php -c php.ini tests/auth.php
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Core\AuthException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\OrganizationRepository;
use App\Models\UserRepository;
use App\Models\VolunteerRepository;
use App\Services\AuthService;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

function expectAuth(callable $callback, string $code): bool
{
    try {
        $callback();
    } catch (AuthException $exception) {
        return $exception->errorCode === $code;
    } catch (Throwable) {
        return false;
    }

    return false;
}

/** Build a request; $json forces the API/JSON content type. */
function make_request(string $method, string $path, array $body = [], array $headers = [], bool $json = false): Request
{
    if ($json) {
        $headers['accept'] = 'application/json';
        $headers['content-type'] = 'application/json';
    }

    return new Request($method, $path, [], $body, $headers, [], '127.0.0.1', 'auth-test');
}

function ok_next(): callable
{
    return static fn (): Response => Response::html('OK', 200);
}

$cleanup = [];

echo "ResQLink authentication & RBAC tests\n";
echo "====================================\n\n";

echo "Configuration\n";
check('ADMIN excluded from public roles', !in_array('ADMIN', config('app.public_roles', []), true));
$loginRules = (string) config('validation.login.rules.role', 'absent');
check('login rule set does not accept a role field', $loginRules === 'absent');

echo "\nRegistration\n";
$service = new AuthService();
$suffix = bin2hex(random_bytes(4));

$validCitizen = [
    'name' => 'Test Citizen',
    'email' => "citizen-{$suffix}@resqlink.test",
    'phone' => '9111111111',
    'password' => 'StrongPass123',
    'role' => 'CITIZEN',
];

$validated = $service->validateRegistration($validCitizen);
check('valid registration input passes validation', $validated['email'] === $validCitizen['email']);

$citizen = $service->register($validated);
$cleanup[] = (int) $citizen['id'];
check('registration returns a user id', isset($citizen['id']));

$stored = Database_select_user((int) $citizen['id']);
check('password is hashed, never plaintext', str_starts_with((string) $stored['password_hash'], '$2y$') || str_starts_with((string) $stored['password_hash'], '$argon'));
check('stored hash does not contain the password', !str_contains((string) $stored['password_hash'], 'StrongPass123'));
check('password_hash verifies the password', password_verify('StrongPass123', (string) $stored['password_hash']));
check('wrong password does not verify', !password_verify('nope', (string) $stored['password_hash']));
check('no password hash in returned user', !isset($citizen['password_hash']));

check('duplicate email rejected', expectAuth(static function () use ($service, $validCitizen): void {
    $service->register($service->validateRegistration($validCitizen));
}, 'EMAIL_ALREADY_EXISTS'));

check('ADMIN role rejected at service level', expectAuth(static function () use ($service, $suffix): void {
    $service->register($service->validateRegistration([
        'name' => 'Sneaky Admin', 'email' => "admin-{$suffix}@resqlink.test",
        'password' => 'StrongPass123', 'role' => 'ADMIN',
    ]));
}, 'VALIDATION_ERROR'));

echo "\nRole profiles\n";
$volunteerEmail = "volunteer-x-{$suffix}@resqlink.test";
$volunteer = $service->register($service->validateRegistration([
    'name' => 'Test Volunteer', 'email' => $volunteerEmail,
    'password' => 'StrongPass123', 'role' => 'VOLUNTEER',
]));
$cleanup[] = (int) $volunteer['id'];
check('volunteer profile auto-created', VolunteerRepository::findByUserId((int) $volunteer['id']) !== null);
check('new volunteer starts offline + pending', (VolunteerRepository::findByUserId((int) $volunteer['id'])['availability'] ?? '') === 'OFFLINE');

$orgEmail = "org-x-{$suffix}@resqlink.test";
$org = $service->register($service->validateRegistration([
    'name' => 'Test Org', 'email' => $orgEmail,
    'password' => 'StrongPass123', 'role' => 'ORGANIZATION',
]));
$cleanup[] = (int) $org['id'];
check('organization profile auto-created', OrganizationRepository::findByUserId((int) $org['id']) !== null);
check('new organization starts PENDING', (OrganizationRepository::findByUserId((int) $org['id'])['verification_status'] ?? '') === 'PENDING');

echo "\nLogin\n";
unset($_SESSION['user_id'], $_SESSION['user']);

$user = $service->login($validCitizen['email'], 'StrongPass123');
check('correct credentials log in', ($user['email'] ?? null) === $validCitizen['email']);
check('session user id set', ($_SESSION['user_id'] ?? null) === (int) $citizen['id']);
check('auth_user() exposes the session user', auth_user() !== null && auth_user()['id'] === (int) $citizen['id']);
check('session user has no password hash', !isset(auth_user()['password_hash']));

check('wrong password -> INVALID_CREDENTIALS', expectAuth(static fn () => $service->login($validCitizen['email'], 'WrongPassword'), 'INVALID_CREDENTIALS'));
check('unknown email -> INVALID_CREDENTIALS (no enumeration)', expectAuth(static fn () => $service->login("ghost-{$suffix}@resqlink.test", 'x'), 'INVALID_CREDENTIALS'));

$loginError = '';
try {
    $service->login($validCitizen['email'], 'WrongPassword');
} catch (AuthException $exception) {
    $loginError = $exception->userMessage();
}
check('credential error message is generic', $loginError === 'Invalid email or password.');

echo "\nSuspended accounts\n";
$suspendedEmail = "suspended-{$suffix}@resqlink.test";
$suspendedId = UserRepository::create([
    'name' => 'Suspended User', 'email' => $suspendedEmail, 'phone' => null,
    'password_hash' => password_hash('StrongPass123', PASSWORD_DEFAULT),
    'role' => 'CITIZEN', 'status' => 'SUSPENDED',
]);
$cleanup[] = $suspendedId;

check('suspended login -> ACCOUNT_SUSPENDED', expectAuth(static fn () => $service->login($suspendedEmail, 'StrongPass123'), 'ACCOUNT_SUSPENDED'));

$_SESSION['user_id'] = $suspendedId;
$blocked = (new AuthMiddleware())->handle(make_request('GET', '/account'), ok_next());
check('suspended session is rejected', $blocked->status() === 302);
check('suspended session cleared', !isset($_SESSION['user_id']));

echo "\nAuthMiddleware\n";
unset($_SESSION['user_id'], $_SESSION['user']);

$guestWeb = (new AuthMiddleware())->handle(make_request('GET', '/account'), ok_next());
check('guest web request redirects to /login', $guestWeb->status() === 302 && str_contains((string) ($guestWeb->headers()['Location'] ?? ''), '/login'));
check('intended path remembered', ($_SESSION['intended'] ?? null) === '/account');

$_SESSION['user_id'] = (int) $citizen['id'];
$nextCalled = false;
$authed = (new AuthMiddleware())->handle(make_request('GET', '/account'), static function () use (&$nextCalled): Response {
    $nextCalled = true;

    return Response::html('OK');
});
check('authenticated request reaches the handler', $nextCalled && $authed->status() === 200);

unset($_SESSION['user_id'], $_SESSION['user']); // ensure this really is a guest
$guestApi = (new AuthMiddleware())->handle(make_request('GET', '/api/v1/auth/me', [], [], true), ok_next());
check('guest API request -> 401 JSON', $guestApi->status() === 401 && json_decode($guestApi->body(), true)['code'] === 'UNAUTHORIZED');

echo "\nRoleMiddleware\n";
$adminId = (int) (UserRepository::findByEmail('admin@resqlink.test')['id'] ?? 0);

$_SESSION['user_id'] = (int) $citizen['id'];
$adminGuard = (new RoleMiddleware(['ADMIN']));
$citizenOnAdmin = $adminGuard->handle(make_request('GET', '/admin'), ok_next());
check('citizen blocked from /admin (403)', $citizenOnAdmin->status() === 403);

$_SESSION['user_id'] = $adminId;
$adminOnAdmin = $adminGuard->handle(make_request('GET', '/admin'), ok_next());
check('admin allowed on /admin', $adminOnAdmin->status() === 200);

$citizenGuard = (new RoleMiddleware(['CITIZEN']));
$_SESSION['user_id'] = (int) $citizen['id'];
check('citizen allowed on /dashboard', $citizenGuard->handle(make_request('GET', '/dashboard'), ok_next())->status() === 200);
$_SESSION['user_id'] = $adminId;
check('admin blocked from citizen area', $citizenGuard->handle(make_request('GET', '/dashboard'), ok_next())->status() === 403);

$_SESSION['user_id'] = (int) $citizen['id'];
$apiForbidden = (new RoleMiddleware(['ADMIN']))->handle(make_request('POST', '/api/v1/admin/verify', [], [], true), ok_next());
check('API role denial -> 403 JSON FORBIDDEN', $apiForbidden->status() === 403 && json_decode($apiForbidden->body(), true)['code'] === 'FORBIDDEN');

echo "\nCSRF protection\n";
$token = csrf_token();
$csrf = new CsrfMiddleware();

$getPasses = true;
$csrf->handle(make_request('GET', '/login'), static function () use (&$getPasses): Response {
    $getPasses = false;

    return Response::html('OK');
});
check('safe GET method is skipped', $getPasses === false);

$reached = false;
$result = $csrf->handle(
    make_request('POST', '/login', ['_token' => $token]),
    static function () use (&$reached): Response {
        $reached = true;

        return Response::html('OK');
    }
);
check('valid form token passes', $reached && $result->status() === 200);

$reached = false;
$noToken = $csrf->handle(make_request('POST', '/login'), static function () use (&$reached): Response {
    $reached = true;

    return Response::html('OK');
});
check('missing token blocks the request', !$reached && $noToken->status() === 302);

$reached = false;
$badToken = $csrf->handle(
    make_request('POST', '/login', [], ['x-csrf-token' => 'forged-token'], true),
    static function () use (&$reached): Response {
        $reached = true;

        return Response::html('OK');
    }
);
check('forged API token -> 403 CSRF_MISMATCH', !$reached && $badToken->status() === 403 && json_decode($badToken->body(), true)['code'] === 'CSRF_MISMATCH');

$reached = false;
$goodApi = $csrf->handle(
    make_request('POST', '/api/v1/auth/login', [], ['x-csrf-token' => $token], true),
    static function () use (&$reached): Response {
        $reached = true;

        return Response::html('OK');
    }
);
check('valid API header token passes', $reached && $goodApi->status() === 200);

echo "\nLogout\n";
$_SESSION['user_id'] = (int) $citizen['id'];
$_SESSION['user'] = ['id' => (int) $citizen['id']];
$service->logout();
check('logout clears the session user', !isset($_SESSION['user_id']) && !isset($_SESSION['user']));
check('auth_user() is null after logout', auth_user() === null);

echo "\nCleanup\n";
foreach ($cleanup as $id) {
    UserRepository::delete($id);
}
check('test accounts removed', UserRepository::findByEmail($validCitizen['email']) === null);

// ---- helper (defined as a function so it can be called before declaration) ----
/**
 * @return array<string, mixed>
 */
function Database_select_user(int $id): array
{
    return UserRepository::find($id) ?: [];
}

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
