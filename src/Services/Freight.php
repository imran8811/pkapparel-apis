<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShippingZone;

/**
 * Freight estimates from the rate table staff keep in Admin → Shipping.
 *
 *   Cartons per style = ceil(pieces / pieces per carton)
 *   Gross weight      = pieces × piece weight + cartons × carton weight
 *   Volume            = cartons × carton length × width × height
 *   Air: chargeable kg = max(gross kg, CBM × 167), rounded up to 0.5 kg
 *   Sea (LCL): billable CBM = max(CBM, 1); above the full-container limit the freight is quoted instead
 *
 * Rates come in bands ("from 100 kg: $3.80/kg"). As with airline weight breaks, a shipment
 * pays the cheapest band, counting at least the band's starting quantity.
 * Estimates only: the final freight is confirmed before the balance is paid.
 *
 * Samples go by courier, priced per piece: a fixed amount plus a price per piece (bands by pieces).
 */
final class Freight
{
    /** Volumetric weight for air freight (IATA 1:6000). */
    public const KG_PER_CBM = 167;

    /** Sea freight (LCL) is charged for at least 1 CBM. */
    public const SEA_MIN_CBM = 1.0;

    public function __construct(private readonly ShippingZone $zones, private readonly Commerce $commerce)
    {
    }

    /**
     * Cartons, weight and volume of an order, packed style by style.
     *
     * @param list<array{pcs: int, piece_weight_g: int, pcs_per_carton: int, carton_length_mm: int, carton_width_mm: int, carton_height_mm: int, carton_weight_g: int}> $groups
     * @return array{cartons: int, gross_g: int, volume_cm3: int, cbm: float}
     */
    public static function packing(array $groups): array
    {
        $cartons = 0;
        $grossG = 0;
        $volumeMm3 = 0;
        foreach ($groups as $group) {
            if ($group['pcs'] <= 0) {
                continue;
            }
            $groupCartons = (int) ceil($group['pcs'] / max(1, $group['pcs_per_carton']));
            $cartons += $groupCartons;
            $grossG += $group['pcs'] * $group['piece_weight_g'] + $groupCartons * $group['carton_weight_g'];
            $volumeMm3 += $groupCartons * $group['carton_length_mm'] * $group['carton_width_mm'] * $group['carton_height_mm'];
        }
        $volumeCm3 = (int) round($volumeMm3 / 1000);

        return ['cartons' => $cartons, 'gross_g' => $grossG, 'volume_cm3' => $volumeCm3, 'cbm' => round($volumeCm3 / 1_000_000, 3)];
    }

    /**
     * Freight per shipping mode to a country.
     *
     * @param array{cartons: int, gross_g: int, volume_cm3: int, cbm: float} $packing
     * @return array<string, array{cost_cents: ?int, quoted: bool, reason: ?string, transit_min_days: ?int, transit_max_days: ?int, basis: ?string}>
     *         keyed by air, sea and forwarder. cost_cents is null when the freight will be quoted (reason: no_rate or fcl).
     */
    public function quote(?string $countryCode, array $packing): array
    {
        $zone = $countryCode === null ? null : $this->zones->forCountry($countryCode);
        $rates = $zone['rates'] ?? [];
        $forMode = static fn (string $mode): array => array_values(array_filter($rates, static fn (array $r): bool => $r['mode'] === $mode));

        return [
            'air' => self::air($packing, $forMode('air')),
            'sea' => self::sea($packing, $forMode('sea'), $this->commerce->fclFromCbm()),
            'forwarder' => ['cost_cents' => 0, 'quoted' => false, 'reason' => null, 'transit_min_days' => null, 'transit_max_days' => null, 'basis' => null],
        ];
    }

    /**
     * Courier cost of a sample order to a country; quoted (cost_cents null) when the zone has no courier rate.
     *
     * @return array{cost_cents: ?int, quoted: bool, reason: ?string, transit_min_days: ?int, transit_max_days: ?int, basis: ?string}
     */
    public function courier(?string $countryCode, int $pieces): array
    {
        $zone = $countryCode === null ? null : $this->zones->forCountry($countryCode);
        $rates = array_values(array_filter($zone['rates'] ?? [], static fn (array $r): bool => $r['mode'] === 'courier'));
        $best = self::cheapestBand($rates, (float) max(1, $pieces));
        if ($best === null) {
            return self::quoted('no_rate', $rates);
        }

        return self::estimate($best, sprintf('%d %s', $pieces, $pieces === 1 ? 'piece' : 'pieces'));
    }

    /**
     * @param array{gross_g: int, cbm: float} $packing
     * @param list<array{from_qty: float, rate_cents: int, fixed_cents: int, transit_min_days: ?int, transit_max_days: ?int}> $rates
     * @return array{cost_cents: ?int, quoted: bool, reason: ?string, transit_min_days: ?int, transit_max_days: ?int, basis: ?string}
     */
    public static function air(array $packing, array $rates): array
    {
        $grossKg = $packing['gross_g'] / 1000;
        $chargeable = ceil(max($grossKg, $packing['cbm'] * self::KG_PER_CBM) * 2) / 2;
        $best = self::cheapestBand($rates, $chargeable);
        if ($best === null) {
            return self::quoted('no_rate', $rates);
        }

        return self::estimate($best, sprintf('%s kg chargeable', self::number($best['billable'])));
    }

    /**
     * @param array{cbm: float} $packing
     * @param list<array{from_qty: float, rate_cents: int, fixed_cents: int, transit_min_days: ?int, transit_max_days: ?int}> $rates
     * @return array{cost_cents: ?int, quoted: bool, reason: ?string, transit_min_days: ?int, transit_max_days: ?int, basis: ?string}
     */
    public static function sea(array $packing, array $rates, float $fclFromCbm): array
    {
        if ($packing['cbm'] > $fclFromCbm) {
            return self::quoted('fcl', $rates);
        }
        $best = self::cheapestBand($rates, max($packing['cbm'], self::SEA_MIN_CBM));
        if ($best === null) {
            return self::quoted('no_rate', $rates);
        }

        return self::estimate($best, sprintf('%s CBM', self::number($best['billable'])));
    }

    /**
     * @param list<array{from_qty: float, rate_cents: int, fixed_cents: int, transit_min_days: ?int, transit_max_days: ?int}> $rates
     * @return array{cost: int, billable: float, rate: array<string, mixed>}|null
     */
    private static function cheapestBand(array $rates, float $quantity): ?array
    {
        $best = null;
        foreach ($rates as $rate) {
            $billable = max($quantity, (float) $rate['from_qty']);
            $cost = (int) round($billable * $rate['rate_cents']) + $rate['fixed_cents'];
            if ($best === null || $cost < $best['cost']) {
                $best = ['cost' => $cost, 'billable' => $billable, 'rate' => $rate];
            }
        }

        return $best;
    }

    /**
     * @param array{cost: int, billable: float, rate: array<string, mixed>} $best
     * @return array{cost_cents: int, quoted: bool, reason: null, transit_min_days: ?int, transit_max_days: ?int, basis: string}
     */
    private static function estimate(array $best, string $basis): array
    {
        return [
            'cost_cents' => $best['cost'],
            'quoted' => false,
            'reason' => null,
            'transit_min_days' => $best['rate']['transit_min_days'],
            'transit_max_days' => $best['rate']['transit_max_days'],
            'basis' => $basis,
        ];
    }

    /**
     * @param list<array{transit_min_days: ?int, transit_max_days: ?int}> $rates
     * @return array{cost_cents: null, quoted: bool, reason: string, transit_min_days: ?int, transit_max_days: ?int, basis: null}
     */
    private static function quoted(string $reason, array $rates): array
    {
        return [
            'cost_cents' => null,
            'quoted' => true,
            'reason' => $reason,
            'transit_min_days' => $rates[0]['transit_min_days'] ?? null,
            'transit_max_days' => $rates[0]['transit_max_days'] ?? null,
            'basis' => null,
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');
    }
}
