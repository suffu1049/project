<?php

declare(strict_types=1);

/**
 * Security & session helpers.
 *
 * Output escaping (`e`) must be used for every piece of user-controlled data
 * rendered in a view. CSRF tokens protect state-changing requests in Phase 3+.
 */

if (!function_exists('e')) {
    /**
     * Escape a value for safe HTML output (XSS protection).
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('session_get')) {
    function session_get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('session_set')) {
    function session_set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }
}

if (!function_exists('session_forget')) {
    function session_forget(string $key): void
    {
        unset($_SESSION[$key]);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Return (creating if necessary) the current session CSRF token.
     */
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden CSRF input for forms.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Constant-time CSRF token check.
     */
    function csrf_verify(?string $token): bool
    {
        $sessionToken = $_SESSION['_csrf_token'] ?? null;

        if (!is_string($sessionToken) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('flash')) {
    /**
     * Set a one-time flash message (or read it when $value is null).
     */
    function flash(string $key, mixed $value = null): mixed
    {
        if ($value === null) {
            return pull_flash($key);
        }

        $_SESSION['_flash'][$key] = $value;

        return null;
    }
}

if (!function_exists('pull_flash')) {
    /**
     * Retrieve and remove a flash message.
     */
    function pull_flash(string $key, mixed $default = null): mixed
    {
        if (!isset($_SESSION['_flash'][$key])) {
            return $default;
        }

        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}

if (!function_exists('has_flash')) {
    function has_flash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }
}

if (!function_exists('old')) {
    /**
     * Retrieve previously submitted form input after a validation redirect.
     *
     * The whole old-input bag is pulled once and cached for the request so
     * multiple fields can be repopulated from a single flash entry.
     */
    function old(string $key, mixed $default = null): mixed
    {
        static $bag = null;

        if ($bag === null) {
            $stored = pull_flash('_old_input', []);
            $bag = is_array($stored) ? $stored : [];
        }

        return $bag[$key] ?? $default;
    }
}

if (!function_exists('auth_user')) {
    /**
     * Currently authenticated user (populated in Phase 3). Null for guests.
     */
    function auth_user(): ?array
    {
        $user = $_SESSION['user'] ?? null;

        return is_array($user) ? $user : null;
    }
}

if (!function_exists('role_home')) {
    /**
     * The default landing route for each role.
     */
    function role_home(string $role): string
    {
        return match ($role) {
            'VOLUNTEER'     => 'volunteer/dashboard',
            'ORGANIZATION'  => 'organization/dashboard',
            'ADMIN'         => 'admin',
            default         => 'dashboard',
        };
    }
}

if (!function_exists('role_for_path')) {
    /**
     * Which role a route prefix is reserved for (null when it is public).
     */
    function role_for_path(string $path): ?string
    {
        if (str_starts_with($path, '/admin')) {
            return 'ADMIN';
        }

        if (str_starts_with($path, '/volunteer')) {
            return 'VOLUNTEER';
        }

        if (str_starts_with($path, '/organization')) {
            return 'ORGANIZATION';
        }

        if (str_starts_with($path, '/dashboard')) {
            return 'CITIZEN';
        }

        return null;
    }
}

if (!function_exists('safe_intended')) {
    /**
     * Returns the intended path only when it does not belong to another role.
     */
    function safe_intended(?string $path, string $role): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $owner = role_for_path($path);

        if ($owner !== null && $owner !== $role) {
            return null;
        }

        return $path;
    }
}
