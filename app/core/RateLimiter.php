<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Lightweight file-based rate limiter (Phase 12 hardening).
 *
 * Used to blunt credential-stuffing / brute-force against the authentication
 * endpoints without introducing an external dependency (no Redis in the MVP
 * stack). Attempts are stored as timestamps in a sliding window, one JSON file
 * per key under `storage/cache/rate_limit/`, guarded with an exclusive lock.
 *
 * This is a per-host guard, not a distributed one: it is intentionally simple
 * and degrades to "allow" if the storage directory is not writable, so a
 * read-only filesystem can never lock legitimate users out.
 */
final class RateLimiter
{
    private const DIRECTORY = 'cache/rate_limit';

    /**
     * True when the key has reached (or exceeded) its allowance in the window.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return count(self::attempts($key, $decaySeconds)) >= max(1, $maxAttempts);
    }

    /**
     * Record one attempt and return the number of attempts now in the window.
     */
    public static function hit(string $key, int $decaySeconds): int
    {
        $attempts = self::attempts($key, $decaySeconds);
        $attempts[] = time();

        self::write($key, $attempts);

        return count($attempts);
    }

    /**
     * Forget a key's attempts (e.g. after a successful sign-in).
     */
    public static function clear(string $key): void
    {
        $path = self::path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Seconds until the oldest attempt leaves the window (0 when not limited).
     */
    public static function availableIn(string $key, int $decaySeconds): int
    {
        $attempts = self::attempts($key, $decaySeconds);

        if ($attempts === []) {
            return 0;
        }

        return max(0, (min($attempts) + max(1, $decaySeconds)) - time());
    }

    /**
     * Drop every stored counter (used by tests and `migrate --fresh`).
     */
    public static function flush(): void
    {
        $directory = self::directory();

        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Attempts recorded for a key inside the sliding window.
     *
     * @return array<int, int>
     */
    public static function attempts(string $key, int $decaySeconds): array
    {
        $raw = @file_get_contents(self::path($key));

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $cutoff = time() - max(1, $decaySeconds);
        $attempts = [];

        foreach ($decoded as $timestamp) {
            if (is_int($timestamp) && $timestamp > $cutoff) {
                $attempts[] = $timestamp;
            }
        }

        return $attempts;
    }

    /**
     * @param array<int, int> $attempts
     */
    private static function write(string $key, array $attempts): void
    {
        $directory = self::directory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return; // Fail open: never block a user because storage is read-only.
        }

        $payload = json_encode(array_values($attempts), JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $path = self::path($key);

        try {
            file_put_contents($path, $payload, LOCK_EX);
        } catch (RuntimeException) {
            // Fail open (see above).
        }
    }

    private static function path(string $key): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    }

    private static function directory(): string
    {
        return storage_path(self::DIRECTORY);
    }
}
