<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * AI assistance domain exception (Phase 10).
 *
 * Carries a machine-readable code plus the HTTP status for the transport
 * layer. Messages are always safe to show to the user and never contain
 * provider details, prompts or secrets.
 *
 * Callers are expected to catch this and fall back to the deterministic
 * workflow — an AI failure must never block emergency creation.
 */
class AIException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->message;
    }

    public static function unavailable(): self
    {
        return new self(
            'AI_UNAVAILABLE',
            'AI assistance is unavailable right now. You can continue without it.',
            503
        );
    }

    public static function timeout(): self
    {
        return new self(
            'AI_TIMEOUT',
            'AI assistance timed out. You can continue without it.',
            504
        );
    }

    public static function invalidResponse(string $reason = 'AI returned an unusable response.'): self
    {
        return new self('AI_INVALID_RESPONSE', $reason, 502);
    }

    public static function rateLimited(): self
    {
        return new self(
            'AI_RATE_LIMITED',
            'AI assistance is temporarily rate limited. You can continue without it.',
            429
        );
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }
}
