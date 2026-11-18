<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Quotations;
use PHPUnit\Framework\TestCase;

/** How a quotation style's price is made (the admin form shows the same figures while staff type). */
final class QuotationPriceTest extends TestCase
{
    /** @var array<string, mixed> Rs 850 per metre × 1.35 m, Rs 350 of making and other costs, 15% profit. */
    private const JEANS = [
        'fabric_rate_cents' => 85000, 'fabric_consumption' => '1.35',
        'cutting_cents' => 2500, 'stitching_cents' => 12000, 'washing_cents' => 8000, 'packing_cents' => 1500,
        'printing_cents' => 0, 'embroidery_cents' => 0, 'accessories_cents' => 6000, 'overhead_cents' => 5000, 'extra_cents' => 0,
        'profit_type' => 'percent', 'profit_percent' => '15', 'profit_cents' => 0,
    ];

    public function testFabricIsRateTimesConsumptionAndProfitIsOnTheCost(): void
    {
        // Fabric 850 × 1.35 = 1,147.50; cost 1,497.50; profit 15% = 224.625 → 224.63; price 1,722.13 → Rs 1,722.
        self::assertSame(
            ['fabric_cost_cents' => 114750, 'cost_cents' => 149750, 'profit_cents' => 22463, 'price_cents' => 172213, 'unit_price_cents' => 172200],
            Quotations::price(self::JEANS, 'PKR', null),
        );
    }

    public function testAnotherCurrencyIsTheRupeePriceAtTheExchangeRate(): void
    {
        // Rs 1,722.13 ÷ 280.5 = $6.1395… → $6.14; at 300 → $5.7404… → $5.74.
        self::assertSame(614, Quotations::price(self::JEANS, 'USD', '280.5')['unit_price_cents']);
        self::assertSame(574, Quotations::price(self::JEANS, 'EUR', '300')['unit_price_cents']);
        self::assertSame(0, Quotations::price(self::JEANS, 'USD', null)['unit_price_cents'], 'No rate, no price');
    }

    public function testProfitAsAnAmountAndRounding(): void
    {
        $style = ['profit_type' => 'amount', 'profit_cents' => 15000, 'fabric_consumption' => '2'] + self::JEANS;
        $price = Quotations::price($style, 'PKR', null);
        // Fabric 850 × 2 = 1,700; cost 2,050; + Rs 150 = 2,200.
        self::assertSame([170000, 205000, 15000, 220000, 220000], array_values($price));

        // Halves round up: 333.33 × 1.5 = 499.995 → 500.00; 12.5% of 0.01 → 0; Rs 0.50 → Rs 1.
        $small = ['fabric_rate_cents' => 33333, 'fabric_consumption' => '1.5', 'profit_type' => 'percent', 'profit_percent' => '12.5'];
        self::assertSame(49999 + 1, Quotations::price($small, 'PKR', null)['fabric_cost_cents']);
        self::assertSame(100, Quotations::price(['extra_cents' => 50, 'profit_type' => 'amount'], 'PKR', null)['unit_price_cents']);
        self::assertSame(0, Quotations::price(['extra_cents' => 49, 'profit_type' => 'amount'], 'PKR', null)['unit_price_cents']);
    }
}
