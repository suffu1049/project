<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 12 security hardening tests.
 *
 * Run with:
 *     php -c php.ini tests/security.php
 *
 * Covers the Phase 12 security audit deltas: the authentication brute-force
 * guard (RateLimiter + web/API wiring), default security response headers, and
 * production error handling (no path/class/stack leakage).
 *
 * The production-error assertions run before any output because PHP reports
 * headers_sent() = true in CLI once anything has been echoed.
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\AuthApiController;
use App\Controllers\AuthController;
use App\Core\ErrorHandler;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecurityHeaders;

define('BASE_PATH', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '1');

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

function make_request(string $method, string $path, array $body = [], string $ip = '127.0.0.1', bool $json = false): Request
{
    $headers = ['accept' => $json ? 'application/json' : 'text/html'];

    if ($json) {
        $headers['content-type'] = 'application/json';
    }

    return new Request($method, $path, [], $body, $headers, [], $ip, 'security-test');
}

$secretPath = base_path('config/database.php');

// ---------------------------------------------------------------------------
// Production error handling — captured first, before any output is emitted.

ErrorHandler::register(false);

ob_start();
ErrorHandler::handleException(new RuntimeException('boom at ' . $secretPath));
$productionErrorBody = (string) ob_get_clean();

// Restore development behavior for the rest of the run.
ErrorHandler::register(true);

// ---------------------------------------------------------------------------
echo "ResQLink security hardening tests\n";
echo "=================================\n\n";

echo "Production error handling\n";
check('production error response has a body', trim($productionErrorBody) !== '');
check('production error hides filesystem paths', !str_contains($productionErrorBody, base_path()));
check('production error hides the exception class', !str_contains($productionErrorBody, 'RuntimeException'));
check('production error shows a safe, generic message', str_contains($productionErrorBody, 'Something went wrong'));

// ---------------------------------------------------------------------------
echo "\nSecurity headers\n";

$plain = Response::html('<p>ok</p>');
check('security headers missing before apply', SecurityHeaders::missing($plain) !== []);

$hardened = SecurityHeaders::apply($plain);
$headers = $hardened->headers();
check('nosniff header is applied', ($headers['X-Content-Type-Options'] ?? null) === 'nosniff');
check('clickjacking is blocked (X-Frame-Options)', ($headers['X-Frame-Options'] ?? null) === 'DENY');
check('referrer policy is set', ($headers['Referrer-Policy'] ?? null) === 'strict-origin-when-cross-origin');
check('content-type is preserved', ($headers['Content-Type'] ?? null) === 'text/html; charset=UTF-8');

$twice = SecurityHeaders::apply($hardened);
check('applying twice does not duplicate headers', array_keys(array_change_key_case($twice->headers(), CASE_LOWER)) === array_keys(array_change_key_case($headers, CASE_LOWER)));

$custom = SecurityHeaders::apply(Response::html('x', 200, ['X-Frame-Options' => 'SAMEORIGIN']));
check('an explicitly set header is not overridden', ($custom->headers()['X-Frame-Options'] ?? null) === 'SAMEORIGIN');

// ---------------------------------------------------------------------------
echo "\nRate limiter\n";

RateLimiter::flush();

$key = 'unit.' . bin2hex(random_bytes(4));

check('a fresh key has no attempts', RateLimiter::attempts($key, 300) === []);
check('a fresh key is not limited', RateLimiter::tooManyAttempts($key, 3, 300) === false);

RateLimiter::hit($key, 300);
RateLimiter::hit($key, 300);
check('attempts accumulate', count(RateLimiter::attempts($key, 300)) === 2);
check('below the limit is still allowed', RateLimiter::tooManyAttempts($key, 3, 300) === false);

RateLimiter::hit($key, 300);
check('at the limit it blocks', RateLimiter::tooManyAttempts($key, 3, 300) === true);
check('availableIn reports a positive cooldown', RateLimiter::availableIn($key, 300) > 0);

$other = 'unit.' . bin2hex(random_bytes(4));
check('keys are isolated from each other', RateLimiter::tooManyAttempts($other, 3, 300) === false);

RateLimiter::clear($key);
check('clear resets the counter', RateLimiter::attempts($key, 300) === []);
check('a cleared key is allowed again', RateLimiter::tooManyAttempts($key, 3, 300) === false);

// Sliding window: an attempt older than the window is pruned.
RateLimiter::hit($key, 1);
sleep(2);
check('attempts older than the window are pruned', RateLimiter::attempts($key, 1) === []);

// ---------------------------------------------------------------------------
echo "\nAPI login brute-force guard\n";

RateLimiter::flush();

$max = max(1, (int) config('app.rate_limit.login_attempts', 10));
$attackerIp = '203.0.113.7';
$innocentIp = '198.51.100.22';
$api = new AuthApiController();

$lastStatus = 0;
$lastCode = '';

for ($i = 0; $i < $max; $i++) {
    $response = $api->login(make_request('POST', '/api/v1/auth/login', [
        'email'    => 'admin@resqlink.test',
        'password' => 'definitely-wrong-' . $i,
    ], $attackerIp, true));

    $decoded = json_decode((string) $response->body(), true);
    $lastStatus = $response->status();
    $lastCode = (string) ($decoded['code'] ?? '');
}

check('wrong credentials are rejected (not 429) before the limit', $lastStatus === 401 && $lastCode === 'INVALID_CREDENTIALS');

$blocked = $api->login(make_request('POST', '/api/v1/auth/login', [
    'email'    => 'admin@resqlink.test',
    'password' => 'Password123!', // even the correct password is blocked once limited
], $attackerIp, true));
$blockedPayload = json_decode((string) $blocked->body(), true);

check('the attempt after the limit returns 429', $blocked->status() === 429);
check('the 429 uses the RATE_LIMITED code', ($blockedPayload['code'] ?? null) === 'RATE_LIMITED');
check('the 429 does not leak whether the password was correct', ($blockedPayload['success'] ?? null) === false);
check('the 429 does not start a session', !isset($_SESSION['user']));

$innocent = $api->login(make_request('POST', '/api/v1/auth/login', [
    'email'    => 'admin@resqlink.test',
    'password' => 'Password123!',
], $innocentIp, true));
check('a different client is unaffected', $innocent->status() === 200);

RateLimiter::flush();

$clearIp = '203.0.113.8';
for ($i = 0; $i < 3; $i++) {
    $api->login(make_request('POST', '/api/v1/auth/login', [
        'email'    => 'admin@resqlink.test',
        'password' => 'wrong-' . $i,
    ], $clearIp, true));
}

$keyForIp = 'auth.login|' . $clearIp;
check('failed logins are counted for the client', count(RateLimiter::attempts($keyForIp, 300)) === 3);

$success = $api->login(make_request('POST', '/api/v1/auth/login', [
    'email'    => 'admin@resqlink.test',
    'password' => 'Password123!',
], $clearIp, true));
check('a correct password still signs in under the limit', $success->status() === 200);
check('a successful sign-in clears the failure counter', RateLimiter::attempts($keyForIp, 300) === []);

// ---------------------------------------------------------------------------
echo "\nWeb login brute-force guard\n";

RateLimiter::flush();
unset($_SESSION['user'], $_SESSION['user_id']);

$webIp = '203.0.113.9';
$web = new AuthController();

for ($i = 0; $i < $max; $i++) {
    $web->login(make_request('POST', '/login', [
        'email'    => 'admin@resqlink.test',
        'password' => 'nope-' . $i,
    ], $webIp));
}

$webKey = 'auth.login|' . $webIp;
check('web failures trip the same guard', RateLimiter::tooManyAttempts($webKey, $max, 300) === true);

$webBlocked = $web->login(make_request('POST', '/login', [
    'email'    => 'admin@resqlink.test',
    'password' => 'Password123!',
], $webIp));
check('the limited web request redirects', $webBlocked->status() === 302);
check('the limited web request does not establish a session', !isset($_SESSION['user'], $_SESSION['user_id']));
check('the limited web request reports the guard message', str_contains((string) ($_SESSION['_flash']['error'] ?? ''), 'Too many sign-in attempts'));

// Sanity: without the guard, a correct password DOES establish a session.
RateLimiter::clear($webKey);
unset($_SESSION['user'], $_SESSION['user_id']);
$web->login(make_request('POST', '/login', ['email' => 'admin@resqlink.test', 'password' => 'Password123!'], $webIp));
check('an unblocked correct password establishes a session', isset($_SESSION['user']));

// ---------------------------------------------------------------------------
echo "\nConfiguration\n";

$loginLimit = (int) config('app.rate_limit.login_attempts', 0);
$registerLimit = (int) config('app.rate_limit.register_attempts', 0);
$decay = (int) config('app.rate_limit.decay_seconds', 0);

check('login attempt limit is positive', $loginLimit > 0);
check('register attempt limit is positive', $registerLimit > 0);
check('decay window is at least 30 seconds', $decay >= 30);

// ---------------------------------------------------------------------------
echo "\nCleanup\n";

RateLimiter::flush();
check('rate limiter storage is empty after cleanup', RateLimiter::attempts('auth.login|' . $attackerIp, $decay) === []);
echo "  cleanup finished\n";

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
