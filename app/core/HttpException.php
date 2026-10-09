<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Represents an HTTP-level error (404, 405, 403, ...) that should be rendered
 * as a safe page or JSON error response.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        public readonly string $errorCode = 'HTTP_ERROR',
        public readonly array $headers = []
    ) {
        parent::__construct($message !== '' ? $message : 'HTTP error ' . $statusCode);
    }

    public static function notFound(string $message = 'Page not found.'): self
    {
        return new self(404, $message, 'NOT_FOUND');
    }

    public static function methodNotAllowed(string $message = 'Method not allowed.'): self
    {
        return new self(405, $message, 'METHOD_NOT_ALLOWED');
    }

    public static function forbidden(string $message = 'You are not authorized to perform this action.'): self
    {
        return new self(403, $message, 'FORBIDDEN');
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self(401, $message, 'UNAUTHORIZED');
    }

    public static function badRequest(string $message = 'Bad request.'): self
    {
        return new self(400, $message, 'BAD_REQUEST');
    }
}
