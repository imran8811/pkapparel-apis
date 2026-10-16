<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Validator;
use App\Middleware\RequireClientKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = App::create(dirname(__DIR__, 2));
        $this->app->container->set(Logger::class, static fn (): Logger => new Logger(sys_get_temp_dir() . '/pkapparel-api-tests'));

        $router = new Router();
        $router->get('/v1/ping', static fn (Request $r): array => ['pong' => true]);
        $router->get('/v1/boom', static function (): never {
            throw new RuntimeException('database exploded');
        });
        $router->post('/v1/validate', static fn (Request $r): array => Validator::make($r->all(), ['email' => 'required|email'])->validate());
        $router->get('/v1/items/{slug}', static fn (Request $r): Response => Response::data(['slug' => $r->param('slug')]));
        $router->get('/v1/private', static fn (): array => ['secret' => 'yes'], [RequireClientKey::class]);
        $this->app->useRouter($router);
    }

    public function testArrayResultsAreWrappedInData(): void
    {
        $response = $this->app->handle(new Request('GET', '/v1/ping'));

        self::assertSame(200, $response->status());
        self::assertSame(['data' => ['pong' => true]], $response->decoded());
    }

    public function testRouteParamsReachTheHandler(): void
    {
        $response = $this->app->handle(new Request('GET', '/v1/items/pk-1058'));

        self::assertSame(['data' => ['slug' => 'pk-1058']], $response->decoded());
    }

    public function testUnknownRouteGivesJson404(): void
    {
        $response = $this->app->handle(new Request('GET', '/v1/missing'));

        self::assertSame(404, $response->status());
        self::assertSame('route_not_found', $response->decoded()['error']['code']);
    }

    public function testUnexpectedErrorsAreHiddenBehindAReference(): void
    {
        $response = $this->app->handle(new Request('GET', '/v1/boom'));
        $error = $response->decoded()['error'];

        self::assertSame(500, $response->status());
        self::assertSame('server_error', $error['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $error['reference']);
        self::assertStringNotContainsString('database exploded', $error['message']);
    }

    public function testValidationErrorsAre422WithFields(): void
    {
        $request = new Request('POST', '/v1/validate', [], ['content-type' => 'application/json'], '{"email":"nope"}');
        $response = $this->app->handle($request);

        self::assertSame(422, $response->status());
        self::assertSame(['email' => 'Enter a valid email address.'], $response->decoded()['error']['fields']);
    }

    public function testInvalidJsonIs400(): void
    {
        $request = new Request('POST', '/v1/validate', [], ['content-type' => 'application/json'], '{broken');

        self::assertSame(400, $this->app->handle($request)->status());
    }

    public function testClientKeyIsRequired(): void
    {
        $missing = $this->app->handle(new Request('GET', '/v1/private'));
        self::assertSame(401, $missing->status());
        self::assertSame('invalid_client', $missing->decoded()['error']['code']);

        $key = $this->app->config()->string('client_key');
        self::assertNotSame('', $key, 'CLIENT_KEY must be set in api/.env');

        $allowed = $this->app->handle(new Request('GET', '/v1/private', [], ['x-client-key' => $key]));
        self::assertSame(200, $allowed->status());
    }
}
