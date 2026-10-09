<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Role authorization guard.
 *
 * Extends authentication: first the request must be authenticated, then the
 * user's role must be in the allowed list. Roles always come from the
 * server-side session/database — never from the request.
 *
 * Usage:  [new RoleMiddleware(['ADMIN'])]
 */
class RoleMiddleware extends AuthMiddleware
{
    /**
     * @param array<int, string> $roles
     */
    public function __construct(private readonly array $roles)
    {
    }

    protected function guard(Request $request): ?Response
    {
        $blocked = parent::guard($request);

        if ($blocked !== null) {
            return $blocked;
        }

        $user = $request->attribute('user');
        $role = is_array($user) ? (string) ($user['role'] ?? '') : '';

        if (!in_array($role, $this->roles, true)) {
            return $this->forbidden($request);
        }

        return null;
    }

    protected function forbidden(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error('You are not authorized to perform this action.', 'FORBIDDEN', 403);
        }

        return Response::html(
            View::render('errors/403', [
                'status'  => 403,
                'message' => 'You do not have permission to access this area.',
            ]),
            403
        );
    }
}
