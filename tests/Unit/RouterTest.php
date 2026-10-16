<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/v1/health', ['HealthController', 'show']);

        $match = $router->match('GET', '/v1/health');

        self::assertSame(['HealthController', 'show'], $match['handler']);
        self::assertSame([], $match['params']);
    }

    public function testExtractsParametersAndHonoursConstraints(): void
    {
        $router = new Router();
        $router->get('/v1/orders/{id:\d+}', ['OrderController', 'show']);
        $router->get('/v1/products/{slug}', ['ProductController', 'show']);

        self::assertSame(['id' => '42'], $router->match('GET', '/v1/orders/42')['params']);
        self::assertSame(['slug' => 'pk-1058'], $router->match('GET', '/v1/products/pk-1058/')['params']);

        $this->expectException(HttpException::class);
        $router->match('GET', '/v1/orders/abc');
    }

    public function testLiteralDotsAreNotWildcards(): void
    {
        $router = new Router();
        $router->get('/sitemap.xml', ['SitemapController', 'show']);

        self::assertSame([], $router->match('GET', '/sitemap.xml')['params']);

        $this->expectException(HttpException::class);
        $router->match('GET', '/sitemapXxml');
    }

    public function testUnknownPathIs404(): void
    {
        $router = new Router();

        try {
            $router->match('GET', '/nope');
            self::fail('Expected a 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('route_not_found', $e->errorCode);
        }
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $router = new Router();
        $router->post('/v1/cart', ['CartController', 'add']);
        $router->delete('/v1/cart', ['CartController', 'clear']);

        try {
            $router->match('GET', '/v1/cart');
            self::fail('Expected a 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('POST, DELETE', $e->headers['Allow']);
        }
    }

    public function testHeadIsAnsweredByGetRoutes(): void
    {
        $router = new Router();
        $router->get('/v1/health', ['HealthController', 'show']);

        self::assertSame(['HealthController', 'show'], $router->match('HEAD', '/v1/health')['handler']);
    }

    public function testGroupsPrefixPathsAndStackMiddleware(): void
    {
        $router = new Router();
        $router->group('/v1', static function (Router $r): void {
            $r->group('/admin', static function (Router $r): void {
                $r->get('/orders', ['AdminOrderController', 'index'], ['RoleMiddleware']);
            }, ['StaffMiddleware']);
            $r->get('/site', ['SiteController', 'show']);
        }, ['ClientKeyMiddleware']);

        $admin = $router->match('GET', '/v1/admin/orders');
        self::assertSame(['ClientKeyMiddleware', 'StaffMiddleware', 'RoleMiddleware'], $admin['middleware']);

        $site = $router->match('GET', '/v1/site');
        self::assertSame(['ClientKeyMiddleware'], $site['middleware']);
    }
}
