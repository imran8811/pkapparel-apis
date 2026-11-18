<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Quotation;
use App\Models\Settings;

/**
 * Quotations staff make in the admin when a buyer sends artwork, sizes and colors and asks for prices
 * (Admin → Quotations, QT-2026-00001). A quotation has one or more styles, each costed per piece in rupees:
 *
 *   fabric = fabric rate × consumption per piece (metres, yards or kilograms)
 *   cost   = fabric + cutting + stitching + washing + packing + printing + embroidery + accessories + overhead + extra
 *   profit = a percentage of the cost, or an amount per piece
 *   price  = cost + profit + shipping per piece (no profit on the shipping), quoted in rupees (rounded to the rupee),
 *            or in US dollars, euros or pounds at the exchange rate typed in (rupees for 1 unit; rounded to the cent)
 *
 * The buyer's PDF shows the prices only; the costing sheet shows staff how each price was made. A quotation can be
 * edited while it is open: an accepted or declined one is reopened first. The numbers count up per year.
 */
final class Quotations
{
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'PKR'];

    public const FABRIC_UNITS = ['m' => 'metre', 'yd' => 'yard', 'kg' => 'kg'];

    /** The costs per piece after the fabric, in the costing sheet's order (owner request 2026-11-18). */
    public const COSTS = [
        'cutting' => 'Cutting',
        'stitching' => 'Stitching',
        'washing' => 'Washing',
        'packing' => 'Packing',
        'printing' => 'Printing',
        'embroidery' => 'Embroidery',
        'accessories' => 'Accessories',
        'overhead' => 'Overhead',
        'extra' => 'Extra',
    ];

    public const STAGES = ['draft' => 'Not sent', 'sent' => 'Sent', 'expired' => 'Expired', 'accepted' => 'Accepted', 'declined' => 'Declined'];

    private const MAX_STYLES = 50;

    /** One cost per piece: up to Rs 1,000,000. */
    private const MAX_COST_CENTS = 100_000_000;

    /** A price per piece: up to Rs 10,000,000 (the columns hold more). */
    private const MAX_PRICE_CENTS = 1_000_000_000;

    public function __construct(
        private readonly Quotation $quotations,
        private readonly Customer $customers,
        private readonly Inquiry $inquiries,
        private readonly Settings $settings,
        private readonly DocumentNumbers $numbers,
        private readonly OrderPdfs $pdfs,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array{id: int} $staff
     * @return array<string, mixed> the presented quotation
     */
    public function create(array $input, array $staff): array
    {
        [$fields, $styles] = $this->validate($input);
        $id = $this->quotations->create($this->numbers->next(DocumentNumbers::QUOTATION), $fields, $styles, $staff['id']);

        return $this->present($this->findOrFail($id));
    }

    /**
     * @param array<string, mixed> $quotation stored quotation
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $quotation, array $input): array
    {
        if ($quotation['status'] !== 'open') {
            throw new HttpException(409, 'quotation_decided', sprintf('This quotation is marked as %s, so it cannot be edited. Reopen it first, or make a copy.', mb_strtolower(Quotation::STATUSES[$quotation['status']] ?? $quotation['status'])));
        }
        [$fields, $styles] = $this->validate($input);
        $this->quotations->replace($quotation['id'], $fields, $styles);

        return $this->present($this->findOrFail($quotation['id']));
    }

    /**
     * A new open quotation with the same buyer and styles, dated today (e.g. a revised offer after the buyer's reply).
     *
     * @param array<string, mixed> $quotation
     * @param array{id: int} $staff
     * @return array<string, mixed>
     */
    public function duplicate(array $quotation, array $staff): array
    {
        $input = $quotation;
        $input['issue_date'] = Format::local()->format('Y-m-d');
        $input['valid_until'] = $this->defaultValidUntil($input['issue_date']);
        [$fields, $styles] = $this->validate($input);
        $id = $this->quotations->create($this->numbers->next(DocumentNumbers::QUOTATION), $fields, $styles, $staff['id']);

        return $this->present($this->findOrFail($id));
    }

    /**
     * Open again, accepted or declined by the buyer.
     *
     * @param array<string, mixed> $quotation
     * @param array<string, mixed> $input {status: open|accepted|declined}
     * @return array<string, mixed>
     */
    public function changeStatus(array $quotation, array $input): array
    {
        $data = Validator::make($input, ['status' => 'required|in:' . implode(',', array_keys(Quotation::STATUSES))])->validate();
        if ($data['status'] !== $quotation['status']) {
            $this->quotations->mark($quotation['id'], [
                'status' => $data['status'],
                'decided_at' => $data['status'] === 'open' ? null : gmdate('Y-m-d H:i:s'),
            ]);
        }

        return $this->present($this->findOrFail($quotation['id']));
    }

    /**
     * Emails the quotation (PDF attached). Replies go to the sales mailbox. The inquiry it answers, if any, becomes
     * "Quote sent" unless staff already moved it on.
     *
     * @param array<string, mixed> $quotation
     * @param array<string, mixed> $input {to: "a@x.com, b@y.com" or a list, message?: string}
     * @param array{id: int} $staff
     * @return array<string, mixed>
     */
    public function send(array $quotation, array $input, array $staff): array
    {
        $data = Validator::make($input, ['message' => 'nullable|string|max:2000'])->validate();
        $recipients = Invoices::recipients($input['to'] ?? null, 'quotation');

        $presented = $this->present($quotation);
        $html = $this->mailer->render('quotation', [
            'quotation' => $presented,
            'message' => $data['message'] ?? null,
            'preheader' => sprintf('Quotation %s: prices for %s.', $quotation['quotation_no'], self::stylesInWords(count($quotation['styles']))),
            'contact' => $this->settings->get('contact', []),
            'addresses' => $this->settings->get('addresses', []),
        ]);
        $sent = $this->mailer->send(
            $recipients,
            sprintf('Quotation %s | PK Apparel', $quotation['quotation_no']),
            $html,
            $this->config->string('mail.admin_to'),
            [['name' => self::filename($quotation), 'content' => $this->pdfs->quotation($presented), 'type' => 'application/pdf']],
        );
        if (!$sent) {
            throw new HttpException(502, 'mail_failed', 'The email could not be sent. Please check the mail settings, or download the PDF and send it yourself.');
        }
        $this->quotations->mark($quotation['id'], ['sent_at' => gmdate('Y-m-d H:i:s'), 'sent_to' => mb_substr(implode(', ', $recipients), 0, 400)]);
        if ($quotation['inquiry_id'] !== null) {
            $this->inquiries->markQuoted($quotation['inquiry_id'], $staff['id']);
        }

        return $this->present($this->findOrFail($quotation['id']));
    }

    /** @param array<string, mixed> $quotation */
    public function pdf(array $quotation): string
    {
        return $this->pdfs->quotation($this->present($quotation));
    }

    /** @param array<string, mixed> $quotation */
    public function costingSheet(array $quotation): string
    {
        return $this->pdfs->costingSheet($this->present($quotation));
    }

    /** @param array<string, mixed> $quotation */
    public static function filename(array $quotation): string
    {
        return $quotation['quotation_no'] . '.pdf';
    }

    /** @param array<string, mixed> $quotation */
    public static function costingFilename(array $quotation): string
    {
        return $quotation['quotation_no'] . '-costing.pdf';
    }

    /** @return array<string, mixed> */
    public function findOrFail(int $id): array
    {
        $quotation = $this->quotations->find($id);
        if ($quotation === null) {
            throw HttpException::notFound('This quotation does not exist.');
        }

        return $quotation;
    }

    /**
     * What the new-quotation form starts from: dates, the printed terms, delivery time and the latest exchange rates.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $commerce = (array) $this->settings->get('commerce', []);
        $company = (array) $this->settings->get('company', []);
        $today = Format::local()->format('Y-m-d');

        return [
            'today' => $today,
            'valid_until' => $this->defaultValidUntil($today),
            'currencies' => self::CURRENCIES,
            'incoterms' => Invoices::INCOTERMS,
            'fabric_units' => array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::FABRIC_UNITS), self::FABRIC_UNITS),
            'costs' => array_map(static fn (string $key, string $label): array => ['key' => $key, 'label' => $label], array_keys(self::COSTS), self::COSTS),
            'exchange_rates' => (object) $this->quotations->latestExchangeRates(),
            'terms' => $this->defaultTerms(),
            'delivery_time' => sprintf('About %d days after the order is confirmed', (int) ($commerce['lead_time_days'] ?? 30)),
            'company_name' => trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel'),
        ];
    }

    /**
     * Adds what the admin and the PDFs show: the stage (not sent, sent, expired, accepted, declined) and labels.
     *
     * @param array<string, mixed> $quotation stored quotation
     * @return array<string, mixed>
     */
    public function present(array $quotation): array
    {
        $today = Format::local()->format('Y-m-d');
        $stage = match (true) {
            $quotation['status'] === 'accepted' => 'accepted',
            $quotation['status'] === 'declined' => 'declined',
            $quotation['valid_until'] !== null && $quotation['valid_until'] < $today => 'expired',
            $quotation['sent_at'] !== null => 'sent',
            default => 'draft',
        };

        return $quotation + [
            'status_label' => Quotation::STATUSES[$quotation['status']] ?? $quotation['status'],
            'stage' => $stage,
            'stage_label' => self::STAGES[$stage],
        ];
    }

    /**
     * A style's prices, worked out in whole paisa and cents so the admin form shows exactly the same:
     * the fabric rounds to the paisa, a percentage profit to the paisa, a rupee price to the rupee and a price in
     * another currency to the cent (halves up). The shipping per piece is added after the profit, so no profit is
     * charged on it.
     *
     * @param array<string, mixed> $style fabric_rate_cents, fabric_consumption ("1.35"), the COSTS as {key}_cents,
     *                                    profit_type (percent|amount), profit_percent ("15") or profit_cents,
     *                                    shipping_cents
     * @param string|null $exchangeRate rupees for 1 unit of $currency ("280.5"); unused for PKR
     * @return array{fabric_cost_cents: int, cost_cents: int, profit_cents: int, price_cents: int, unit_price_cents: int}
     */
    public static function price(array $style, string $currency, ?string $exchangeRate): array
    {
        $fabric = intdiv((int) ($style['fabric_rate_cents'] ?? 0) * self::scaled((string) ($style['fabric_consumption'] ?? '0'), 3) + 500, 1000);
        $cost = $fabric;
        foreach (array_keys(self::COSTS) as $key) {
            $cost += (int) ($style["{$key}_cents"] ?? 0);
        }
        $profit = ($style['profit_type'] ?? 'percent') === 'amount'
            ? (int) ($style['profit_cents'] ?? 0)
            : intdiv($cost * self::scaled((string) ($style['profit_percent'] ?? '0'), 2) + 5000, 10000);
        $price = $cost + $profit + (int) ($style['shipping_cents'] ?? 0);
        if ($currency === 'PKR') {
            $unit = intdiv($price + 50, 100) * 100;
        } else {
            $rate = self::scaled((string) $exchangeRate, 4);
            $unit = $rate > 0 ? intdiv($price * 20000 + $rate, 2 * $rate) : 0;
        }

        return ['fabric_cost_cents' => $fabric, 'cost_cents' => $cost, 'profit_cents' => $profit, 'price_cents' => $price, 'unit_price_cents' => $unit];
    }

    /** "1.35" with 3 places → 1350: a decimal typed in, as a whole number of its smallest unit (half up), without floats. */
    private static function scaled(string $value, int $places): int
    {
        $value = trim($value);
        if (preg_match('/^(\d*)(?:\.(\d*))?$/', $value, $match) !== 1 || ($match[1] === '' && ($match[2] ?? '') === '')) {
            return 0;
        }
        $digits = str_pad($match[2] ?? '', $places + 1, '0');
        $scaled = (int) (($match[1] === '' ? '0' : $match[1]) . substr($digits, 0, $places));

        return $digits[$places] >= '5' ? $scaled + 1 : $scaled;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} quotation columns and styles
     */
    private function validate(array $input): array
    {
        $errors = [];
        // A quotation in rupees has no exchange rate: one sent anyway is ignored.
        $input['exchange_rate'] = ($input['currency'] ?? null) === 'PKR' ? null : self::decimalInput($input['exchange_rate'] ?? null);
        $validator = Validator::make($input, [
            'customer_id' => 'nullable|int|min:1',
            'inquiry_id' => 'nullable|int|min:1',
            'currency' => 'required|in:' . implode(',', self::CURRENCIES),
            'exchange_rate' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
            'issue_date' => 'required|date',
            'valid_until' => 'nullable|date',
            'buyer_reference' => 'nullable|string|max:60',
            'incoterm' => 'nullable|in:' . implode(',', Invoices::INCOTERMS),
            'delivery_time' => 'nullable|string|max:160',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:3000',
            'staff_notes' => 'nullable|string|max:2000',
        ]);
        $data = $validator->passes() ? $validator->validate() : [];
        foreach ($validator->errors() as $field => $message) {
            $errors[$field] = $field === 'exchange_rate' && $message === 'The format is not valid.' ? 'Enter the rupees for 1 unit of the currency, e.g. 280.50.' : $message;
        }

        $buyer = Invoices::party(is_array($input['buyer'] ?? null) ? $input['buyer'] : [], 'buyer', $errors);

        // Read from the input, so a mistake in another field does not also report the rate as missing.
        $currency = in_array($input['currency'] ?? null, self::CURRENCIES, true) ? (string) $input['currency'] : 'PKR';
        $rate = $currency !== 'PKR' && is_string($input['exchange_rate']) && preg_match('/^\d{1,6}(\.\d{1,4})?$/', $input['exchange_rate']) === 1 ? $input['exchange_rate'] : null;
        if ($currency !== 'PKR' && !isset($errors['exchange_rate']) && self::scaled((string) $rate, 4) === 0) {
            $errors['exchange_rate'] = sprintf('Enter the exchange rate: rupees for 1 %s.', $currency);
        }
        $styles = $this->styles($input['styles'] ?? null, $currency, $rate, $errors);

        $customerId = $data['customer_id'] ?? null;
        if ($customerId !== null && $this->customers->find($customerId) === null) {
            $errors['customer_id'] = 'This buyer account no longer exists.';
        }
        $inquiryId = $data['inquiry_id'] ?? null;
        if ($inquiryId !== null && $this->inquiries->find($inquiryId) === null) {
            $errors['inquiry_id'] = 'This inquiry no longer exists.';
        }
        // Read from the input too, so this shows together with mistakes in other fields.
        $date = static fn (string $key): ?string => is_string($input[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$key]) === 1 ? $input[$key] : null;
        if (!isset($errors['valid_until']) && $date('issue_date') !== null && $date('valid_until') !== null && $date('valid_until') < $date('issue_date')) {
            $errors['valid_until'] = 'The validity date cannot be before the quotation date.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $quantities = array_filter($styles, static fn (array $style): bool => $style['qty'] !== null);
        $text = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;

        return [[
            'customer_id' => $customerId,
            'inquiry_id' => $inquiryId,
            'buyer' => $buyer,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'buyer_reference' => $text('buyer_reference'),
            'incoterm' => $text('incoterm'),
            'delivery_time' => $text('delivery_time'),
            'total_qty' => array_sum(array_column($quantities, 'qty')),
            'total_cents' => array_sum(array_column($quantities, 'amount_cents')),
            'notes' => $text('notes'),
            'terms' => $text('terms'),
            'staff_notes' => $text('staff_notes'),
        ], $styles];
    }

    /**
     * Each style's details and costs, with its prices worked out. Errors are keyed "styles.0.fabric_consumption"…
     *
     * @param array<string, string> $errors
     * @return list<array<string, mixed>>
     */
    private function styles(mixed $input, string $currency, ?string $rate, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === []) {
            $errors['styles'] = 'Add at least one style.';

            return [];
        }
        if (count($input) > self::MAX_STYLES) {
            $errors['styles'] = sprintf('A quotation can have at most %d styles.', self::MAX_STYLES);

            return [];
        }

        $money = 'nullable|int|min:0|max:' . self::MAX_COST_CENTS;
        $rules = [
            'article_no' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:200',
            'fabric' => 'nullable|string|max:200',
            'colors' => 'nullable|string|max:200',
            'sizes' => 'nullable|string|max:200',
            'details' => 'nullable|string|max:500',
            'qty' => 'nullable|int|min:1|max:10000000',
            'fabric_rate_cents' => $money,
            'fabric_unit' => 'nullable|in:' . implode(',', array_keys(self::FABRIC_UNITS)),
            'fabric_consumption' => ['nullable', 'string', 'regex:/^\d{1,4}(\.\d{1,3})?$/'],
            'extra_label' => 'nullable|string|max:60',
            'profit_type' => 'required|in:percent,amount',
            'profit_percent' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'profit_cents' => $money,
            'shipping_cents' => $money,
        ];
        foreach (array_keys(self::COSTS) as $key) {
            $rules["{$key}_cents"] = $money;
        }
        $formats = [
            'fabric_consumption' => 'Enter the consumption per piece, e.g. 1.35 (up to 3 decimals).',
            'profit_percent' => 'Enter a percentage, e.g. 15 or 12.5.',
        ];

        $styles = [];
        foreach ($input as $index => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $raw['fabric_consumption'] = self::decimalInput($raw['fabric_consumption'] ?? null);
            $raw['profit_percent'] = self::decimalInput($raw['profit_percent'] ?? null);
            $validator = Validator::make($raw, $rules);
            $field = static fn (string $name): string => "styles.{$index}.{$name}";
            if (!$validator->passes()) {
                foreach ($validator->errors() as $name => $message) {
                    $errors[$field($name)] = $message === 'The format is not valid.' && isset($formats[$name]) ? $formats[$name] : $message;
                }
                continue;
            }
            $data = $validator->validate();
            $text = static fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

            $style = [
                'article_no' => $text('article_no'),
                'description' => $text('description'),
                'fabric' => $text('fabric'),
                'colors' => $text('colors'),
                'sizes' => $text('sizes'),
                'details' => $text('details'),
                'qty' => isset($data['qty']) ? (int) $data['qty'] : null,
                'fabric_rate_cents' => (int) ($data['fabric_rate_cents'] ?? 0),
                'fabric_unit' => $text('fabric_unit') ?? 'm',
                'fabric_consumption' => $text('fabric_consumption') ?? '0',
                'extra_label' => $text('extra_label'),
                'profit_type' => (string) $data['profit_type'],
                'profit_percent' => $data['profit_type'] === 'percent' ? ($text('profit_percent') ?? '0') : null,
                'profit_cents' => (int) ($data['profit_cents'] ?? 0),
                'shipping_cents' => (int) ($data['shipping_cents'] ?? 0),
            ];
            foreach (array_keys(self::COSTS) as $key) {
                $style["{$key}_cents"] = (int) ($data["{$key}_cents"] ?? 0);
            }

            $hasRate = $style['fabric_rate_cents'] > 0;
            $hasConsumption = self::scaled($style['fabric_consumption'], 3) > 0;
            if ($style['article_no'] === null && $style['description'] === null) {
                $errors[$field('article_no')] = "Enter the buyer's article no. or a description.";
            }
            if ($hasRate && !$hasConsumption) {
                $errors[$field('fabric_consumption')] = 'Enter the fabric used for one piece.';
            } elseif ($hasConsumption && !$hasRate) {
                $errors[$field('fabric_rate_cents')] = 'Enter the fabric rate.';
            }

            $prices = self::price($style, $currency, $rate);
            if ($prices['price_cents'] > self::MAX_PRICE_CENTS) {
                $errors[$field('price')] = 'The price per piece is too large. Check the costs and the profit.';
                continue;
            }
            $style = $prices + $style;
            $style['amount_cents'] = $style['qty'] === null ? null : $style['qty'] * $prices['unit_price_cents'];
            $styles[] = $style;
        }

        return $styles;
    }

    /** A decimal sent as a number (1.35) or text (" 1.35 ") → "1.35"; empty → null. */
    private static function decimalInput(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        return is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
    }

    /** The printed terms a new quotation starts with (staff can change them per quotation). */
    private function defaultTerms(): string
    {
        $site = preg_replace('#^https?://(www\.)?#', '', rtrim($this->config->string('web.url', 'https://www.pkapparel.com'), '/'));
        $commerce = (array) $this->settings->get('commerce', []);
        $deposit = max(1, min(100, (int) ($commerce['deposit_percent'] ?? 50)));

        return implode("\n", [
            'Prices are per piece and hold until the date above.',
            'They are based on the artwork, fabric, sizes, colors and quantities given: if any of these change, the price may change.',
            sprintf('Production starts once the pre-production sample is approved and the %d%% deposit is received. The balance is paid before shipment.', $deposit),
            sprintf('Our terms of sale apply (%s/terms-of-sale).', $site),
        ]);
    }

    /** Quotations hold for pi_valid_days (14), like proforma invoices. */
    private function defaultValidUntil(string $issueDate): string
    {
        $commerce = (array) $this->settings->get('commerce', []);
        $days = max(1, (int) ($commerce['pi_valid_days'] ?? 14));

        return Format::local($issueDate)->modify("+{$days} days")->format('Y-m-d');
    }

    private static function stylesInWords(int $count): string
    {
        return $count === 1 ? '1 style' : "{$count} styles";
    }
}
