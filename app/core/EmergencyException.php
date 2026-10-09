<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Emergency request domain exception.
 *
 * Carries a machine-readable code from the API.md error catalog plus the HTTP
 * status the transport layer should use. Messages are always safe to show to
 * the user.
 */
class EmergencyException extends RuntimeException
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

    public static function notFound(): self
    {
        return new self('REQUEST_NOT_FOUND', 'Emergency request not found.', 404);
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to perform this action.', 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(
            'INVALID_REQUEST_STATUS',
            sprintf('Cannot change request status from %s to %s.', $from, $to),
            409
        );
    }

    public static function alreadyCancelled(): self
    {
        return new self('REQUEST_CANCELLED', 'This request has already been cancelled.', 409);
    }

    public static function alreadyResolved(): self
    {
        return new self('REQUEST_ALREADY_RESOLVED', 'This request has already been resolved.', 409);
    }

    public static function cannotEdit(): self
    {
        return new self(
            'INVALID_REQUEST_STATUS',
            'This request can no longer be edited in its current status.',
            409
        );
    }

    public static function attachmentRejected(string $reason): self
    {
        return new self('VALIDATION_ERROR', $reason, 422, ['file' => [$reason]]);
    }
}
