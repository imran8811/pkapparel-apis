<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Freight;
use PHPUnit\Framework\TestCase;

/** The freight formulas from PLAN §6: packing, air weight breaks, sea minimum and full containers. */
final class FreightTest extends TestCase
{
    private const AIR = [
        ['from_qty' => 0.0, 'rate_cents' => 500, 'fixed_cents' => 5000, 'transit_min_days' => 5, 'transit_max_days' => 8],
        ['from_qty' => 100.0, 'rate_cents' => 400, 'fixed_cents' => 5000, 'transit_min_days' => 5, 'transit_max_days' => 8],
        ['from_qty' => 300.0, 'rate_cents' => 350, 'fixed_cents' => 5000, 'transit_min_days' => 5, 'transit_max_days' => 8],
    ];

    private const SEA = [
        ['from_qty' => 0.0, 'rate_cents' => 6000, 'fixed_cents' => 15000, 'transit_min_days' => 25, 'transit_max_days' => 35],
    ];

    public function testPackingCountsCartonsWeightAndVolumePerStyle(): void
    {
        $packing = Freight::packing([
            ['pcs' => 120, 'piece_weight_g' => 600, 'pcs_per_carton' => 50, 'carton_length_mm' => 600, 'carton_width_mm' => 400, 'carton_height_mm' => 400, 'carton_weight_g' => 1000],
            ['pcs' => 100, 'piece_weight_g' => 300, 'pcs_per_carton' => 100, 'carton_length_mm' => 500, 'carton_width_mm' => 400, 'carton_height_mm' => 300, 'carton_weight_g' => 800],
            ['pcs' => 0, 'piece_weight_g' => 300, 'pcs_per_carton' => 100, 'carton_length_mm' => 500, 'carton_width_mm' => 400, 'carton_height_mm' => 300, 'carton_weight_g' => 800],
        ]);

        // 3 cartons (120 / 50, rounded up) + 1 carton; 72 kg + 3 kg cartons + 30 kg + 0.8 kg carton
        self::assertSame(['cartons' => 4, 'gross_g' => 105800, 'volume_cm3' => 348000, 'cbm' => 0.348], $packing);
    }

    public function testAirUsesTheChargeableWeightAndTheCheapestWeightBreak(): void
    {
        $packing = ['cartons' => 4, 'gross_g' => 105800, 'volume_cm3' => 348000, 'cbm' => 0.348];

        $air = Freight::air($packing, self::AIR);

        // 105.8 kg is heavier than the volume weight (0.348 × 167 = 58.1 kg) and rounds up to 106 kg.
        self::assertSame(106 * 400 + 5000, $air['cost_cents']);
        self::assertSame('106 kg chargeable', $air['basis']);
        self::assertSame([5, 8], [$air['transit_min_days'], $air['transit_max_days']]);
    }

    public function testLightBulkyShipmentsPayForTheirVolume(): void
    {
        // 20 kg but 0.5 CBM: 0.5 × 167 = 83.5 kg chargeable
        $air = Freight::air(['gross_g' => 20000, 'cbm' => 0.5], self::AIR);

        // 83.5 kg at $5 = $417.50, or charged as 100 kg at $4 = $400: the cheaper weight break wins.
        self::assertSame(100 * 400 + 5000, $air['cost_cents']);
        self::assertSame('100 kg chargeable', $air['basis']);
    }

    public function testSeaChargesAtLeastOneCubicMeter(): void
    {
        $sea = Freight::sea(['cbm' => 0.348], self::SEA, 15.0);

        self::assertSame(6000 + 15000, $sea['cost_cents']);
        self::assertSame('1 CBM', $sea['basis']);
        self::assertFalse($sea['quoted']);
    }

    public function testLargeSeaShipmentsAreQuotedAsFullContainers(): void
    {
        $sea = Freight::sea(['cbm' => 16.2], self::SEA, 15.0);

        self::assertNull($sea['cost_cents']);
        self::assertTrue($sea['quoted']);
        self::assertSame('fcl', $sea['reason']);
    }

    public function testWithoutRatesTheFreightIsQuoted(): void
    {
        $air = Freight::air(['gross_g' => 50000, 'cbm' => 0.2], []);

        self::assertNull($air['cost_cents']);
        self::assertSame('no_rate', $air['reason']);
    }
}
