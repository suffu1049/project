<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Simple configuration repository.
 *
 * Each file in `config/` must return an array; it is stored under the
 * file's basename, e.g. config/app.php -> config('app.env').
 */
final class Config
{
    private static array $items = [];

    /**
     * Load every `*.php` file in the given directory.
     */
    public static function load(string $directory): void
    {
        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $value = require $file;

            if (is_array($value)) {
                self::$items[$key] = $value;
            }
        }
    }

    /**
     * Retrieve a value using dot notation, e.g. config('database.host').
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    public static function all(): array
    {
        return self::$items;
    }
}
