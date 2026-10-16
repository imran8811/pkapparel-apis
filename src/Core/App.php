<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Middleware;
use LogicException;
use Throwable;

/**
 * Boots configuration and services, then sends each request through
 * Router → Middleware → Controller.
 */
final class App
{
    private ?Router $router = null;

    private function __construct(
        public readonly string $basePath,
        public readonly Container $container,
    ) {
    }

    public static function create(string $basePath): self
    {
        Env::load($basePath . '/.env');

        /** @var array<string, mixed> $settings */
        $settings = require $basePath . '/config/app.php';
        $config = new Config($settings);
        date_default_timezone_set('UTC');

        $container = new Container();
        $app = new self($basePath, $container);

        $container->instance(self::class, $app);
        $container->instance(Config::class, $config);
        $container->set(Database::class, static fn (): Database => new Database($config->get('db')));
        $container->set(Logger::class, static fn (): Logger => new Logger($basePath . '/storage/logs'));

        return $app;
    }

    public function config(): Config
    {
        return $this->container->get(Config::class);
    }

    public function router(): Router
    {
        if ($this->router === null) {
            $router = new Router();
            /** @var callable(Router): void $register */
            $register = require $this->basePath . '/routes/api.php';
            $register($router);
            $this->router = $router;
        }

        return $this->router;
    }

    /** Replace the route table (used by tests). */
    public function useRouter(Router $router): void
    {
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router()->match($request->method, $request->path);
            $request->setRouteParams($route['params']);

            $handler = $route['handler'];
            $pipeline = fn (Request $r): Response => $this->dispatch($handler, $r);

            foreach (array_reverse($route['middleware']) as $middlewareClass) {
                $next = $pipeline;
                $pipeline = function (Request $r) use ($middlewareClass, $next): Response {
                    $middleware = $this->container->get($middlewareClass);
                    if (!$middleware instanceof Middleware) {
                        throw new LogicException("[{$middlewareClass}] must implement " . Middleware::class . '.');
                    }

                    return $middleware->handle($r, $next);
                };
            }

            return $pipeline($request);
        } catch (Throwable $e) {
            return $this->container->get(ErrorHandler::class)->render($e, $request);
        }
    }

    public function run(): void
    {
        ErrorHandler::register();

        $request = Request::fromGlobals($this->config()->string('base_path'));
        $this->handle($request)->send($request->method === 'HEAD');
    }

    /** @param array{0: class-string, 1: string}|callable $handler */
    private function dispatch(array|callable $handler, Request $request): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            [$class, $method] = $handler;
            $result = $this->container->get($class)->{$method}($request);
        } else {
            $result = $handler($request);
        }

        return $result instanceof Response ? $result : Response::data($result);
    }
}
