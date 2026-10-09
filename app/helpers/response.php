<?php

declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Response;

/**
 * JSON response helpers following the API.md envelope contract.
 */

if (!function_exists('json_success')) {
    function json_success(mixed $data = null, string $message = 'Operation completed successfully.', int $status = 200): Response
    {
        return Response::success($data, $message, $status);
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, string $code = 'INTERNAL_ERROR', int $status = 400, array $errors = []): Response
    {
        return Response::error($message, $code, $status, $errors);
    }
}

if (!function_exists('abort')) {
    /**
     * Throw an HTTP exception for the central error handler to render.
     *
     * @return never
     */
    function abort(int $status, string $message = '', string $code = 'HTTP_ERROR'): void
    {
        throw new HttpException($status, $message, $code);
    }
}
