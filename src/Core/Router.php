<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Maps "METHOD /path/{param}" to a handler. Placeholders match one path segment
 * by default; use {id:\d+} for a custom pattern. Groups share a prefix and middleware.
 *
 * @phpstan-type Handler array{0: class-string, 1: string}|callable
 * @phpstan-type Route array{method: string, path: string, regex: string, params: list<string>, handler: Handler, middleware: list<class-string>}
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<class-string> */
    private array $groupMiddleware = [];

    /** @param Handler $handler @param list<class-string> $middleware */
    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param Handler $handler @param list<class-string> $middleware */
    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param Handler $handler @param list<class-string> $middleware */
    public function put(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param Handler $handler @param list<class-string> $middleware */
    public function patch(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param Handler $handler @param list<class-string> $middleware */
    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param Handler $handler @param list<class-string> $middleware */
    public function add(string $method, string $path, array|callable $handler, array $middleware = []): void
    {
        $fullPath = Request::normalizePath($this->prefix . '/' . trim($path, '/'));
        [$regex, $params] = self::compile($fullPath);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    /**
     * @param callable(self): void $routes
     * @param list<class-string>   $middleware
     */
    public function group(string $prefix, callable $routes, array $middleware = []): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];

        try {
            $routes($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * @return array{handler: Handler, middleware: list<class-string>, params: array<string, string>}
     * @throws HttpException 404 when nothing matches, 405 when only the method is wrong
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = Request::normalizePath($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $methodMatches = $route['method'] === $method || ($method === 'HEAD' && $route['method'] === 'GET');
            if (!$methodMatches) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name];
            }

            return ['handler' => $route['handler'], 'middleware' => $route['middleware'], 'params' => $params];
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowed)));
        }

        throw HttpException::notFound('This API route does not exist.', 'route_not_found');
    }

    /** @return list<array{method: string, path: string}> */
    public function routes(): array
    {
        return array_map(static fn (array $route): array => ['method' => $route['method'], 'path' => $route['path']], $this->routes);
    }

    /** @return array{0: string, 1: list<string>} */
    private static function compile(string $path): array
    {
        $params = [];
        $parts = preg_split('#(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\})#', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$path];

        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$#', $part, $m)) {
                $params[] = $m[1];
                $regex .= '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return ['#^' . $regex . '$#', $params];
    }
}
