<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/** Runs before a controller. Call $next($request) to continue, or return/throw to stop. */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response;
}
