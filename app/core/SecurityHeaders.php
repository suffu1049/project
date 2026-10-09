<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Default security response headers (Phase 12 hardening).
 *
 * Applied to every response — including the central error handler's output —
 * unless a handler already set the same header. Deliberately conservative:
 * no Content-Security-Policy is sent because the map/chart/UI layer loads
 * scripts from a CDN and uses inline data blocks, and a policy that breaks the
 * emergency UI is worse than none. The remaining headers are safe and
 * universally supported.
 */
final class SecurityHeaders
{
    /** @var array<string, string> */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'geolocation=(self), camera=(), microphone=()',
    ];

    /**
     * Return the headers not already present on the response.
     *
     * @return array<string, string>
     */
    public static function missing(Response $response): array
    {
        $existing = array_change_key_case($response->headers(), CASE_LOWER);

        return array_filter(
            self::HEADERS,
            static fn (string $value, string $name): bool => !array_key_exists(strtolower($name), $existing),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Attach the default security headers to a response.
     */
    public static function apply(Response $response): Response
    {
        foreach (self::missing($response) as $name => $value) {
            $response->withHeader($name, $value);
        }

        return $response;
    }
}
