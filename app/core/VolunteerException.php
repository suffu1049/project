<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Volunteer domain exception (Phase 5).
 *
 * Carries a machine-readable code plus the HTTP status for the transport
 * layer. Messages are safe to show to the user.
 */
class VolunteerException extends RuntimeException
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
        return new self('VOLUNTEER_PROFILE_NOT_FOUND', 'Volunteer profile not found.', 404);
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to perform this action.', 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }

    public static function invalidSkills(string $reason): self
    {
        return new self('INVALID_VOLUNTEER_SKILLS', $reason, 422, ['skills' => [$reason]]);
    }
}
