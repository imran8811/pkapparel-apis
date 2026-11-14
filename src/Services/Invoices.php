<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Format;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Settings;

/**
 * Invoices staff make in the admin for buyers who order outside the website (Admin → Invoices):
 *
 * - sample: samples plus the courier, paid in full before dispatch (SI-2026-00001);
 * - proforma: a production order's proforma, asking for a deposit (PI-2026-00001);
 * - commercial: the final invoice, less what was already received (INV-2026-00001, the same series as the orders'
 *   balance invoices).
 *
 * Amounts: total = lines + shipping − discount. "Received" is money already paid (e.g. the deposit on a final
 * invoice) and is deducted. Due now is the balance, or for a proforma its deposit share of the total.
 * An invoice can be edited until it is paid or cancelled; cancelled invoices keep their number.
 */
final class Invoices
{
    /** Number prefixes per type. */
    private const PREFIXES = ['sample' => DocumentNumbers::SAMPLE_INVOICE, 'proforma' => DocumentNumbers::PROFORMA_INVOICE, 'commercial' => DocumentNumbers::INVOICE];

    public const CURRENCIES = ['USD', 'EUR', 'GBP'];

    public const INCOTERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    private const MAX_LINES = 50;

    /** The largest amount the invoice columns hold, with room to spare: $40 million. */
    private const MAX_CENTS = 4_000_000_000;

    public function __construct(
        private readonly Invoice $invoices,
        private readonly Customer $customers,
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
     * @return array<string, mixed> the presented invoice
     */
    public function create(array $input, array $staff): array
    {
        $type = (string) ($input['type'] ?? '');
        if (!isset(Invoice::TYPES[$type])) {
            throw new ValidationException(['type' => 'Choose sample invoice, proforma invoice or invoice.']);
        }
        [$fields, $lines] = $this->validate($input, $type);
        $id = $this->invoices->create($this->numbers->next(self::PREFIXES[$type]), $type, $fields, $lines, $staff['id']);

        return $this->present($this->findOrFail($id));
    }

    /**
     * @param array<string, mixed> $invoice stored invoice
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $invoice, array $input): array
    {
        $this->assertOpen($invoice, 'edited');
        [$fields, $lines] = $this->validate($input, $invoice['type']);
        $this->invoices->replace($invoice['id'], $fields, $lines);

        return $this->present($this->findOrFail($invoice['id']));
    }

    /**
     * A new open invoice with the same buyer, lines and charges, dated today; optionally of another type
     * (e.g. the proforma for production after the samples were approved).
     *
     * @param array<string, mixed> $invoice
     * @param array{id: int} $staff
     * @return array<string, mixed>
     */
    public function duplicate(array $invoice, ?string $type, array $staff): array
    {
        $type ??= $invoice['type'];
        if (!isset(Invoice::TYPES[$type])) {
            throw new ValidationException(['type' => 'Choose sample invoice, proforma invoice or invoice.']);
        }
        $input = $invoice;
        $input['issue_date'] = Format::local()->format('Y-m-d');
        $input['due_date'] = $this->defaultDueDate($type, $input['issue_date']);
        $input['received_cents'] = 0;
        $input['deposit_percent'] = $type === 'proforma' ? ($invoice['type'] === 'proforma' ? $invoice['deposit_percent'] : $this->depositPercent()) : 100;
        if ($type !== $invoice['type']) {
            $input['terms'] = $this->defaultTerms()[$type];
        }
        [$fields, $lines] = $this->validate($input, $type);
        $id = $this->invoices->create($this->numbers->next(self::PREFIXES[$type]), $type, $fields, $lines, $staff['id']);

        return $this->present($this->findOrFail($id));
    }

    /**
     * Emails the invoice (PDF attached) with the bank details. Replies go to the sales mailbox.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $input {to: "a@x.com, b@y.com" or a list, message?: string}
     * @return array<string, mixed>
     */
    public function send(array $invoice, array $input): array
    {
        if ($invoice['status'] === 'cancelled') {
            throw new HttpException(409, 'invoice_cancelled', 'This invoice was cancelled, so it cannot be sent.');
        }
        $data = Validator::make($input, ['message' => 'nullable|string|max:2000'])->validate();
        $recipients = self::recipients($input['to'] ?? null);

        $presented = $this->present($invoice);
        $bank = $this->bank();
        $pdf = $this->pdfs->invoice($presented, $bank);
        $typeLabel = Invoice::TYPES[$invoice['type']];
        $html = $this->mailer->render('invoice', [
            'invoice' => $presented,
            'message' => $data['message'] ?? null,
            'bank' => $bank,
            'preheader' => sprintf('%s %s: %s due.', $typeLabel, $invoice['invoice_no'], Format::money($presented['amounts']['due_now_cents'], $invoice['currency'])),
            'contact' => $this->settings->get('contact', []),
            'addresses' => $this->settings->get('addresses', []),
        ]);
        $sent = $this->mailer->send(
            $recipients,
            sprintf('%s %s | PK Apparel', $typeLabel, $invoice['invoice_no']),
            $html,
            $this->config->string('mail.admin_to'),
            [['name' => self::filename($invoice), 'content' => $pdf, 'type' => 'application/pdf']],
        );
        if (!$sent) {
            throw new HttpException(502, 'mail_failed', 'The email could not be sent. Please check the mail settings, or download the PDF and send it yourself.');
        }
        $this->invoices->mark($invoice['id'], ['sent_at' => gmdate('Y-m-d H:i:s'), 'sent_to' => mb_substr(implode(', ', $recipients), 0, 400)]);

        return $this->present($this->findOrFail($invoice['id']));
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $input {paid_on, payment_reference?}
     * @return array<string, mixed>
     */
    public function markPaid(array $invoice, array $input): array
    {
        if ($invoice['status'] === 'cancelled') {
            throw new HttpException(409, 'invoice_cancelled', 'This invoice was cancelled.');
        }
        $data = Validator::make($input, ['paid_on' => 'required|date', 'payment_reference' => 'nullable|string|max:120'])->validate();
        if ($data['paid_on'] > Format::local()->format('Y-m-d')) {
            throw new ValidationException(['paid_on' => 'The payment date cannot be in the future.']);
        }
        $this->invoices->mark($invoice['id'], ['status' => 'paid', 'paid_on' => $data['paid_on'], 'payment_reference' => $data['payment_reference'] ?? null]);

        return $this->present($this->findOrFail($invoice['id']));
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    public function markUnpaid(array $invoice): array
    {
        if ($invoice['status'] !== 'paid') {
            throw new HttpException(409, 'not_paid', 'This invoice is not marked as paid.');
        }
        $this->invoices->mark($invoice['id'], ['status' => 'open', 'paid_on' => null, 'payment_reference' => null]);

        return $this->present($this->findOrFail($invoice['id']));
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    public function cancel(array $invoice): array
    {
        $this->assertOpen($invoice, 'cancelled');
        $this->invoices->mark($invoice['id'], ['status' => 'cancelled', 'cancelled_at' => gmdate('Y-m-d H:i:s')]);

        return $this->present($this->findOrFail($invoice['id']));
    }

    /** @param array<string, mixed> $invoice */
    public function pdf(array $invoice): string
    {
        return $this->pdfs->invoice($this->present($invoice), $this->bank());
    }

    /** @param array<string, mixed> $invoice */
    public static function filename(array $invoice): string
    {
        return $invoice['invoice_no'] . '.pdf';
    }

    /** @return array<string, mixed> */
    public function findOrFail(int $id): array
    {
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            throw HttpException::notFound('This invoice does not exist.');
        }

        return $invoice;
    }

    /**
     * What the new-invoice form starts from: the printed terms per type, the deposit, the sample price and whether
     * the company and bank details are filled in.
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
            'due_dates' => array_map(fn (string $type): ?string => $this->defaultDueDate($type, $today), array_combine(array_keys(Invoice::TYPES), array_keys(Invoice::TYPES))),
            'terms' => $this->defaultTerms(),
            'deposit_percent' => $this->depositPercent(),
            'sample_price_cents' => (int) ($commerce['sample_price_cents'] ?? 10000),
            'sample_lead_time_days' => (int) ($commerce['sample_lead_time_days'] ?? 7),
            'lead_time_days' => (int) ($commerce['lead_time_days'] ?? 30),
            'currencies' => self::CURRENCIES,
            'incoterms' => self::INCOTERMS,
            'bank_ready' => $this->bank() !== null,
            'company_name' => trim((string) ($company['legal_name'] ?? '')) !== '' ? $company['legal_name'] : ($company['name'] ?? 'PK Apparel'),
        ];
    }

    /** The printed terms each type starts with (staff can change them per invoice). */
    public function defaultTerms(): array
    {
        $site = preg_replace('#^https?://(www\.)?#', '', rtrim($this->config->string('web.url', 'https://www.pkapparel.com'), '/'));
        $commerce = (array) $this->settings->get('commerce', []);
        $valid = (int) ($commerce['pi_valid_days'] ?? 14);
        $common = sprintf('Bank charges on the sending side are paid by the buyer. Our terms of sale apply (%s/terms-of-sale).', $site);

        return [
            'sample' => implode("\n", [
                'Samples are made or picked once the payment is received, then sent by express courier with tracking.',
                'The courier cost is included. Import duties and taxes, if any, are paid by the buyer.',
                $common,
            ]),
            'proforma' => implode("\n", [
                'Production starts once the deposit is received. The balance is paid before shipment.',
                'Ready dates are estimates and may change with approvals, fabric availability or holidays.',
                sprintf('Prices are valid for %d days. Import duties and taxes are paid by the buyer.', $valid),
                $common,
            ]),
            'commercial' => implode("\n", [
                'Goods ship once the full amount is received.',
                'Import duties and taxes are paid by the buyer.',
                $common,
            ]),
        ];
    }

    /**
     * Adds what the admin and the PDF show: labels, the stage (not sent, sent, overdue, paid, cancelled) and the
     * amounts block.
     *
     * @param array<string, mixed> $invoice stored invoice
     * @return array<string, mixed>
     */
    public function present(array $invoice): array
    {
        $today = Format::local()->format('Y-m-d');
        $stage = match (true) {
            $invoice['status'] === 'cancelled' => 'cancelled',
            $invoice['status'] === 'paid' => 'paid',
            $invoice['due_date'] !== null && $invoice['due_date'] < $today => 'overdue',
            $invoice['sent_at'] !== null => 'sent',
            default => 'not_sent',
        };

        return $invoice + [
            'type_label' => Invoice::TYPES[$invoice['type']] ?? 'Invoice',
            'status_label' => Invoice::STATUSES[$invoice['status']] ?? $invoice['status'],
            'stage' => $stage,
            'stage_label' => ['cancelled' => 'Cancelled', 'paid' => 'Paid', 'overdue' => 'Overdue', 'sent' => 'Sent', 'not_sent' => 'Not sent'][$stage],
            'amounts' => [
                'subtotal_cents' => $invoice['subtotal_cents'],
                'shipping_cents' => $invoice['shipping_cents'],
                'discount_cents' => $invoice['discount_cents'],
                'total_cents' => $invoice['total_cents'],
                'received_cents' => $invoice['received_cents'],
                'deposit_percent' => $invoice['deposit_percent'],
                'due_now_cents' => $invoice['due_now_cents'],
                // What stays to pay after the amount due now (a proforma's balance before shipment).
                'balance_after_cents' => max(0, $invoice['total_cents'] - $invoice['received_cents'] - $invoice['due_now_cents']),
            ],
        ];
    }

    /**
     * Totals of an invoice.
     *
     * @param list<array{qty: int, unit_price_cents: int}> $lines
     * @return array{subtotal_cents: int, total_cents: int, due_now_cents: int, lines: list<int>}
     */
    public static function totals(string $type, array $lines, int $shipping, int $discount, int $received, int $depositPercent): array
    {
        $amounts = array_map(static fn (array $line): int => $line['qty'] * $line['unit_price_cents'], $lines);
        $subtotal = array_sum($amounts);
        $total = max(0, $subtotal + $shipping - $discount);
        $balance = max(0, $total - $received);
        $dueNow = $type === 'proforma'
            ? min($balance, max(0, (int) round($total * $depositPercent / 100) - $received))
            : $balance;

        return ['subtotal_cents' => $subtotal, 'total_cents' => $total, 'due_now_cents' => $dueNow, 'lines' => $amounts];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} invoice columns and lines
     */
    private function validate(array $input, string $type): array
    {
        $errors = [];
        $money = 'nullable|int|min:0|max:' . self::MAX_CENTS;
        $validator = Validator::make($input, [
            'customer_id' => 'nullable|int|min:1',
            'currency' => 'required|in:' . implode(',', self::CURRENCIES),
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'buyer_reference' => 'nullable|string|max:60',
            'incoterm' => 'nullable|in:' . implode(',', self::INCOTERMS),
            'shipping_method' => 'nullable|string|max:120',
            'delivery_time' => 'nullable|string|max:160',
            'shipping_cents' => $money,
            'discount_cents' => $money,
            'received_cents' => $money,
            'deposit_percent' => 'nullable|int|min:1|max:100',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:3000',
            'staff_notes' => 'nullable|string|max:2000',
        ]);
        $data = $validator->passes() ? $validator->validate() : [];
        $errors += $validator->errors();

        $buyer = $this->party(is_array($input['buyer'] ?? null) ? $input['buyer'] : [], 'buyer', $errors);
        $consignee = null;
        if (is_array($input['consignee'] ?? null) && array_filter($input['consignee'], static fn (mixed $v): bool => is_string($v) && trim($v) !== '') !== []) {
            $consignee = $this->party($input['consignee'], 'consignee', $errors);
        }
        $lines = $this->lines($input['lines'] ?? null, $errors);

        $customerId = $data['customer_id'] ?? null;
        if ($customerId !== null && $this->customers->find($customerId) === null) {
            $errors['customer_id'] = 'This buyer account no longer exists.';
        }
        if (isset($data['issue_date'], $data['due_date']) && $data['due_date'] < $data['issue_date']) {
            $errors['due_date'] = 'The due date cannot be before the invoice date.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $shipping = (int) ($data['shipping_cents'] ?? 0);
        $discount = (int) ($data['discount_cents'] ?? 0);
        $received = (int) ($data['received_cents'] ?? 0);
        $deposit = $type === 'proforma' ? (int) ($data['deposit_percent'] ?? $this->depositPercent()) : 100;
        $totals = self::totals($type, $lines, $shipping, $discount, $received, $deposit);
        if ($totals['subtotal_cents'] + $shipping > self::MAX_CENTS) {
            throw new ValidationException(['lines' => 'The invoice total is too large. Split it into several invoices.']);
        }
        if ($discount > $totals['subtotal_cents'] + $shipping) {
            throw new ValidationException(['discount_cents' => 'The discount cannot be more than the invoice amount.']);
        }
        if ($received > $totals['total_cents']) {
            throw new ValidationException(['received_cents' => 'The amount received cannot be more than the total.']);
        }
        foreach ($lines as $index => $line) {
            $lines[$index]['amount_cents'] = $totals['lines'][$index];
        }
        $text = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;

        return [[
            'customer_id' => $customerId,
            'buyer' => $buyer,
            'consignee' => $consignee,
            'currency' => $data['currency'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'buyer_reference' => $text('buyer_reference'),
            'incoterm' => $text('incoterm'),
            'shipping_method' => $text('shipping_method'),
            'delivery_time' => $text('delivery_time'),
            'subtotal_cents' => $totals['subtotal_cents'],
            'shipping_cents' => $shipping,
            'discount_cents' => $discount,
            'total_cents' => $totals['total_cents'],
            'received_cents' => $received,
            'deposit_percent' => $deposit,
            'due_now_cents' => $totals['due_now_cents'],
            'notes' => $text('notes'),
            'terms' => $text('terms'),
            'staff_notes' => $text('staff_notes'),
        ], $lines];
    }

    /**
     * The buyer or the consignee. Errors are keyed "buyer.company", "consignee.address"…
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     * @return array<string, ?string>
     */
    private function party(array $input, string $prefix, array &$errors): array
    {
        $validator = Validator::make($input, [
            'company' => 'nullable|string|max:160',
            'contact_name' => 'nullable|string|max:120',
            'email' => 'nullable|email',
            'phone' => 'nullable|phone',
            'address' => 'nullable|string|max:500',
            'country_code' => 'required|country',
            'tax_id' => 'nullable|string|max:60',
        ]);
        // Checked on the input, so they are reported together with any other mistake in the same fields.
        $filled = static fn (string $key): bool => is_string($input[$key] ?? null) && trim($input[$key]) !== '';
        $missing = [];
        if (!$filled('company') && !$filled('contact_name')) {
            $missing["{$prefix}.company"] = 'Enter the company or the contact name.';
        }
        if ($prefix === 'consignee' && !$filled('address')) {
            $missing['consignee.address'] = 'Enter the delivery address.';
        }
        if (!$validator->passes()) {
            foreach ($validator->errors() as $field => $message) {
                $errors["{$prefix}.{$field}"] = $message;
            }
            $errors += $missing;

            return [];
        }
        $errors += $missing;
        $data = $validator->validate();
        $party = [];
        foreach (['company', 'contact_name', 'email', 'phone', 'address', 'country_code', 'tax_id'] as $key) {
            $value = $data[$key] ?? null;
            $party[$key] = $value === null || $value === '' ? null : (string) $value;
        }
        if ($party['email'] !== null) {
            $party['email'] = mb_strtolower($party['email']);
        }

        return $party;
    }

    /**
     * @param array<string, string> $errors
     * @return list<array{description: string, details: ?string, hs_code: ?string, qty: int, unit: string, unit_price_cents: int}>
     */
    private function lines(mixed $input, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === []) {
            $errors['lines'] = 'Add at least one line.';

            return [];
        }
        if (count($input) > self::MAX_LINES) {
            $errors['lines'] = sprintf('An invoice can have at most %d lines.', self::MAX_LINES);

            return [];
        }

        $lines = [];
        foreach ($input as $index => $line) {
            $validator = Validator::make(is_array($line) ? $line : [], [
                'description' => 'required|string|max:200',
                'details' => 'nullable|string|max:500',
                'hs_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9][0-9. ]*$/'],
                'qty' => 'required|int|min:1|max:1000000',
                'unit' => 'nullable|string|max:20',
                'unit_price_cents' => 'required|int|min:0|max:100000000',
            ]);
            if (!$validator->passes()) {
                foreach ($validator->errors() as $field => $message) {
                    $errors["lines.{$index}.{$field}"] = $field === 'hs_code' && $message === 'The format is not valid.' ? 'Use digits, e.g. 6203.42.' : $message;
                }
                continue;
            }
            $data = $validator->validate();
            $lines[] = [
                'description' => (string) $data['description'],
                'details' => ($data['details'] ?? '') === '' ? null : (string) $data['details'],
                'hs_code' => ($data['hs_code'] ?? '') === '' ? null : (string) $data['hs_code'],
                'qty' => (int) $data['qty'],
                'unit' => ($data['unit'] ?? '') === '' ? 'pcs' : (string) $data['unit'],
                'unit_price_cents' => (int) $data['unit_price_cents'],
            ];
        }

        return $lines;
    }

    /**
     * "a@x.com, b@y.com" or a list → up to 5 distinct, valid addresses.
     *
     * @return list<string>
     */
    private static function recipients(mixed $input): array
    {
        $parts = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input);
        $emails = [];
        foreach ($parts ?: [] as $part) {
            $email = mb_strtolower(trim((string) $part));
            if ($email === '') {
                continue;
            }
            if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ValidationException(['to' => sprintf('"%s" is not a valid email address.', $email)]);
            }
            $emails[$email] = true;
        }
        if ($emails === []) {
            throw new ValidationException(['to' => 'Enter the email address to send the invoice to.']);
        }
        if (count($emails) > 5) {
            throw new ValidationException(['to' => 'Send to at most 5 addresses at once.']);
        }

        return array_keys($emails);
    }

    /** @param array<string, mixed> $invoice */
    private function assertOpen(array $invoice, string $action): void
    {
        if ($invoice['status'] === 'cancelled') {
            throw new HttpException(409, 'invoice_cancelled', "This invoice was cancelled, so it cannot be {$action}. Duplicate it to make a new one.");
        }
        if ($invoice['status'] === 'paid') {
            throw new HttpException(409, 'invoice_paid', "This invoice is paid, so it cannot be {$action}. Mark it as unpaid first.");
        }
    }

    /** The bank account for transfers, or null while neither the account number nor the IBAN is filled in. */
    private function bank(): ?array
    {
        $bank = $this->settings->get('bank', []);
        if (!is_array($bank) || (trim((string) ($bank['account_number'] ?? '')) === '' && trim((string) ($bank['iban'] ?? '')) === '')) {
            return null;
        }

        return $bank;
    }

    private function depositPercent(): int
    {
        $commerce = (array) $this->settings->get('commerce', []);

        return max(1, min(100, (int) ($commerce['deposit_percent'] ?? 50)));
    }

    /** Sample and final invoices are due in 7 days; a proforma is valid for pi_valid_days (14). */
    private function defaultDueDate(string $type, string $issueDate): ?string
    {
        $commerce = (array) $this->settings->get('commerce', []);
        $days = $type === 'proforma' ? (int) ($commerce['pi_valid_days'] ?? 14) : 7;

        return Format::local($issueDate)->modify("+{$days} days")->format('Y-m-d');
    }
}
