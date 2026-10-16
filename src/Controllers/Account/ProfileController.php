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
use App\Services\TokenService;

/** The buyer's own account: profile, email address and password. */
final class ProfileController
{
    public function __construct(
        private readonly Customer $customers,
        private readonly CustomerToken $links,
        private readonly TokenService $tokens,
        private readonly RateLimiter $limiter,
        private readonly AccountMailer $mailer,
    ) {
    }

    /** GET /v1/account */
    public function show(Request $request): Response
    {
        return Response::data(Customer::present(AuthController::customer($request)));
    }

    /** PUT /v1/account: profile; a new email address needs the current password and is confirmed again. */
    public function update(Request $request): Response
    {
        $customer = AuthController::customer($request);
        $data = Validator::make($request->all(), [
            'email' => 'required|email',
            'company_name' => 'required|string|min:2|max:160',
            'contact_name' => 'required|string|min:2|max:120',
            'business_type' => 'required|in:' . implode(',', array_keys(Customer::BUSINESS_TYPES)),
            'country_code' => 'required|country',
            'phone' => 'required|phone',
            'website' => 'nullable|url|max:255',
            'current_password' => 'nullable|string|max:200',
        ])->validate();
        $data['website'] ??= null;
        $id = (int) $customer['id'];

        $emailChanged = Customer::normaliseEmail($data['email']) !== Customer::normaliseEmail((string) $customer['email']);
        if ($emailChanged) {
            $this->checkPassword($customer, $data['current_password'] ?? null, 'Enter your current password to change your email address.');
            if ($this->customers->emailTaken($data['email'], $id)) {
                throw new ValidationException(['email' => 'Another account already uses this email address.']);
            }
        }

        $this->customers->updateProfile($id, $data);
        if ($emailChanged) {
            $this->customers->changeEmail($id, $data['email']);
            $fresh = (array) $this->customers->find($id);
            $token = $this->links->issue($id, CustomerToken::VERIFY_EMAIL, (string) $fresh['email'], AccountMailer::VERIFY_HOURS * 3600);
            $this->mailer->verification($fresh, $token);
        }

        return Response::data(Customer::present((array) $this->customers->find($id)));
    }

    /** PUT /v1/account/password {current_password, password}: also logs out every other session. */
    public function changePassword(Request $request): Response
    {
        $customer = AuthController::customer($request);
        $data = Validator::make($request->all(), [
            'current_password' => 'required|string|max:200',
            'password' => 'required|string|min:8|max:200',
        ])->validate();

        $this->checkPassword($customer, $data['current_password'], 'Your current password is not correct.');
        if ($data['password'] === $data['current_password']) {
            throw new ValidationException(['password' => 'Choose a new password.']);
        }
        if (mb_strtolower($data['password']) === Customer::normaliseEmail((string) $customer['email'])) {
            throw new ValidationException(['password' => 'Choose a password that is not your email address.']);
        }

        $id = (int) $customer['id'];
        $this->customers->updatePassword($id, $data['password']);
        $this->tokens->revokeAll('customer', $id, (string) $request->attribute('token'));

        return Response::noContent();
    }

    /** @param array<string, mixed> $customer */
    private function checkPassword(array $customer, ?string $password, string $message): void
    {
        $bucket = 'customer-password:' . $customer['id'];
        if ($this->limiter->hit($bucket, 8, 900)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 900));
        }
        if ($password === null || !password_verify($password, (string) $customer['password_hash'])) {
            throw new ValidationException(['current_password' => $message]);
        }
        $this->limiter->clear($bucket);
    }
}
