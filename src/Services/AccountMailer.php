<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Settings;

/**
 * Account emails: confirm the email address, reset the password. Links point to the website: to the stock pages
 * (/stock/…) for buyers of the stock, with the stock variant of the email layout.
 */
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

    /**
     * @param array<string, mixed> $customer
     * @param string|null $area "stock" or "wholesale": where the link leads (by default, where the buyer signed up)
     */
    public function verification(array $customer, string $token, ?string $area = null): bool
    {
        $stock = self::isStock($customer, $area);
        $link = $this->webUrl(($stock ? '/stock' : '') . '/verify-email?token=' . $token);

        return $this->mailer->send(
            (string) $customer['email'],
            'Confirm your email address | PK Apparel',
            $this->mailer->render('account-verify', $this->layoutData($stock) + [
                'customer' => $customer,
                'link' => $link,
                'hours' => self::VERIFY_HOURS,
                'preheader' => 'One click to confirm your PK Apparel buyer account.',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $customer
     * @param string|null $area "stock" or "wholesale": where the link leads (by default, where the buyer signed up)
     */
    public function passwordReset(array $customer, string $token, ?string $area = null): bool
    {
        $stock = self::isStock($customer, $area);
        $link = $this->webUrl(($stock ? '/stock' : '') . '/reset-password?token=' . $token);

        return $this->mailer->send(
            (string) $customer['email'],
            'Reset your password | PK Apparel',
            $this->mailer->render('password-reset', $this->layoutData($stock) + [
                'customer' => $customer,
                'link' => $link,
                'minutes' => self::RESET_MINUTES,
                'preheader' => 'A link to choose a new password for your PK Apparel account.',
            ]),
        );
    }

    /** @param array<string, mixed> $customer */
    private static function isStock(array $customer, ?string $area): bool
    {
        return ($area ?? ($customer['channel'] ?? 'wholesale')) === 'stock';
    }

    /** @return array<string, mixed> */
    private function layoutData(bool $stock): array
    {
        return ['contact' => $this->settings->get('contact', []), 'addresses' => $this->settings->get('addresses', []), 'stock' => $stock];
    }

    private function webUrl(string $path): string
    {
        return rtrim($this->config->string('web.url'), '/') . $path;
    }
}
