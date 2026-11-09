<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Settings;

/**
 * The stock settings (Admin → Settings → Stock) with safe defaults, and the accounts stock buyers pay into.
 * Both are private settings: the payment details only reach the buyer of an order with an amount due.
 */
final class StockSettings
{
    /** Stock buyers are local: their accounts and addresses use this country (never shown on the stock pages). */
    public const COUNTRY = 'PK';

    public const CURRENCY = 'PKR';

    private const DEFAULTS = ['min_sets_per_order' => 1, 'dispatch_days' => 2, 'payment_days' => 7];

    /** Payment details: a bank account, JazzCash and Easypaisa (any of them may be left empty). */
    public const PAYMENT_FIELDS = [
        'bank_name', 'account_title', 'account_number', 'iban',
        'jazzcash_name', 'jazzcash_number', 'easypaisa_name', 'easypaisa_number', 'notes',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /** The smallest order, in sets. */
    public function minSetsPerOrder(): int
    {
        return max(1, $this->int('min_sets_per_order'));
    }

    /** Days to send (or have ready for collection) an order once its payment is confirmed. */
    public function dispatchDays(): int
    {
        return max(1, $this->int('dispatch_days'));
    }

    /** An unpaid stock order is cancelled after this many days. */
    public function paymentDays(): int
    {
        return max(1, $this->int('payment_days'));
    }

    /** @return array{address: ?string, hours: ?string} Where buyers collect their orders. */
    public function pickup(): array
    {
        $values = $this->values();

        return [
            'address' => self::text($values['pickup_address'] ?? null),
            'hours' => self::text($values['pickup_hours'] ?? null),
        ];
    }

    /** @return array<string, mixed> Every stock setting, for the admin. */
    public function values(): array
    {
        $values = $this->settings->get('stock', []);

        return (is_array($values) ? $values : []) + self::DEFAULTS + ['pickup_address' => null, 'pickup_hours' => null];
    }

    /**
     * The accounts to pay into, or null until staff have entered at least one of them.
     *
     * @return array<string, ?string>|null
     */
    public function payment(): ?array
    {
        $stored = $this->settings->get('stock_payment', []);
        $stored = is_array($stored) ? $stored : [];
        $payment = [];
        foreach (self::PAYMENT_FIELDS as $field) {
            $payment[$field] = self::text($stored[$field] ?? null);
        }
        $usable = $payment['account_number'] !== null || $payment['iban'] !== null || $payment['jazzcash_number'] !== null || $payment['easypaisa_number'] !== null;

        return $usable ? $payment : null;
    }

    /** @return list<string> "Bank transfer", "JazzCash", "Easypaisa": the ways to pay that are set up. */
    public function paymentMethods(): array
    {
        $payment = $this->payment();
        if ($payment === null) {
            return [];
        }

        return array_values(array_filter([
            $payment['account_number'] !== null || $payment['iban'] !== null ? 'Bank transfer' : null,
            $payment['jazzcash_number'] !== null ? 'JazzCash' : null,
            $payment['easypaisa_number'] !== null ? 'Easypaisa' : null,
        ]));
    }

    private function int(string $key): int
    {
        return (int) ($this->values()[$key] ?? self::DEFAULTS[$key]);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
