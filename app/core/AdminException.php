<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Admin control center domain exception (Phase 9).
 *
 * Carries a machine-readable code plus the HTTP status for the transport
 * layer. Messages are safe to show to the user.
 */
class AdminException extends RuntimeException
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

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to perform this action.', 403);
    }

    public static function notFound(string $entity = 'Resource'): self
    {
        return new self('NOT_FOUND', $entity . ' not found.', 404);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }
}
