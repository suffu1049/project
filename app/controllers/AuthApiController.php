<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthException;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/**
 * JSON authentication endpoints (API.md §9–§12).
 *
 * All state-changing endpoints require a CSRF token: fetch it from
 * `GET /api/v1/csrf` and send it back as `X-CSRF-Token`.
 */
final class AuthApiController extends Controller
{
    /**
     * GET /api/v1/csrf — issue a CSRF token for the current session.
     */
    public function csrf(Request $request): Response
    {
        return $this->json(['csrf_token' => csrf_token()], 'CSRF token issued.');
    }

    /**
     * POST /api/v1/auth/register — create an account (never ADMIN).
     */
    public function register(Request $request): Response
    {
        $limiter = new RateLimiter();
        $key = 'auth.register|' . $request->ip();
        $max = (int) config('app.rate_limit.register_attempts', 5);
        $decay = (int) config('app.rate_limit.decay_seconds', 300);

        if ($limiter->tooManyAttempts($key, $max, $decay)) {
            return $this->jsonError('Too many registration attempts. Please try again later.', 'RATE_LIMITED', 429);
        }

        $limiter->hit($key, $decay);

        $service = new AuthService();

        try {
            $data = $service->validateRegistration($request->all());
            $user = $service->register($data);
            $service->authenticate($user);
        } catch (AuthException $exception) {
            return $this->failed($exception);
        }

        return $this->json(['user' => $user], 'Account created successfully.', 201);
    }

    /**
     * POST /api/v1/auth/login — authenticate and start a session.
     */
    public function login(Request $request): Response
    {
        $limiter = new RateLimiter();
        $key = 'auth.login|' . $request->ip();
        $max = (int) config('app.rate_limit.login_attempts', 10);
        $decay = (int) config('app.rate_limit.decay_seconds', 300);

        if ($limiter->tooManyAttempts($key, $max, $decay)) {
            return $this->jsonError('Too many sign-in attempts. Please try again later.', 'RATE_LIMITED', 429);
        }

        $service = new AuthService();

        try {
            $data = $service->validateLogin($request->all());
            $user = $service->login((string) $data['email'], (string) $data['password']);
        } catch (AuthException $exception) {
            // Only failed attempts count toward the brute-force guard.
            $limiter->hit($key, $decay);

            return $this->failed($exception);
        }

        $limiter->clear($key);

        return $this->json(['user' => $user], 'Login successful.');
    }

    /**
     * POST /api/v1/auth/logout — end the session.
     */
    public function logout(Request $request): Response
    {
        (new AuthService())->logout();

        return $this->json(null, 'Logged out successfully.');
    }

    /**
     * GET /api/v1/auth/me — the current authenticated user (401 for guests).
     */
    public function me(Request $request): Response
    {
        $user = $request->attribute('user');

        if (!is_array($user)) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        return $this->json($user, 'Current user retrieved.');
    }

    private function failed(AuthException $exception): Response
    {
        return $this->jsonError($exception->userMessage(), $exception->errorCode, $exception->status, $exception->errors);
    }
}
