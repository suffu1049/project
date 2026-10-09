<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight file logger.
 *
 * Writes one line per entry to `storage/logs/{channel}-Y-m-d.log`.
 * Logging must never throw into the request lifecycle, so all filesystem
 * failures are swallowed after a best-effort attempt.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    /**
     * Sensitive keys that must never be written to logs.
     */
    private const REDACT = [
        'password', 'password_hash', 'password_confirmation', 'token',
        'csrf', 'csrf_token', 'api_key', 'secret', 'authorization',
    ];

    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        try {
            $path = self::path($channel);
            $line = sprintf(
                "[%s] %s.%s: %s%s\n",
                date('Y-m-d H:i:s'),
                strtoupper($channel),
                strtoupper($level),
                $message,
                $context === [] ? '' : ' ' . self::encode($context)
            );

            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Never let logging break the application.
        }
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    private static function path(string $channel): string
    {
        $directory = storage_path('logs');

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-z0-9_\-]/i', '_', $channel)
            . '-' . date('Y-m-d') . '.log';
    }

    /**
     * Redact sensitive context values and encode to JSON.
     */
    private static function encode(array $context): string
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redactArray($value);
            } elseif (is_object($value)) {
                $context[$key] = get_class($value);
            }
        }

        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function redactArray(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redactArray($value);
            }
        }

        return $context;
    }
}
