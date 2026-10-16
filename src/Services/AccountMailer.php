<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Settings;

/** Account emails: confirm the email address, reset the password. Links point to the website. */
final class AccountMailer
{
    public const VERIFY_HOURS = 48;

    public const RESET_MINUTES = 60;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Settings $settings,
    ) {
    }

    /** @param array<string, mixed> $customer */
    public function verification(array $customer, string $token): bool
    {
        $link = $this->webUrl('/verify-email?token=' . $token);

        return $this->mailer->send(
            (string) $customer['email'],
            'Confirm your email address | PK Apparel',
            $this->mailer->render('account-verify', $this->layoutData() + [
                'customer' => $customer,
                'link' => $link,
                'hours' => self::VERIFY_HOURS,
                'preheader' => 'One click to confirm your PK Apparel buyer account.',
            ]),
        );
    }

    /** @param array<string, mixed> $customer */
    public function passwordReset(array $customer, string $token): bool
    {
        $link = $this->webUrl('/reset-password?token=' . $token);

        return $this->mailer->send(
            (string) $customer['email'],
            'Reset your password | PK Apparel',
            $this->mailer->render('password-reset', $this->layoutData() + [
                'customer' => $customer,
                'link' => $link,
                'minutes' => self::RESET_MINUTES,
                'preheader' => 'A link to choose a new password for your PK Apparel account.',
            ]),
        );
    }

    /** @return array<string, mixed> */
    private function layoutData(): array
    {
        return ['contact' => $this->settings->get('contact', []), 'addresses' => $this->settings->get('addresses', [])];
    }

    private function webUrl(string $path): string
    {
        return rtrim($this->config->string('web.url'), '/') . $path;
    }
}
