<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Only the PK Apparel website (Next.js server) may call the API.
 * It proves this by sending the shared secret in the X-Client-Key header.
 */
final class RequireClientKey implements Middleware
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $expected = $this->config->string('client_key');
        $given = $request->header('x-client-key') ?? '';

        if ($expected === '' || !hash_equals($expected, $given)) {
            throw new HttpException(401, 'invalid_client', 'This API only accepts requests from the PK Apparel website.');
        }

        return $next($request);
    }
}
