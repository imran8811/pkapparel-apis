<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\CustomerToken;
use App\Services\AccountMailer;
use App\Services\CartService;
use App\Services\TokenService;

/**
 * Buyer signup, login, email confirmation and password reset.
 * The website keeps the session token in an HttpOnly cookie and sends it as a Bearer token.
 */
final class AuthController
{
    /** Buyers stay logged in for 30 days. */
    public const SESSION_SECONDS = 30 * 24 * 3600;

    private const WINDOW = 900; // 15 minutes

    public function __construct(
        private readonly Customer $customers,
        private readonly CustomerToken $links,
        private readonly TokenService $tokens,
        private readonly RateLimiter $limiter,
        private readonly AccountMailer $mailer,
        private readonly CartService $carts,
    ) {
    }

    /** POST /v1/account/signup */
    public function signup(Request $request): Response
    {
        $input = $request->all();

        // Honeypot: real visitors never see or fill this field.
        if (trim((string) ($input['fax_number'] ?? '')) !== '') {
            throw new ValidationException(['email' => 'The account could not be created. Please try again.']);
        }

        $data = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|string|min:8|max:200',
            'company_name' => 'required|string|min:2|max:160',
            'contact_name' => 'required|string|min:2|max:120',
            'business_type' => 'required|in:' . implode(',', array_keys(Customer::BUSINESS_TYPES)),
            'country_code' => 'required|country',
            'phone' => 'required|phone',
            'website' => 'nullable|url|max:255',
            'accept_terms' => 'required|bool',
            'cart_token' => 'nullable|string|max:64',
        ])->validate();

        if ($data['accept_terms'] !== true) {
            throw new ValidationException(['accept_terms' => 'Please accept the terms of sale and the privacy policy.']);
        }
        if (mb_strtolower($data['password']) === Customer::normaliseEmail($data['email'])) {
            throw new ValidationException(['password' => 'Choose a password that is not your email address.']);
        }

        $bucket = 'signup:' . $request->clientIp();
        if ($this->limiter->hit($bucket, 5, 3600)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
        }
        if ($this->customers->emailTaken($data['email'])) {
            throw new ValidationException(['email' => 'An account with this email already exists. Log in, or reset your password if you forgot it.']);
        }

        $id = $this->customers->create($data['email'], $data['password'], $data);
        $customer = $this->customers->find($id);
        if ($customer === null) {
            throw new \RuntimeException('The new account could not be read back.');
        }
        $this->sendVerification($customer);

        return Response::data($this->startSession($request, $customer, $data['cart_token'] ?? null), [], 201);
    }

    /** POST /v1/account/login {email, password} */
    public function login(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|max:200',
            'cart_token' => 'nullable|string|max:64',
        ])->validate();

        // Limit guessing per visitor and per account.
        $ipBucket = 'customer-login-ip:' . $request->clientIp();
        $accountBucket = 'customer-login:' . Customer::normaliseEmail($data['email']);
        foreach ([[$ipBucket, 20], [$accountBucket, 8]] as [$bucket, $max]) {
            if ($this->limiter->hit($bucket, $max, self::WINDOW)) {
                throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, self::WINDOW));
            }
        }

        $customer = $this->customers->findByEmail($data['email']);
        // Always verify a hash, so a wrong email takes as long as a wrong password.
        $hash = $customer['password_hash'] ?? password_hash('timing-equaliser', PASSWORD_DEFAULT);
        if ($customer === null || !password_verify($data['password'], $hash)) {
            throw new HttpException(401, 'invalid_credentials', 'The email or password is not correct.');
        }
        if ($customer['status'] !== 'active') {
            throw new HttpException(403, 'account_blocked', 'This account is blocked. Please contact us.');
        }

        $this->limiter->clear($accountBucket);
        $id = (int) $customer['id'];
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->customers->updatePassword($id, $data['password']);
        }
        $this->customers->recordLogin($id);

        return Response::data($this->startSession($request, $customer, $data['cart_token'] ?? null));
    }

    /** POST /v1/account/logout */
    public function logout(Request $request): Response
    {
        $this->tokens->revoke((string) $request->attribute('token'));

        return Response::noContent();
    }

    /** POST /v1/account/verify-email {token}: the link from the confirmation email. */
    public function verifyEmail(Request $request): Response
    {
        $token = (string) Validator::make($request->all(), ['token' => 'required|string|max:64'])->validate()['token'];

        $link = $this->links->consume(CustomerToken::VERIFY_EMAIL, $token);
        $customer = $link === null ? null : $this->customers->find($link['customer_id']);
        if ($link !== null && $customer !== null && Customer::normaliseEmail((string) $customer['email']) === Customer::normaliseEmail($link['email'])) {
            $this->customers->markVerified((int) $customer['id']);

            return Response::data(['verified' => true, 'email' => $customer['email']]);
        }

        // A link opened twice (or by an email scanner first) still counts when the address is confirmed.
        $used = $this->links->findUsed(CustomerToken::VERIFY_EMAIL, $token);
        $customer = $used === null ? null : $this->customers->find($used['customer_id']);
        if ($used !== null && $customer !== null && $customer['email_verified_at'] !== null
            && Customer::normaliseEmail((string) $customer['email']) === Customer::normaliseEmail($used['email'])) {
            return Response::data(['verified' => true, 'email' => $customer['email']]);
        }

        throw new HttpException(400, 'invalid_token', 'This link is not valid or has expired. Log in to get a new one.');
    }

    /** POST /v1/account/verify-email/resend (logged in) */
    public function resendVerification(Request $request): Response
    {
        $customer = self::customer($request);
        if ($customer['email_verified_at'] !== null) {
            throw new HttpException(409, 'already_verified', 'Your email address is already confirmed.');
        }
        $bucket = 'verify-resend:' . $customer['id'];
        if ($this->limiter->hit($bucket, 3, 3600)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
        }
        $this->sendVerification($customer);

        return Response::noContent();
    }

    /** POST /v1/account/forgot-password {email}: always the same answer, so it does not reveal who has an account. */
    public function forgotPassword(Request $request): Response
    {
        $email = (string) Validator::make($request->all(), ['email' => 'required|email'])->validate()['email'];

        foreach ([['forgot-ip:' . $request->clientIp(), 10], ['forgot:' . Customer::normaliseEmail($email), 3]] as [$bucket, $max]) {
            if ($this->limiter->hit($bucket, $max, 3600)) {
                throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
            }
        }

        $customer = $this->customers->findByEmail($email);
        if ($customer !== null && $customer['status'] === 'active') {
            $token = $this->links->issue((int) $customer['id'], CustomerToken::RESET_PASSWORD, (string) $customer['email'], AccountMailer::RESET_MINUTES * 60);
            $this->mailer->passwordReset($customer, $token);
        }

        return Response::noContent();
    }

    /** POST /v1/account/reset-password {token, password}: sets the password, confirms the email, logs out other sessions. */
    public function resetPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'token' => 'required|string|max:64',
            'password' => 'required|string|min:8|max:200',
            'cart_token' => 'nullable|string|max:64',
        ])->validate();

        $bucket = 'reset-ip:' . $request->clientIp();
        if ($this->limiter->hit($bucket, 20, 3600)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
        }

        $link = $this->links->consume(CustomerToken::RESET_PASSWORD, $data['token']);
        $customer = $link === null ? null : $this->customers->find($link['customer_id']);
        if ($link === null || $customer === null || $customer['status'] !== 'active') {
            throw new HttpException(400, 'invalid_token', 'This link is not valid or has expired. Please ask for a new one.');
        }
        if (mb_strtolower($data['password']) === Customer::normaliseEmail((string) $customer['email'])) {
            throw new ValidationException(['password' => 'Choose a password that is not your email address.']);
        }

        $id = (int) $customer['id'];
        $this->customers->updatePassword($id, $data['password']);
        if (Customer::normaliseEmail((string) $customer['email']) === Customer::normaliseEmail($link['email'])) {
            // The link reached this mailbox, which proves the address.
            $this->customers->markVerified($id);
        }
        $this->tokens->revokeAll('customer', $id);
        $this->customers->recordLogin($id);

        return Response::data($this->startSession($request, (array) $this->customers->find($id), $data['cart_token'] ?? null));
    }

    /**
     * @param array<string, mixed> $customer
     * @return array{token: string, expires_at: string, customer: array<string, mixed>}
     */
    private function startSession(Request $request, array $customer, ?string $cartToken): array
    {
        $id = (int) $customer['id'];
        if ($cartToken !== null && $cartToken !== '') {
            $this->carts->mergeGuest($cartToken, $id);
        }
        $this->tokens->purgeExpired();
        $session = $this->tokens->issue('customer', $id, self::SESSION_SECONDS, $request->clientIp(), $request->userAgent());

        return ['token' => $session['token'], 'expires_at' => $session['expires_at'], 'customer' => Customer::present($customer)];
    }

    /** @param array<string, mixed> $customer */
    private function sendVerification(array $customer): void
    {
        $token = $this->links->issue((int) $customer['id'], CustomerToken::VERIFY_EMAIL, (string) $customer['email'], AccountMailer::VERIFY_HOURS * 3600);
        $this->mailer->verification($customer, $token);
    }

    /** @return array<string, mixed> */
    public static function customer(Request $request): array
    {
        $customer = $request->attribute('customer');
        if (!is_array($customer)) {
            throw HttpException::unauthorized();
        }

        return $customer;
    }
}
