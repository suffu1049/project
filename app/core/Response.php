<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response value object.
 *
 * Controllers return a Response; the front controller emits it. JSON
 * responses follow the contract in API.md (success/message/data).
 */
final class Response
{
    private function __construct(
        private int $status,
        private array $headers,
        private string $body,
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, $body);
    }

    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers,
            $body === false ? '{}' : $body
        );
    }

    /**
     * Standard success envelope: { success, message, data }.
     */
    public static function success(mixed $data = null, string $message = 'Operation completed successfully.', int $status = 200, array $extra = []): self
    {
        return self::json(array_merge([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $extra), $status);
    }

    /**
     * Standard error envelope: { success, message, errors?, code }.
     */
    public static function error(string $message, string $code = 'INTERNAL_ERROR', int $status = 400, array $errors = []): self
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $payload['code'] = $code;

        return self::json($payload, $status);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, ['Location' => $location], '');
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Emit the response to the client (headers + body).
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->status !== 204) {
            echo $this->body;
        }
    }
}
