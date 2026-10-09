<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Values come from `.env` (via the env() helper) with safe defaults so the
 * app can boot even with no environment file present.
 */
return [
    'name'     => env('APP_NAME', 'ResQLink'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => env('APP_DEBUG', false),
    'url'      => env('APP_URL', ''),
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'session' => [
        'name'     => env('SESSION_NAME', 'resqlink_session'),
        'lifetime' => (int) env('SESSION_LIFETIME', 120),
        'secure'   => env('SESSION_SECURE', false),
        'samesite' => env('SESSION_SAMESITE', 'Lax'),
    ],

    // Roles recognised across the platform (Phase 3 enforces them).
    'roles' => ['CITIZEN', 'VOLUNTEER', 'ORGANIZATION', 'ADMIN'],

    // Roles a visitor may self-select at registration (Admin excluded).
    'public_roles' => ['CITIZEN', 'VOLUNTEER', 'ORGANIZATION'],

    // Authentication brute-force guard (Phase 12). Per-host sliding window,
    // applied in the web/API auth controllers — not in AuthService, so the
    // business layer stays free of transport concerns.
    'rate_limit' => [
        'login_attempts'    => max(1, (int) env('AUTH_LOGIN_RATE_LIMIT', 10)),
        'register_attempts' => max(1, (int) env('AUTH_REGISTER_RATE_LIMIT', 5)),
        'decay_seconds'     => max(30, (int) env('AUTH_RATE_LIMIT_DECAY_SECONDS', 300)),
    ],
];
