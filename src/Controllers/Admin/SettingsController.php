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
use App\Services\WebRevalidator;

/**
 * Company info, contacts, addresses, social links, business defaults, factory numbers and the
 * bank account for transfers (admins only). Each group is saved on its own: PUT /v1/admin/settings/{group}.
 */
final class SettingsController extends AdminController
{
    private const GROUPS = ['company', 'contact', 'addresses', 'social', 'commerce', 'factory_facts', 'bank'];

    /** Groups that are never shown on the public website (/v1/site). */
    private const PRIVATE_GROUPS = ['bank'];

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
     * @return list<array{label: string, line: string, city: string, country: string}>
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
                'country' => 'required|string|max:80',
            ]);
            if (!$validator->passes()) {
                $fields = [];
                foreach ($validator->errors() as $field => $message) {
                    $fields["addresses.{$index}.{$field}"] = $message;
                }
                throw new ValidationException($fields);
            }
            $addresses[] = $validator->validate();
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
            'deposit_percent' => 'required|int|min:0|max:100',
            'lead_time_days' => 'required|int|min:1|max:365',
            'sample_price_cents' => 'required|int|min:0|max:10000000',
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
            'deposit_percent' => $data['deposit_percent'],
            'lead_time_days' => $data['lead_time_days'],
            'sample_price_cents' => $data['sample_price_cents'],
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
