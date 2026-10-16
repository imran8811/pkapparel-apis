<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\StaffUser;
use App\Services\TokenService;

/** Admin routes: requires a valid staff token (Authorization: Bearer …) of an active account. */
final class RequireStaff implements Middleware
{
    public function __construct(private readonly TokenService $tokens, private readonly StaffUser $staff)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        $userId = $token === null ? null : $this->tokens->resolve('staff', $token);
        $user = $userId === null ? null : $this->staff->find($userId);

        if ($user === null || (int) $user['is_active'] !== 1) {
            throw HttpException::unauthorized('Your session has ended. Please log in again.', 'session_expired');
        }

        $request->setAttribute('staff', StaffUser::present($user));
        $request->setAttribute('token', $token);

        return $next($request);
    }
}
