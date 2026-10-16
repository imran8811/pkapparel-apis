<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\StaffUser;
use App\Services\TokenService;

/** Staff login for the admin panel. Tokens are kept by the website in an HttpOnly cookie. */
final class AuthController extends AdminController
{
    /** Sessions last 7 days; deactivating an account ends its sessions at once (RequireStaff checks every request). */
    public const SESSION_SECONDS = 7 * 24 * 3600;

    private const WINDOW = 900; // 15 minutes

    public function __construct(
        private readonly StaffUser $staff,
        private readonly TokenService $tokens,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** POST /v1/admin/auth/login {email, password} */
    public function login(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|max:200',
        ])->validate();

        // Limit guessing per visitor and per account.
        $ipBucket = 'staff-login-ip:' . $request->clientIp();
        $accountBucket = 'staff-login:' . mb_strtolower($data['email']);
        foreach ([[$ipBucket, 20], [$accountBucket, 8]] as [$bucket, $max]) {
            if ($this->limiter->hit($bucket, $max, self::WINDOW)) {
                throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, self::WINDOW));
            }
        }

        $user = $this->staff->findByEmail($data['email']);
        // Always verify a hash, so a wrong email takes as long as a wrong password.
        $hash = $user['password_hash'] ?? password_hash('timing-equaliser', PASSWORD_DEFAULT);
        if ($user === null || !password_verify($data['password'], $hash) || (int) $user['is_active'] !== 1) {
            throw new HttpException(401, 'invalid_credentials', 'The email or password is not correct.');
        }

        $this->limiter->clear($accountBucket);
        $id = (int) $user['id'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->staff->updatePassword($id, $data['password']);
        }
        $this->staff->recordLogin($id);
        $this->tokens->purgeExpired();

        $session = $this->tokens->issue('staff', $id, self::SESSION_SECONDS, $request->clientIp(), $request->userAgent());

        return Response::data([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'staff' => StaffUser::present($user),
        ]);
    }

    /** POST /v1/admin/auth/logout */
    public function logout(Request $request): Response
    {
        $this->tokens->revoke((string) $request->attribute('token'));

        return Response::noContent();
    }

    /** GET /v1/admin/auth/me */
    public function me(Request $request): Response
    {
        return Response::data($this->staff($request));
    }

    /** PUT /v1/admin/auth/password {current_password, password}: also logs out every other session. */
    public function changePassword(Request $request): Response
    {
        $staff = $this->staff($request);
        $data = Validator::make($request->all(), [
            'current_password' => 'required|string|max:200',
            'password' => 'required|string|min:10|max:200',
        ])->validate();

        $bucket = 'staff-password:' . $staff['id'];
        if ($this->limiter->hit($bucket, 8, self::WINDOW)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, self::WINDOW));
        }

        $user = $this->staff->find($staff['id']);
        if ($user === null || !password_verify($data['current_password'], (string) $user['password_hash'])) {
            throw new ValidationException(['current_password' => 'Your current password is not correct.']);
        }
        if ($data['password'] === $data['current_password']) {
            throw new ValidationException(['password' => 'Choose a password you have not used here before.']);
        }

        $this->staff->updatePassword($staff['id'], $data['password']);
        $this->tokens->revokeAll('staff', $staff['id'], (string) $request->attribute('token'));
        $this->limiter->clear($bucket);

        return Response::noContent();
    }
}
