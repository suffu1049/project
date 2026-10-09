<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Matching domain exception (Phase 7).
 *
 * Carries a machine-readable code plus the HTTP status for the transport
 * layer. Messages are safe to show to the user.
 */
class MatchingException extends RuntimeException
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
        return new self('MATCH_NOT_FOUND', 'Match not found.', 404);
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to manage this match.', 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }

    public static function requestNotFound(): self
    {
        return new self('REQUEST_NOT_FOUND', 'Emergency request not found.', 404);
    }

    public static function requestClosed(string $reason = 'This request is no longer open for matching.'): self
    {
        return new self('INVALID_REQUEST_STATUS', $reason, 409, ['request_id' => [$reason]]);
    }

    public static function conflict(string $reason): self
    {
        return new self('MATCH_CONFLICT', $reason, 409);
    }

    public static function notEligible(string $reason): self
    {
        return new self('MATCH_NOT_ELIGIBLE', $reason, 409);
    }

    public static function invalidStatus(string $reason): self
    {
        return new self('VALIDATION_ERROR', $reason, 422, ['status' => [$reason]]);
    }
}
