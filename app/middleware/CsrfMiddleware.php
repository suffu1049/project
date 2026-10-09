<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * CSRF guard for state-changing requests (POST / PATCH / PUT / DELETE).
 *
 * The token is read from the `X-CSRF-Token` header (fetch/JSON clients) or the
 * `_token` field (HTML forms) and compared in constant time against the token
 * stored in the session. Safe methods pass straight through.
 *
 * Read the current token with `GET /api/v1/csrf`.
 */
class CsrfMiddleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->header('x-csrf-token');

        if (!is_string($token) || $token === '') {
            $candidate = $request->input('_token');
            $token = is_string($candidate) ? $candidate : '';
        }

        if (!csrf_verify($token)) {
            return $this->failed($request);
        }

        return $next($request);
    }

    private function failed(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error('CSRF token is missing or invalid.', 'CSRF_MISMATCH', 403);
        }

        flash('error', 'Your session expired. Please try again.');

        $referer = (string) $request->header('referer', '');
        $location = $referer !== '' ? $referer : url('login');

        return Response::redirect($location);
    }
}
