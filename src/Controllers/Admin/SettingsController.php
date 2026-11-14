<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Settings;
use App\Services\Commerce;
use App\Services\StockSettings;
use App\Services\WebRevalidator;

/**
 * Company info, contacts, addresses, social links, business defaults, factory numbers, the
 * bank account for transfers, and the stock settings and payment details (admins only).
 * Each group is saved on its own: PUT /v1/admin/settings/{group}.
 */
final class SettingsController extends AdminController
{
    private const GROUPS = ['company', 'contact', 'addresses', 'social', 'commerce', 'factory_facts', 'bank', 'stock', 'stock_payment'];

    /** Groups that are never shown on the public website (/v1/site). */
    private const PRIVATE_GROUPS = ['bank', 'stock', 'stock_payment'];

    private const SOCIAL = ['facebook', 'instagram', 'tiktok', 'linkedin', 'youtube'];

    public function __construct(private readonly Settings $settings, private readonly WebRevalidator $revalidator)
    {
    }

    /** GET /v1/admin/settings */
    public function index(Request $request): Response
    {
        $this->authorize($request);

        $values = [];
        foreach (self::GROUPS as $group) {
            $values[$group] = $this->settings->get($group, []);
        }

        return Response::data($values);
    }

    /** PUT /v1/admin/settings/{group} */
    public function update(Request $request): Response
    {
        $this->authorize($request);
        $group = (string) $request->param('group');
        if (!in_array($group, self::GROUPS, true)) {
            throw HttpException::notFound('Unknown settings group.');
        }

        $input = $request->all();
        $current = $this->settings->get($group, []);
        $value = match ($group) {
            'company' => $this->company($input),
            'contact' => $this->contact($input),
            'addresses' => $this->addresses($input),
            'social' => $this->social($input),
            'commerce' => $this->commerce($input, is_array($current) ? $current : []),
            'factory_facts' => $this->factoryFacts($input, is_array($current) ? $current : []),
            'bank' => $this->bank($input),
            'stock' => $this->stock($input),
            'stock_payment' => $this->stockPayment($input),
        };

        $public = !in_array($group, self::PRIVATE_GROUPS, true);
        $this->settings->set($group, $value, $public);
        if ($public) {
            $this->revalidator->revalidate([$group === 'factory_facts' ? 'factory' : 'site']);
        }

        return Response::data($value);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function company(array $input): array
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:120',
            'legal_name' => 'nullable|string|max:160',
            'ntn' => 'nullable|string|max:40',
            'tagline' => 'required|string|max:160',
            'founded_year' => 'required|int|min:1900|max:' . gmdate('Y'),
            'city' => 'required|string|max:80',
            'country' => 'required|string|max:80',
        ])->validate();

        return [
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'ntn' => $data['ntn'] ?? null,
            'tagline' => $data['tagline'],
            'founded_year' => $data['founded_year'],
            'city' => $data['city'],
            'country' => $data['country'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{phone: string, whatsapp: string, email: string}
     */
    private function contact(array $input): array
    {
        if (is_string($input['whatsapp'] ?? null)) {
            // "+92 310-4489288" → "923104489288" (letters are kept, so they fail the format check)
            $input['whatsapp'] = preg_replace('/[\s()+\-.]+/', '', $input['whatsapp']);
        }
        $validator = Validator::make($input, [
            'phone' => 'required|phone',
            'whatsapp' => ['required', 'regex:/^[1-9][0-9]{7,14}$/'],
            'email' => 'required|email',
        ]);
        if (!$validator->passes()) {
            $errors = $validator->errors();
            if (isset($errors['whatsapp']) && isset($input['whatsapp']) && $input['whatsapp'] !== '') {
                $errors['whatsapp'] = 'Use the full international number in digits, e.g. 923104489288.';
            }
            throw new ValidationException($errors);
        }
        $data = $validator->validate();

        return ['phone' => $data['phone'], 'whatsapp' => $data['whatsapp'], 'email' => mb_strtolower($data['email'])];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{label: string, line: string, city: string, postal_code: ?string, country: string}>
     */
    private function addresses(array $input): array
    {
        $items = $input['addresses'] ?? null;
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > 4) {
            throw new ValidationException(['addresses' => 'Add between 1 and 4 addresses.']);
        }

        $addresses = [];
        foreach ($items as $index => $item) {
            $validator = Validator::make(is_array($item) ? $item : [], [
                'label' => 'required|string|max:60',
                'line' => 'required|string|max:200',
                'city' => 'required|string|max:80',
                // Printed after the city on invoices and receipts: "Lahore 54000".
                'postal_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*$/'],
                'country' => 'required|string|max:80',
            ]);
            if (!$validator->passes()) {
                $fields = [];
                foreach ($validator->errors() as $field => $message) {
                    $fields["addresses.{$index}.{$field}"] = $field === 'postal_code' ? 'Use letters, digits and spaces, e.g. 54000.' : $message;
                }
                throw new ValidationException($fields);
            }
            $data = $validator->validate();
            $addresses[] = [
                'label' => $data['label'],
                'line' => $data['line'],
                'city' => $data['city'],
                'postal_code' => ($data['postal_code'] ?? '') === '' ? null : $data['postal_code'],
                'country' => $data['country'],
            ];
        }

        return $addresses;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string> Only the networks that have a link
     */
    private function social(array $input): array
    {
        $data = Validator::make($input, array_fill_keys(self::SOCIAL, 'nullable|url|max:300'))->validate();

        return array_filter($data, static fn (?string $url): bool => $url !== null && $url !== '');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current Currency and shipping modes are not editable here.
     * @return array<string, mixed>
     */
    private function commerce(array $input, array $current): array
    {
        $data = Validator::make($input, [
            'moq_per_color' => 'required|int|min:1|max:100000',
            'set_pcs_per_size' => 'required|int|min:1|max:50',
            'min_pcs_per_size' => 'nullable|int|min:1|max:10000',
            'deposit_percent' => 'required|int|min:0|max:100',
            'lead_time_days' => 'required|int|min:1|max:365',
            'sample_price_cents' => 'required|int|min:0|max:10000000',
            'sample_lead_time_days' => 'nullable|int|min:1|max:120',
            'custom_sample_lead_time_days' => 'nullable|int|min:1|max:120',
            'pi_valid_days' => 'nullable|int|min:1|max:365',
            'fcl_from_cbm' => 'nullable|numeric|min:1|max:100',
            'packing' => 'nullable|array',
        ])->validate();

        $packing = Validator::make(is_array($data['packing'] ?? null) ? $data['packing'] : [], [
            'piece_weight_g' => 'nullable|int|min:1|max:20000',
            'pcs_per_carton' => 'nullable|int|min:1|max:10000',
            'carton_length_mm' => 'nullable|int|min:1|max:3000',
            'carton_width_mm' => 'nullable|int|min:1|max:3000',
            'carton_height_mm' => 'nullable|int|min:1|max:3000',
            'carton_weight_g' => 'nullable|int|min:0|max:100000',
        ])->validate();
        $currentPacking = is_array($current['packing'] ?? null) ? $current['packing'] : [];

        return [
            'currency' => $current['currency'] ?? 'USD',
            'moq_per_color' => $data['moq_per_color'],
            'set_pcs_per_size' => $data['set_pcs_per_size'],
            'min_pcs_per_size' => $data['min_pcs_per_size'] ?? $current['min_pcs_per_size'] ?? Commerce::MIN_PCS_PER_SIZE,
            'deposit_percent' => $data['deposit_percent'],
            'lead_time_days' => $data['lead_time_days'],
            'sample_price_cents' => $data['sample_price_cents'],
            'sample_lead_time_days' => $data['sample_lead_time_days'] ?? $current['sample_lead_time_days'] ?? 7,
            'custom_sample_lead_time_days' => $data['custom_sample_lead_time_days'] ?? $current['custom_sample_lead_time_days'] ?? 14,
            'pi_valid_days' => $data['pi_valid_days'] ?? $current['pi_valid_days'] ?? 14,
            'fcl_from_cbm' => $data['fcl_from_cbm'] ?? $current['fcl_from_cbm'] ?? 15,
            'packing' => array_merge(Commerce::PACKING_DEFAULTS, $currentPacking, array_filter($packing, static fn (mixed $v): bool => $v !== null)),
            'shipping_modes' => $current['shipping_modes'] ?? ['air', 'sea', 'own_forwarder'],
        ];
    }

    /**
     * The account buyers transfer money to. Private: shown only to the owner of an order with an amount due.
     *
     * @param array<string, mixed> $input
     * @return array<string, ?string>
     */
    private function bank(array $input): array
    {
        $data = Validator::make($input, [
            'beneficiary' => 'nullable|string|max:160',
            'bank_name' => 'nullable|string|max:160',
            'branch' => 'nullable|string|max:160',
            'bank_address' => 'nullable|string|max:300',
            'account_number' => 'nullable|string|max:60',
            'iban' => ['nullable', 'string', 'max:42', 'regex:/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{8,36}$/'],
            'swift' => ['nullable', 'string', 'regex:/^[A-Za-z]{4}[A-Za-z]{2}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'notes' => 'nullable|string|max:500',
        ])->validate();

        $bank = [];
        foreach (['beneficiary', 'bank_name', 'branch', 'bank_address', 'account_number', 'iban', 'swift', 'notes'] as $key) {
            $value = $data[$key] ?? null;
            $bank[$key] = $value === null || $value === '' ? null : $value;
        }
        if ($bank['iban'] !== null) {
            $bank['iban'] = strtoupper(trim((string) preg_replace('/\s+/', ' ', $bank['iban'])));
        }
        if ($bank['swift'] !== null) {
            $bank['swift'] = strtoupper($bank['swift']);
        }
        if (($bank['account_number'] !== null || $bank['iban'] !== null) && $bank['beneficiary'] === null) {
            throw new ValidationException(['beneficiary' => 'Enter the account name (beneficiary) as well.']);
        }

        return $bank;
    }

    /**
     * Stock orders: the smallest order, days to send a paid order, days before an unpaid one is cancelled, and where
     * buyers collect their orders. Private: read through the stock pages and the admin.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function stock(array $input): array
    {
        $data = Validator::make($input, [
            'min_sets_per_order' => 'required|int|min:1|max:1000',
            'dispatch_days' => 'required|int|min:1|max:60',
            'payment_days' => 'required|int|min:1|max:60',
            'pickup_address' => 'nullable|string|max:300',
            'pickup_hours' => 'nullable|string|max:160',
        ])->validate();

        return [
            'min_sets_per_order' => $data['min_sets_per_order'],
            'dispatch_days' => $data['dispatch_days'],
            'payment_days' => $data['payment_days'],
            'pickup_address' => ($data['pickup_address'] ?? '') === '' ? null : $data['pickup_address'],
            'pickup_hours' => ($data['pickup_hours'] ?? '') === '' ? null : $data['pickup_hours'],
        ];
    }

    /**
     * Where stock buyers pay: a bank account, JazzCash and Easypaisa (any of them). Private: shown only to the buyer
     * of a stock order with an amount due.
     *
     * @param array<string, mixed> $input
     * @return array<string, ?string>
     */
    private function stockPayment(array $input): array
    {
        $data = Validator::make($input, [
            'bank_name' => 'nullable|string|max:160',
            'account_title' => 'nullable|string|max:160',
            'account_number' => 'nullable|string|max:60',
            'iban' => ['nullable', 'string', 'max:42', 'regex:/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{8,36}$/'],
            'jazzcash_name' => 'nullable|string|max:120',
            'jazzcash_number' => ['nullable', 'string', 'regex:/^[0-9+][0-9 -]{9,17}$/'],
            'easypaisa_name' => 'nullable|string|max:120',
            'easypaisa_number' => ['nullable', 'string', 'regex:/^[0-9+][0-9 -]{9,17}$/'],
            'notes' => 'nullable|string|max:500',
        ])->validate();

        $payment = [];
        foreach (StockSettings::PAYMENT_FIELDS as $key) {
            $value = $data[$key] ?? null;
            $payment[$key] = $value === null || $value === '' ? null : $value;
        }
        if ($payment['iban'] !== null) {
            $payment['iban'] = strtoupper(trim((string) preg_replace('/\s+/', ' ', $payment['iban'])));
        }
        if (($payment['account_number'] !== null || $payment['iban'] !== null) && $payment['account_title'] === null) {
            throw new ValidationException(['account_title' => 'Enter the account title as well.']);
        }
        if ($payment['jazzcash_number'] !== null && $payment['jazzcash_name'] === null) {
            throw new ValidationException(['jazzcash_name' => 'Enter the name on the JazzCash account as well.']);
        }
        if ($payment['easypaisa_number'] !== null && $payment['easypaisa_name'] === null) {
            throw new ValidationException(['easypaisa_name' => 'Enter the name on the Easypaisa account as well.']);
        }

        return $payment;
    }

    /**
     * Headline numbers shown on the home and factory pages. Empty numbers are hidden on the website.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function factoryFacts(array $input, array $current): array
    {
        $data = Validator::make($input, [
            'monthly_capacity' => 'nullable|string|max:40',
            'machines' => 'nullable|string|max:40',
            'workers' => 'nullable|string|max:40',
            'qa_staff' => 'nullable|string|max:40',
        ])->validate();
        $data += array_fill_keys(['monthly_capacity', 'machines', 'workers', 'qa_staff'], null);

        $facts = array_merge($current, $data);

        return array_filter($facts, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
