<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Freight rates: countries are grouped into zones, and each zone has rate bands per mode.
 * Air bands start at a chargeable weight (kg); sea bands start at a volume (CBM);
 * courier bands (samples) start at a number of pieces.
 */
final class ShippingZone
{
    public const MODES = ['air', 'sea', 'courier'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> Every zone with its countries and rates (admin). */
    public function all(): array
    {
        $zones = $this->db->fetchAll('SELECT * FROM shipping_zones ORDER BY sort_order, name, id');

        return array_map(fn (array $zone): array => $this->withDetails($zone), $zones);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $zone = $this->db->fetch('SELECT * FROM shipping_zones WHERE id = ?', [$id]);

        return $zone === null ? null : $this->withDetails($zone);
    }

    /** @return array<string, mixed>|null The zone a country belongs to, with its rates. */
    public function forCountry(string $countryCode): ?array
    {
        $zone = $this->db->fetch(
            'SELECT z.* FROM shipping_zones z JOIN shipping_zone_countries c ON c.zone_id = z.id WHERE c.country_code = ?',
            [strtoupper($countryCode)],
        );

        return $zone === null ? null : $this->withDetails($zone);
    }

    /** @return array<string, string> country code => name of the zone it already belongs to (other zones only). */
    public function takenCountries(?int $exceptZoneId = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.country_code, z.name FROM shipping_zone_countries c JOIN shipping_zones z ON z.id = c.zone_id WHERE z.id <> ?',
            [$exceptZoneId ?? 0],
        );

        return array_column($rows, 'name', 'country_code');
    }

    /**
     * @param list<string> $countries
     * @param list<array<string, mixed>> $rates
     */
    public function save(?int $id, string $name, int $sortOrder, array $countries, array $rates): int
    {
        return $this->db->transaction(function () use ($id, $name, $sortOrder, $countries, $rates): int {
            if ($id === null) {
                $id = $this->db->insert('shipping_zones', ['name' => $name, 'sort_order' => $sortOrder]);
            } else {
                $this->db->update('shipping_zones', ['name' => $name, 'sort_order' => $sortOrder], ['id' => $id]);
                $this->db->execute('DELETE FROM shipping_zone_countries WHERE zone_id = ?', [$id]);
                $this->db->execute('DELETE FROM shipping_rates WHERE zone_id = ?', [$id]);
            }
            foreach ($countries as $country) {
                $this->db->insert('shipping_zone_countries', ['country_code' => $country, 'zone_id' => $id]);
            }
            foreach ($rates as $rate) {
                $this->db->insert('shipping_rates', [
                    'zone_id' => $id,
                    'mode' => $rate['mode'],
                    'from_qty' => $rate['from_qty'],
                    'rate_cents' => $rate['rate_cents'],
                    'fixed_cents' => $rate['fixed_cents'],
                    'transit_min_days' => $rate['transit_min_days'],
                    'transit_max_days' => $rate['transit_max_days'],
                ]);
            }

            return $id;
        });
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM shipping_zones WHERE id = ?', [$id]) > 0;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array<string, mixed>
     */
    private function withDetails(array $zone): array
    {
        $id = (int) $zone['id'];
        $countries = $this->db->fetchAll('SELECT country_code FROM shipping_zone_countries WHERE zone_id = ? ORDER BY country_code', [$id]);
        $rates = $this->db->fetchAll('SELECT * FROM shipping_rates WHERE zone_id = ? ORDER BY mode, from_qty', [$id]);

        return [
            'id' => $id,
            'name' => (string) $zone['name'],
            'sort_order' => (int) $zone['sort_order'],
            'countries' => array_map(static fn (array $row): string => (string) $row['country_code'], $countries),
            'rates' => array_map(static fn (array $rate): array => [
                'mode' => (string) $rate['mode'],
                'from_qty' => (float) $rate['from_qty'],
                'rate_cents' => (int) $rate['rate_cents'],
                'fixed_cents' => (int) $rate['fixed_cents'],
                'transit_min_days' => $rate['transit_min_days'] === null ? null : (int) $rate['transit_min_days'],
                'transit_max_days' => $rate['transit_max_days'] === null ? null : (int) $rate['transit_max_days'],
            ], $rates),
        ];
    }
}
