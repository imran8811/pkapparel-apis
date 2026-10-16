<?php

declare(strict_types=1);

namespace App\Core;

/** An HTTP response. Every JSON response follows {"data": …} or {"error": …}. */
final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly string $body = '',
    ) {
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($payload, self::JSON_FLAGS),
        );
    }

    /** @param array<string, mixed> $meta */
    public static function data(mixed $data, array $meta = [], int $status = 200): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    /** @param array<string, string> $fields */
    public static function error(string $code, string $message, int $status, array $fields = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return self::json(['error' => $error], $status);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, [$name => $value] + $this->headers, $this->body);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed> The decoded JSON body (handy in tests). */
    public function decoded(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function send(bool $withoutBody = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if (!$withoutBody && $this->status !== 204) {
            echo $this->body;
        }
    }
}
