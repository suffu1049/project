<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish representation of the incoming HTTP request.
 *
 * Controllers read validated input from this object rather than touching
 * superglobals directly, which keeps them testable and consistent.
 */
final class Request
{
    private array $attributes = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $files,
        private readonly string $clientIp,
        private readonly string $userAgent,
    ) {
    }

    /**
     * Build a request from PHP superglobals.
     */
    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Allow method override via X-HTTP-Method-Override for JSON clients.
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '';
        if ($override !== '' && in_array(strtoupper($override), ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $method = strtoupper($override);
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = self::stripBasePath($path);

        $headers = self::collectHeaders();

        $body = $_POST;
        if (self::hasJsonContentType($headers)) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        return new self(
            $method,
            self::normalizePath($path),
            $_GET,
            $body,
            $headers,
            $_FILES,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Read a value from query string or body, with a default.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return $default;
    }

    /**
     * All merged input (query + body). Use with care — always validate.
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->all())) {
                $result[$key] = $this->input($key);
            }
        }

        return $result;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * All query-string parameters.
     *
     * @return array<string, mixed>
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function ip(): string
    {
        return $this->clientIp;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function isJsonContentType(): bool
    {
        return self::hasJsonContentType($this->headers);
    }

    /**
     * True when the client expects a JSON response.
     */
    public function wantsJson(): bool
    {
        if ($this->isJsonContentType()) {
            return true;
        }

        $accept = strtolower((string) ($this->headers['accept'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        if ($this->header('x-requested-with') === 'XMLHttpRequest') {
            return true;
        }

        return str_starts_with($this->path, '/api');
    }

    public function isSecure(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    }

    /**
     * Store a middleware-derived attribute (e.g. authenticated user id).
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Derive the base path when the app is installed in a subdirectory.
     */
    public static function basePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        // With the built-in server (router.php) the script name is the router,
        // so fall back to the app URL path when available.
        if (str_ends_with($script, 'router.php')) {
            $urlPath = parse_url((string) Config::get('app.url', ''), PHP_URL_PATH) ?: '';

            return rtrim($urlPath, '/');
        }

        $dir = str_replace('\\', '/', dirname($script));

        return ($dir === '/' || $dir === '.' || $dir === '\\') ? '' : rtrim($dir, '/');
    }

    private static function stripBasePath(string $path): string
    {
        $base = self::basePath();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path === '' ? '/' : $path;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    private static function collectHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private static function hasJsonContentType(array $headers): bool
    {
        $type = strtolower((string) ($headers['content-type'] ?? ''));

        return str_contains($type, 'application/json');
    }
}
