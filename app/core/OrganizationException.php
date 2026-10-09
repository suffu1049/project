<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Organization domain exception (Phase 6).
 *
 * Carries a machine-readable code plus the HTTP status for the transport
 * layer. Codes come from the API.md §62 error catalog; messages are safe to
 * show to the user.
 */
class OrganizationException extends RuntimeException
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
        return new self('ORGANIZATION_NOT_FOUND', 'Organization profile not found.', 404);
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to perform this action.', 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }

    public static function notVerified(string $reason = 'An administrator must verify this organization before it can manage resources.'): self
    {
        return new self('ORGANIZATION_NOT_VERIFIED', $reason, 403, ['verification_status' => [$reason]]);
    }

    public static function invalidVerificationStatus(string $reason): self
    {
        return new self('VALIDATION_ERROR', $reason, 422, ['verification_status' => [$reason]]);
    }
}