<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Centralized error handling.
 *
 * Development: detailed output in the log (and, when APP_DEBUG=true, on screen
 * via the error page).
 * Production: generic, safe messages only — never stack traces, SQL, paths.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '0'); // we log ourselves

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Convert PHP errors/warnings into exceptions (respecting @-suppression).
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(Throwable $exception): void
    {
        $status = $exception instanceof HttpException ? $exception->statusCode : 500;
        $code = $exception instanceof HttpException ? $exception->errorCode : 'INTERNAL_ERROR';

        if ($status >= 500) {
            Logger::critical('Unhandled exception: ' . $exception->getMessage(), [
                'exception' => get_class($exception),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => self::$debug ? $exception->getTraceAsString() : '[hidden]',
            ]);
        } else {
            Logger::info('HTTP error: ' . $exception->getMessage(), ['status' => $status, 'code' => $code]);
        }

        self::render($status, $exception, $code);
    }

    /**
     * Catch fatal errors that bypass the exception handler.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        Logger::critical('Fatal error: ' . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        self::render(500, new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), 'INTERNAL_ERROR');
    }

    private static function render(int $status, Throwable $exception, string $code): void
    {
        if (headers_sent()) {
            return;
        }

        $response = self::wantsJson()
            ? Response::error(self::publicMessage($status, $exception), $code, $status)
            : Response::html(self::html($status, $exception), $status);

        SecurityHeaders::apply($response)->send();
    }

    private static function publicMessage(int $status, Throwable $exception): string
    {
        if ($exception instanceof HttpException && $exception->getMessage() !== '') {
            return $exception->getMessage();
        }

        return match ($status) {
            404 => 'Page not found.',
            405 => 'Method not allowed.',
            403 => 'You are not authorized to perform this action.',
            401 => 'Authentication required.',
            400 => 'Bad request.',
            default => 'Something went wrong. Please try again.',
        };
    }

    private static function html(int $status, Throwable $exception): string
    {
        try {
            $view = match (true) {
                $status === 404              => 'errors/404',
                $status === 401 || $status === 403 => 'errors/403',
                default                      => 'errors/500',
            };

            return View::render($view, [
                'status'    => $status,
                'message'   => self::publicMessage($status, $exception),
                'exception' => self::$debug ? $exception : null,
            ]);
        } catch (Throwable) {
            // Absolute fallback: never expose internals.
            return '<!doctype html><html><head><meta charset="utf-8"><title>Error</title></head>'
                . '<body style="font-family:sans-serif;padding:2rem"><h1>' . $status . '</h1>'
                . '<p>' . htmlspecialchars(self::publicMessage($status, $exception), ENT_QUOTES) . '</p></body></html>';
        }
    }

    private static function wantsJson(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_contains($uri, '/api/')) {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}
