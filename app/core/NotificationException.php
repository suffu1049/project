<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Notification domain exception (Phase 8).
 */
class NotificationException extends RuntimeException
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
        return new self('NOTIFICATION_NOT_FOUND', 'Notification not found.', 404);
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'You are not allowed to perform this action.', 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): self
    {
        return new self('VALIDATION_ERROR', $message, 422, $errors);
    }

    public static function failed(string $message = 'Unable to complete the notification action.'): self
    {
        return new self('NOTIFICATION_FAILED', $message, 500);
    }
}
