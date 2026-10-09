<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal, dependency-free .env loader.
 *
 * Loads KEY=VALUE pairs into getenv()/$_ENV/$_SERVER. Values already present
 * in the real environment are never overwritten, so server/CI configuration
 * always wins over the local file.
 */
final class Env
{
    /** Cache of parsed values for fast repeated lookups. */
    private static array $values = [];

    private static bool $loaded = false;

    /**
     * Parse and register a .env file. Missing files are ignored so the
     * application can still boot from real environment variables.
     */
    public static function load(string $file): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = self::normalize($value);

            if ($key === '') {
                continue;
            }

            self::$values[$key] = $value;

            // Do not clobber variables provided by the real environment.
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Read a value (real environment first, then parsed file).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            $value = self::$values[$key] ?? null;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return self::cast($value);
    }

    /**
     * Trim whitespace and remove matching surrounding quotes.
     */
    private static function normalize(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return $value;
    }

    /**
     * Convert common string literals to native PHP types.
     */
    private static function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
