<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\CustomizationOption;
use App\Models\Settings;
use App\Models\ShippingZone;
use App\Models\StaffUser;

/**
 * What is still missing before the shop takes orders (owner decision 2026-10-17), shown to admins on the dashboard.
 * Most items are checked from the settings and data; the rest are ticked by hand (stored in the launch_checklist setting).
 */
final class LaunchChecklist
{
    /** Items the website cannot check: key => [label, admin page]. */
    public const MANUAL = [
        'legal_review' => ['A lawyer has checked the terms of sale, privacy and cookie pages', '/admin/pages'],
        'photos' => ['Real photos of the factory and products are on the website', '/admin/factory'],
        'search_console' => ['The site is verified in Google Search Console and Bing, and the sitemap is submitted', null],
        'test_order' => ['A test order was placed, paid and confirmed on the live website', '/admin/orders'],
    ];

    /** The daily job counts as running when it ran within this many hours. */
    private const DAILY_JOB_HOURS = 36;

    public function __construct(
        private readonly Settings $settings,
        private readonly ShippingZone $zones,
        private readonly CustomizationOption $addons,
        private readonly StaffUser $staff,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Database $db,
    ) {
    }

    /** @return list<array{key: string, label: string, done: bool, note: ?string, href: ?string, manual: bool}> */
    public function items(): array
    {
        $company = $this->group('company');
        $bank = $this->group('bank');
        $facts = $this->group('factory_facts');
        $zones = $this->zones->all();
        $modes = static fn (array $zone): array => array_unique(array_column($zone['rates'], 'mode'));
        $withoutCourier = array_column(array_filter($zones, static fn (array $zone): bool => !in_array('courier', $modes($zone), true)), 'name');
        $unpriced = array_column(array_filter(
            $this->addons->active(),
            static fn (array $addon): bool => $addon['pricing'] !== 'on_request' && $addon['price_cents'] === null,
        ), 'name');
        $admins = array_filter($this->staff->all(), static fn (array $row): bool => $row['role'] === 'admin' && (bool) $row['is_active']);
        $exampleAdmins = array_column(array_filter($admins, static fn (array $row): bool => self::isExampleEmail((string) $row['email'])), 'email');
        $expired = array_column($this->db->fetchAll(
            'SELECT name FROM certifications WHERE is_published = 1 AND valid_until IS NOT NULL AND valid_until < ? ORDER BY name',
            [gmdate('Y-m-d')],
        ), 'name');
        $lastRun = $this->group('daily_job')['last_run_at'] ?? null;
        $smtp = $this->config->string('mail.driver', 'log') !== 'log' && $this->mailer->smtpConfigured();

        $items = [
            self::item('company', "The company's legal name, printed on invoices", trim((string) ($company['legal_name'] ?? '')) !== '', null, '/admin/settings#company'),
            self::item('bank', 'Bank details for transfers', ($bank['account_number'] ?? null) !== null || ($bank['iban'] ?? null) !== null, null, '/admin/settings#bank'),
            self::item(
                'freight',
                'Air or sea freight rates',
                array_filter($zones, static fn (array $zone): bool => array_intersect(['air', 'sea'], $modes($zone)) !== []) !== [],
                $zones === [] ? 'No shipping zones yet: checkout says the freight will be quoted.' : null,
                '/admin/shipping',
            ),
            self::item(
                'courier',
                'Courier rates for sample orders',
                $zones !== [] && $withoutCourier === [],
                $withoutCourier === [] ? null : 'Missing in: ' . implode(', ', $withoutCourier) . '. Sample orders there wait for you to price the courier.',
                '/admin/shipping',
            ),
            self::item('addons', 'Add-on prices', $unpriced === [], $unpriced === [] ? null : 'No price yet: ' . implode(', ', $unpriced) . '.', '/admin/addons'),
            self::item(
                'email',
                'Email sending',
                $smtp,
                $smtp ? null : "Emails are only written to the API's log file. Paste the Gmail App Password into the API's .env file.",
                null,
            ),
            self::item(
                'admin',
                'Your own admin account',
                count($admins) > count($exampleAdmins) && $exampleAdmins === [],
                count($admins) === count($exampleAdmins)
                    ? 'Add an admin account with your own email.'
                    : 'Switch off the account with the example email (' . implode(', ', $exampleAdmins) . ').',
                '/admin/staff',
            ),
            self::item('certificates', 'Certificates up to date', $expired === [], $expired === [] ? null : 'Expired: ' . implode(', ', $expired) . '.', '/admin/certificates'),
            self::item(
                'factory',
                'Factory numbers: machines, workers and QA staff',
                ($facts['machines'] ?? '') !== '' && ($facts['workers'] ?? '') !== '' && ($facts['qa_staff'] ?? '') !== '',
                'They stay hidden on the website until you enter them.',
                '/admin/settings#factory',
            ),
            self::item(
                'debug_off',
                'Error details are hidden from the public',
                !$this->config->bool('debug'),
                "Set APP_DEBUG=false in the API's .env file on the live server.",
                null,
            ),
            self::item(
                'daily_job',
                'The daily job runs (cron)',
                is_string($lastRun) && strtotime($lastRun) >= time() - self::DAILY_JOB_HOURS * 3600,
                is_string($lastRun) ? 'Last run: ' . gmdate('j M Y, H:i', (int) strtotime($lastRun)) . ' UTC.' : 'It has not run yet. See the API README for the cron job.',
                null,
            ),
        ];

        $ticked = $this->group('launch_checklist');
        foreach (self::MANUAL as $key => [$label, $href]) {
            $items[] = ['key' => $key, 'label' => $label, 'done' => isset($ticked[$key]), 'note' => null, 'href' => $href, 'manual' => true];
        }

        return $items;
    }

    /**
     * Ticks or unticks an item the website cannot check.
     *
     * @param array{id: int, name: string} $staff
     */
    public function tick(string $key, bool $done, array $staff): void
    {
        if (!isset(self::MANUAL[$key])) {
            throw HttpException::notFound('This checklist item is checked automatically.');
        }
        $ticked = $this->group('launch_checklist');
        if ($done) {
            $ticked[$key] = ['at' => gmdate(DATE_ATOM), 'by' => $staff['name']];
        } else {
            unset($ticked[$key]);
        }
        $this->settings->set('launch_checklist', $ticked, false);
    }

    /** Emails like the README's example (you@example.com) or test accounts (…@example.test). */
    private static function isExampleEmail(string $email): bool
    {
        return (bool) preg_match('/@example\.(com|org|net|test)$/i', $email);
    }

    /** @return array<string, mixed> */
    private function group(string $name): array
    {
        $value = $this->settings->get($name, []);

        return is_array($value) ? $value : [];
    }

    /** @return array{key: string, label: string, done: bool, note: ?string, href: ?string, manual: bool} */
    private static function item(string $key, string $label, bool $done, ?string $note, ?string $href): array
    {
        return ['key' => $key, 'label' => $label, 'done' => $done, 'note' => $done ? null : $note, 'href' => $href, 'manual' => false];
    }
}
