<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\ShippingZone;

/**
 * Freight rates for checkout estimates: zones (groups of countries) with air and sea rate bands.
 * Each save replaces a zone's countries and rates as a whole.
 */
final class ShippingController extends AdminController
{
    private const MAX_COUNTRIES = 250;

    private const MAX_RATES = 30;

    public function __construct(private readonly ShippingZone $zones)
    {
    }

    /** GET /v1/admin/shipping/zones */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->zones->all());
    }

    /** POST /v1/admin/shipping/zones */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'sales');
        [$name, $sort, $countries, $rates] = $this->validated($request, null);

        $id = $this->zones->save(null, $name, $sort, $countries, $rates);

        return Response::data($this->zones->find($id), [], 201);
    }

    /** PUT /v1/admin/shipping/zones/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $id = $this->id($request);
        if ($this->zones->find($id) === null) {
            throw HttpException::notFound('This zone does not exist.');
        }
        [$name, $sort, $countries, $rates] = $this->validated($request, $id);

        $this->zones->save($id, $name, $sort, $countries, $rates);

        return Response::data($this->zones->find($id));
    }

    /** DELETE /v1/admin/shipping/zones/{id}: its countries then get "freight quoted" at checkout. */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'sales');
        if (!$this->zones->delete($this->id($request))) {
            throw HttpException::notFound('This zone does not exist.');
        }

        return Response::noContent();
    }

    /** @return array{0: string, 1: int, 2: list<string>, 3: list<array<string, mixed>>} */
    private function validated(Request $request, ?int $zoneId): array
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'sort_order' => 'nullable|int|min:0|max:100000',
            'countries' => 'required|array|min:1|max:' . self::MAX_COUNTRIES,
            'rates' => 'nullable|array|max:' . self::MAX_RATES,
        ])->validate();

        $countries = [];
        foreach ($data['countries'] as $country) {
            if (!is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
                throw new ValidationException(['countries' => 'Use 2-letter country codes.']);
            }
            $countries[] = strtoupper($country);
        }
        $countries = array_values(array_unique($countries));
        $taken = array_intersect_key($this->zones->takenCountries($zoneId), array_flip($countries));
        if ($taken !== []) {
            $list = implode(', ', array_map(static fn (string $code, string $zone): string => "{$code} ({$zone})", array_keys($taken), $taken));
            throw new ValidationException(['countries' => "Already in another zone: {$list}. A country can only be in one zone."]);
        }

        $rates = [];
        $seen = [];
        foreach ($data['rates'] ?? [] as $index => $input) {
            $row = Validator::make(is_array($input) ? $input : [], [
                'mode' => 'required|in:' . implode(',', ShippingZone::MODES),
                'from_qty' => 'required|numeric|min:0|max:100000',
                'rate_cents' => 'required|int|min:0|max:10000000',
                'fixed_cents' => 'nullable|int|min:0|max:100000000',
                'transit_min_days' => 'nullable|int|min:1|max:365',
                'transit_max_days' => 'nullable|int|min:1|max:365',
            ]);
            if (!$row->passes()) {
                $errors = $row->errors();
                throw new ValidationException(["rates.{$index}" => sprintf('Rate %d: %s', $index + 1, reset($errors))]);
            }
            $rate = $row->validate();
            if ($rate['rate_cents'] === 0 && (int) ($rate['fixed_cents'] ?? 0) === 0) {
                throw new ValidationException(["rates.{$index}" => sprintf('Rate %d: enter a rate or a fixed charge.', $index + 1)]);
            }
            if ($rate['mode'] === 'courier' && floor((float) $rate['from_qty']) !== (float) $rate['from_qty']) {
                throw new ValidationException(["rates.{$index}" => sprintf('Rate %d: courier rates start at a whole number of pieces.', $index + 1)]);
            }
            $key = $rate['mode'] . '|' . round((float) $rate['from_qty'], 3);
            if (isset($seen[$key])) {
                throw new ValidationException(["rates.{$index}" => sprintf('Rate %d: two %s rates start at the same quantity.', $index + 1, $rate['mode'])]);
            }
            $seen[$key] = true;
            if (isset($rate['transit_min_days'], $rate['transit_max_days']) && $rate['transit_min_days'] > $rate['transit_max_days']) {
                throw new ValidationException(["rates.{$index}" => sprintf('Rate %d: the shortest transit time is longer than the longest.', $index + 1)]);
            }
            $rates[] = [
                'mode' => $rate['mode'],
                'from_qty' => round((float) $rate['from_qty'], 3),
                'rate_cents' => $rate['rate_cents'],
                'fixed_cents' => $rate['fixed_cents'] ?? 0,
                'transit_min_days' => $rate['transit_min_days'] ?? null,
                'transit_max_days' => $rate['transit_max_days'] ?? null,
            ];
        }

        return [$data['name'], $data['sort_order'] ?? 0, $countries, $rates];
    }
}
