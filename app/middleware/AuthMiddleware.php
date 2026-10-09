<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/**
 * Authentication guard.
 *
 * Verifies the session maps to an existing ACTIVE account by re-reading the
 * database, so suspended accounts lose access immediately. On success the
 * current user is attached to the request as the `user` attribute.
 *
 * Guests are redirected to /login (web) or receive 401 JSON (API).
 */
class AuthMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $blocked = $this->guard($request);

        if ($blocked !== null) {
            return $blocked;
        }

        return $next($request);
    }

    /**
     * Returns a blocking response when access is denied, null when allowed.
     */
    protected function guard(Request $request): ?Response
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return $this->unauthenticated($request);
        }

        $request->setAttribute('user', $user);

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveUser(): ?array
    {
        $sessionId = $_SESSION['user_id'] ?? null;

        if (!is_int($sessionId) && !is_string($sessionId)) {
            return null;
        }

        $user = (new AuthService())->userById((int) $sessionId);

        if ($user === null) {
            // Account is gone, suspended, or the session is stale.
            unset($_SESSION['user_id'], $_SESSION['user']);

            return null;
        }

        $_SESSION['user'] = $user;

        return $user;
    }

    protected function unauthenticated(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error('Authentication required.', 'UNAUTHORIZED', 401);
        }

        // Remember where the user was headed for a post-login redirect.
        if ($request->path() !== '/login') {
            $_SESSION['intended'] = $request->path();
        }

        return Response::redirect(url('login'));
    }
}
