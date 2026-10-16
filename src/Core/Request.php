<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

/** An incoming HTTP request. Header names are stored in lower case. */
final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $headers = [],
        private readonly string $body = '',
        private readonly array $post = [],
        private readonly array $files = [],
        private readonly array $server = [],
    ) {
    }

    /** Build the request from PHP globals. $basePath lets the API live in a sub-folder (e.g. "/api"). */
    public static function fromGlobals(string $basePath = ''): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key])) {
                $headers[$name] = (string) $_SERVER[$key];
            }
        }
        if (!isset($headers['authorization'])) {
            $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if ($authorization === null && function_exists('apache_request_headers')) {
                foreach (apache_request_headers() as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        $authorization = $value;
                        break;
                    }
                }
            }
            if ($authorization !== null) {
                $headers['authorization'] = (string) $authorization;
            }
        }

        return new self(
            $method,
            self::normalizePath($path),
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            $_POST,
            $_FILES,
            $_SERVER,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');

        return preg_replace('#/+#', '/', $path) ?? $path;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type') ?? ''), 'application/json');
    }

    /** @return array<string, mixed> The decoded JSON body (an empty array when there is no body). */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if (trim($this->body) === '') {
            return $this->jsonCache = [];
        }

        try {
            $decoded = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('The request body is not valid JSON.', 'invalid_json');
        }
        if (!is_array($decoded)) {
            throw HttpException::badRequest('The request body must be a JSON object.', 'invalid_json');
        }

        return $this->jsonCache = $decoded;
    }

    /** @return array<string, mixed> All input fields: JSON body for JSON requests, form fields otherwise. */
    public function all(): array
    {
        return $this->isJson() ? $this->json() : $this->post;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * The visitor's IP address. The website calls the API from its server and
     * forwards the visitor's address in X-Client-IP. Only routes protected by the
     * client key may rely on this header.
     */
    public function clientIp(): string
    {
        $forwarded = trim((string) $this->header('x-client-ip'));

        return filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? $forwarded : $this->ip();
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('user-agent'), 0, 255);
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
}
