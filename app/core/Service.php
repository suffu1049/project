<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base service.
 *
 * Services hold business rules and coordinate models/repositories. They must
 * not render HTML and must not be tightly coupled to the HTTP layer.
 *
 * Concrete services (AuthService, EmergencyService, PriorityService, ...)
 * are introduced in later phases.
 */
abstract class Service
{
    /**
     * Write to the application log with an optional channel override.
     */
    protected function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        Logger::log($level, $message, $context, $channel);
    }
}
