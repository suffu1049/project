<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Authentication / authorization domain exception.
 *
 * Carries a machine-readable code from the API.md error catalog plus the HTTP
 * status the transport layer should use. Messages are always safe to show to
 * the user (no internals, no user enumeration).
 */
class AuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    /**
     * Public accessor for the user-facing message (Exception::$message is
     * protected and cannot be read directly outside the class hierarchy).
     */
    public function userMessage(): string
    {
        return $this->message;
    }

    public static function invalidCredentials(): self
    {
        return new self('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
    }

    public static function suspended(): self
    {
        return new self('ACCOUNT_SUSPENDED', 'This account has been suspended. Contact an administrator.', 403);
    }

    public static function emailExists(): self
    {
        return new self('EMAIL_ALREADY_EXISTS', 'An account with this email already exists.', 409, [
            'email' => ['An account with this email already exists.'],
        ]);
    }

    public static function invalidRole(): self
    {
        return new self('INVALID_ROLE', 'That role cannot be selected during registration.', 422, [
            'role' => ['Invalid role.'],
        ]);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }
}
