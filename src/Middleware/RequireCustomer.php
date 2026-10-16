<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Services\TokenService;

/** Buyer account routes: requires a valid buyer token (Authorization: Bearer …) of an account that is not blocked. */
final class RequireCustomer implements Middleware
{
    public function __construct(private readonly TokenService $tokens, private readonly Customer $customers)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        $customerId = $token === null ? null : $this->tokens->resolve('customer', $token);
        $customer = $customerId === null ? null : $this->customers->find($customerId);

        if ($customer === null || $customer['status'] !== 'active') {
            throw HttpException::unauthorized('Your session has ended. Please log in again.', 'session_expired');
        }

        $request->setAttribute('customer', $customer);
        $request->setAttribute('token', $token);

        return $next($request);
    }
}
