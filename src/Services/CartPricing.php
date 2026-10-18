<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductColor;

/**
 * Prices cart lines from the live catalog and checks the order rules.
 *
 * - The price per piece is the color's price (or the style's), less any volume-tier discount.
 *   Tiers count every color of a style together, like on the product page.
 * - Each color must reach the style's minimum (MOQ), and every size ordered in a color needs the minimum per size
 *   (e.g. 20: 100 pieces of a color cover at most 5 sizes). Colors and styles no longer sold block checkout.
 * - Quantities for sizes a style no longer offers are left out (and reported).
 */
final class CartPricing
{
    public function __construct(
        private readonly Product $products,
        private readonly ProductColor $colors,
        private readonly Catalog $catalog,
        private readonly Commerce $commerce,
    ) {
    }

    /**
     * @param list<array{product_id: int, color_id: int, size_qty: array<string, int>}> $lines
     * @return array{
     *     styles: list<array<string, mixed>>, line_count: int, total_pcs: int, goods_cents: int,
     *     lead_time_days: int, packing: array{cartons: int, gross_g: int, volume_cm3: int, cbm: float},
     *     issues: list<string>, can_checkout: bool
     * }
     */
    public function evaluate(array $lines): array
    {
        $productIds = array_values(array_unique(array_map(static fn (array $l): int => $l['product_id'], $lines)));
        $colorsByProduct = $this->colors->forProducts($productIds, false, 1);

        $byProduct = [];
        foreach ($lines as $line) {
            $byProduct[$line['product_id']][] = $line;
        }

        $styles = [];
        $issues = [];
        $packingGroups = [];
        $leadTime = 0;
        foreach ($byProduct as $productId => $productLines) {
            $product = $this->products->find($productId);
            if ($product === null) {
                continue;
            }
            $style = $this->style($product, $productLines, $colorsByProduct[$productId] ?? [], $issues);
            $styles[] = $style;
            if ($style['pcs'] > 0) {
                $packingGroups[] = ['pcs' => $style['pcs']] + $style['packing'];
                $leadTime = max($leadTime, $style['rules']['lead_time_days']);
            }
        }

        $colorLines = array_merge(...array_map(static fn (array $s): array => $s['colors'], $styles ?: [['colors' => []]]));
        $blocked = array_filter($colorLines, static fn (array $c): bool => !$c['available'] || $c['below_moq'] || $c['small_sizes'] !== []);
        $totalPcs = array_sum(array_map(static fn (array $s): int => $s['pcs'], $styles));

        return [
            'styles' => array_map(static function (array $style): array {
                unset($style['packing']);

                return $style;
            }, $styles),
            'line_count' => count($colorLines),
            'total_pcs' => $totalPcs,
            'goods_cents' => array_sum(array_map(static fn (array $s): int => $s['goods_cents'], $styles)),
            'lead_time_days' => $leadTime > 0 ? $leadTime : $this->commerce->leadTimeDays(),
            'packing' => Freight::packing($packingGroups),
            'issues' => $issues,
            'can_checkout' => $colorLines !== [] && $blocked === [] && $totalPcs > 0,
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array{product_id: int, color_id: int, size_qty: array<string, int>}> $lines
     * @param list<array<string, mixed>> $colors all colors of the style, with their first photo
     * @param list<string> $issues
     * @return array<string, mixed>
     */
    private function style(array $product, array $lines, array $colors, array &$issues): array
    {
        $rules = $this->catalog->rules($product);
        $sizes = array_map('strval', $product['sizes']);
        $styleActive = $product['status'] === 'active';
        $colorsById = array_column($colors, null, 'id');

        $entries = [];
        foreach ($lines as $line) {
            $color = $colorsById[$line['color_id']] ?? null;
            if ($color === null) {
                continue;
            }
            $sizeQty = [];
            foreach ($sizes as $size) {
                $qty = (int) ($line['size_qty'][$size] ?? 0);
                if ($qty > 0) {
                    $sizeQty[$size] = $qty;
                }
            }
            $dropped = array_values(array_diff(array_map('strval', array_keys(array_filter($line['size_qty']))), $sizes));
            if ($dropped !== []) {
                $issues[] = sprintf('%s no longer comes in size %s, so those pieces were left out.', $product['name'], implode(', ', $dropped));
            }
            $entries[] = ['color' => $color, 'size_qty' => $sizeQty, 'pcs' => array_sum($sizeQty)];
        }

        // Volume tiers count every color of the style that can still be ordered.
        $available = static fn (array $entry): bool => $styleActive && $entry['color']['is_active'];
        $stylePcs = array_sum(array_map(static fn (array $e): int => $available($e) ? $e['pcs'] : 0, $entries));
        $tiers = $this->products->tiers($product['id']);
        $tierPrice = self::tierPrice($product['price_cents'], $tiers, $stylePcs);
        $discount = $product['price_cents'] - $tierPrice;

        $colorLines = [];
        foreach ($entries as $entry) {
            $color = $entry['color'];
            $isAvailable = $available($entry);
            $unit = max(0, ($color['price_cents'] ?? $product['price_cents']) - $discount);
            $belowMoq = $isAvailable && $entry['pcs'] < $rules['moq_per_color'];
            // Sizes ordered below the minimum per size.
            $smallSizes = $isAvailable
                ? array_values(array_map('strval', array_keys(array_filter($entry['size_qty'], static fn (int $qty): bool => $qty < $rules['min_pcs_per_size']))))
                : [];
            if (!$isAvailable) {
                $issues[] = sprintf('%s in %s is no longer available. Remove it to check out.', $product['name'], $color['name']);
            } elseif ($belowMoq) {
                $issues[] = sprintf(
                    '%s in %s needs %d more pieces (minimum %d per color).',
                    $product['name'],
                    $color['name'],
                    $rules['moq_per_color'] - $entry['pcs'],
                    $rules['moq_per_color'],
                );
            }
            if ($smallSizes !== []) {
                $issues[] = sprintf(
                    '%s in %s: each size needs at least %d pieces. Raise or remove size %s.',
                    $product['name'],
                    $color['name'],
                    $rules['min_pcs_per_size'],
                    implode(', ', $smallSizes),
                );
            }
            $colorLines[] = [
                'color_id' => $color['id'],
                'code' => $color['code'],
                'name' => $color['name'],
                'slug' => $color['slug'],
                'hex' => $color['hex'],
                'image' => $color['images'][0] ?? null,
                'size_qty' => (object) $entry['size_qty'],
                'pcs' => $entry['pcs'],
                'unit_price_cents' => $unit,
                'line_cents' => $isAvailable ? $unit * $entry['pcs'] : 0,
                'available' => $isAvailable,
                'below_moq' => $belowMoq,
                'small_sizes' => $smallSizes,
            ];
        }

        $countable = array_filter($colorLines, static fn (array $c): bool => $c['available']);

        return [
            'product_id' => $product['id'],
            'slug' => $product['slug'],
            'name' => $product['name'],
            'style_no' => $product['style_no'],
            'segment' => $product['segment'],
            'product_type' => $product['product_type'],
            'sizes' => $sizes,
            'available' => $styleActive,
            'rules' => $rules,
            'price_cents' => $product['price_cents'],
            'tiers' => $tiers,
            'tier_price_cents' => $tiers === [] ? null : $tierPrice,
            'colors' => $colorLines,
            'pcs' => array_sum(array_map(static fn (array $c): int => $c['pcs'], $countable)),
            'goods_cents' => array_sum(array_map(static fn (array $c): int => $c['line_cents'], $countable)),
            'packing' => $this->packing($product),
        ];
    }

    /**
     * The lowest tier price the quantity reaches (same rule as the product page).
     *
     * @param list<array{min_qty: int, price_cents: int}> $tiers
     */
    public static function tierPrice(int $baseCents, array $tiers, int $pieces): int
    {
        $price = $baseCents;
        foreach ($tiers as $tier) {
            if ($pieces >= $tier['min_qty'] && $tier['price_cents'] < $price) {
                $price = $tier['price_cents'];
            }
        }

        return $price;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, int> The style's packing data, with the settings' defaults where it has none.
     */
    private function packing(array $product): array
    {
        $packing = [];
        foreach ($this->commerce->packingDefaults() as $key => $default) {
            $value = (int) ($product[$key] ?? 0);
            $packing[$key] = $value > 0 ? $value : $default;
        }

        return $packing;
    }
}
