<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Resource inventory exception (Phase 6).
 *
 * Carries a machine-readable code plus the HTTP status for the transport
 * layer. Codes come from the API.md §62 error catalog (RESOURCE_NOT_FOUND,
 * INSUFFICIENT_RESOURCE, RESOURCE_NOT_AVAILABLE); messages are safe to show
 * to the user.
 */
class ResourceException extends RuntimeException
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
        return new self('RESOURCE_NOT_FOUND', 'Resource not found.', 404);
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to manage this resource.', 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }

    public static function notVerified(string $reason = 'An administrator must verify this organization before it can manage resources.'): self
    {
        return new self('ORGANIZATION_NOT_VERIFIED', $reason, 403, ['verification_status' => [$reason]]);
    }

    public static function insufficient(string $reason = 'The requested quantity exceeds the available quantity.'): self
    {
        return new self('INSUFFICIENT_RESOURCE', $reason, 409, ['quantity' => [$reason]]);
    }

    public static function unavailable(string $reason = 'This resource is marked unavailable.'): self
    {
        return new self('RESOURCE_NOT_AVAILABLE', $reason, 409, ['status' => [$reason]]);
    }

    public static function requestNotFound(): self
    {
        return new self('REQUEST_NOT_FOUND', 'Emergency request not found.', 404);
    }

    public static function requestClosed(string $reason = 'Resources can only be allocated to active emergency requests.'): self
    {
        return new self('INVALID_REQUEST_STATUS', $reason, 409, ['request_id' => [$reason]]);
    }
}