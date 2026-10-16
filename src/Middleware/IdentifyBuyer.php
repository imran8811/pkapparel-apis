<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Services\TokenService;

/**
 * Cart routes, open to guests and buyers. A buyer is recognised by their token (Authorization: Bearer …),
 * a guest by the token of their cart cookie (X-Cart-Token). An expired buyer token is an error,
 * so the website can end the session instead of silently showing a guest cart.
 */
final class IdentifyBuyer implements Middleware
{
    public function __construct(private readonly TokenService $tokens, private readonly Customer $customers)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            $customerId = $this->tokens->resolve('customer', $token);
            $customer = $customerId === null ? null : $this->customers->find($customerId);
            if ($customer === null || $customer['status'] !== 'active') {
                throw HttpException::unauthorized('Your session has ended. Please log in again.', 'session_expired');
            }
            $request->setAttribute('customer', $customer);
        }

        $cartToken = $request->header('X-Cart-Token');
        if ($cartToken !== null && preg_match('/^[a-f0-9]{64}$/', $cartToken) === 1) {
            $request->setAttribute('cart_token', $cartToken);
        }

        return $next($request);
    }
}
