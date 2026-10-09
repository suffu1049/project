<?php

declare(strict_types=1);

/**
 * External service configuration.
 *
 * Every integration here is OPTIONAL. The core emergency workflow must remain
 * fully functional when these services are unavailable. All AI access goes
 * through App\Services\AIService (introduced in Phase 10).
 */
return [
    'ai' => [
        // Optional: set AI_API_KEY + AI_API_URL to enable AI assistance.
        // Without them AIService falls back to deterministic keyword logic,
        // so the emergency workflow is never affected.
        'enabled' => env('AI_API_KEY', '') !== '' && env('AI_API_URL', '') !== '',
        'api_key' => env('AI_API_KEY', ''),
        'api_url' => env('AI_API_URL', ''),
        'model'   => env('AI_MODEL', ''),
        'timeout' => max(1, (int) env('AI_TIMEOUT_SECONDS', 10)),
    ],

    'maps' => [
        'provider'              => env('MAP_PROVIDER', 'openstreetmap'),
        'geocoding_api_key'     => env('GEOCODING_API_KEY', ''),
        // In-app notification polling interval (10–15s recommended by MASTER_PROMPT §21).
        'poll_interval_seconds' => (int) env('MAP_POLL_INTERVAL_SECONDS', 12),
        'leaflet_css'           => env('LEAFLET_CSS_URL', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'),
        'leaflet_js'            => env('LEAFLET_JS_URL', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'),
    ],

    'charts' => [
        // Chart.js is loaded from a CDN for the admin analytics dashboard
        // (Phase 11). Charts degrade to accessible data tables when it is
        // unavailable — analytics never depend on a third-party script loading.
        'chartjs_js' => env('CHARTJS_JS_URL', 'https://unpkg.com/chart.js@4.4.1/dist/chart.umd.js'),
        'default_period' => env('ANALYTICS_DEFAULT_PERIOD', 'week'),
    ],

    'mail' => [
        'host'      => env('MAIL_HOST', ''),
        'port'      => (int) env('MAIL_PORT', 587),
        'username'  => env('MAIL_USERNAME', ''),
        'password'  => env('MAIL_PASSWORD', ''),
        'from'      => env('MAIL_FROM_ADDRESS', 'no-reply@resqlink.local'),
        'enabled'   => env('MAIL_HOST', '') !== '',
    ],

    'attachments' => [
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_bytes'    => 5 * 1024 * 1024,
        'directory'    => 'uploads/emergency',
    ],
];
