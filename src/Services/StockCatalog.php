<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductTypes;
use App\Models\StockColor;
use App\Models\StockStyle;

/**
 * What the stock pages show: style cards for lists and full style pages, with the sets in stock per color.
 * Styles with sets in stock come first; sold-out styles stay listed (and their pages stay up) until staff hide them.
 */
final class StockCatalog
{
    private const RELATED = 4;

    public function __construct(
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
    ) {
    }

    /** @return list<array<string, mixed>> Cards of the active styles, in stock first, then featured and in staff order. */
    public function listing(?string $segment): array
    {
        $styles = $this->styles->active($segment);
        $colors = $this->colors->forStyles(array_map(static fn (array $s): int => (int) $s['id'], $styles), true, 2);
        $cards = array_map(fn (array $style): array => $this->card($style, $colors[$style['id']] ?? []), $styles);
        // usort is stable, so styles keep their order within "in stock" and "sold out".
        usort($cards, static fn (array $a, array $b): int => ($b['sets_in_stock'] > 0) <=> ($a['sets_in_stock'] > 0));

        return $cards;
    }

    /** @return array<string, mixed>|null The full style page, or null when the style is not shown. */
    public function style(string $slug): ?array
    {
        $style = $this->styles->findActiveBySlug($slug);
        if ($style === null) {
            return null;
        }
        $colors = $this->colors->forStyles([$style['id']], true)[$style['id']] ?? [];
        if ($colors === []) {
            return null;
        }
        $related = array_values(array_filter($this->listing($style['segment']), static fn (array $card): bool => $card['slug'] !== $style['slug']));

        return [
            'slug' => $style['slug'],
            'name' => $style['name'],
            'style_no' => $style['style_no'],
            'segment' => $style['segment'],
            'specs' => self::specValues($style),
            // Labeled and in display order, as for the wholesale shop's jeans ("Inseam" for length…).
            'spec_fields' => array_map(
                static fn (array $field): array => ['key' => $field['key'], 'label' => $field['label']],
                ProductTypes::specs(StockStyle::PRODUCT_TYPE),
            ),
            'description_html' => (string) $style['description_html'],
            'set' => ['sizes' => $style['set_sizes'], 'pieces' => $style['set_pieces']],
            'piece_price_cents' => $style['piece_price_cents'],
            'set_price_cents' => $style['set_price_cents'],
            'sets_in_stock' => array_sum(array_column($colors, 'sets_in_stock')),
            'colors' => array_map(static fn (array $color): array => [
                'id' => $color['id'],
                'code' => $color['code'],
                'name' => $color['name'],
                'slug' => $color['slug'],
                'hex' => $color['hex'],
                'sets_in_stock' => $color['sets_in_stock'],
                'images' => $color['images'],
            ], $colors),
            'is_featured' => $style['is_featured'],
            'seo_title' => $style['seo_title'],
            'seo_description' => $style['seo_description'],
            'updated_at' => $style['updated_at'],
            'related' => array_slice($related, 0, self::RELATED),
        ];
    }

    /**
     * The style's values for the jeans specs, in display order (null when not set).
     *
     * @param array<string, mixed> $style decoded stock_styles row
     * @return array<string, ?string>
     */
    public static function specValues(array $style): array
    {
        $values = [];
        foreach (ProductTypes::specKeys(StockStyle::PRODUCT_TYPE) as $key) {
            $value = $style['specs'][$key] ?? null;
            $values[$key] = $value === null || $value === '' ? null : (string) $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $style
     * @param list<array<string, mixed>> $colors
     * @return array<string, mixed>
     */
    private function card(array $style, array $colors): array
    {
        return [
            'slug' => $style['slug'],
            'name' => $style['name'],
            'style_no' => $style['style_no'],
            'segment' => $style['segment'],
            'fit' => $style['specs']['fit'] ?? null,
            'stretch' => $style['specs']['stretch'] ?? null,
            'sizes' => array_column($style['set_sizes'], 'size'),
            'set_pieces' => $style['set_pieces'],
            'piece_price_cents' => $style['piece_price_cents'],
            'set_price_cents' => $style['set_price_cents'],
            'sets_in_stock' => array_sum(array_column($colors, 'sets_in_stock')),
            'is_featured' => $style['is_featured'],
            'colors' => array_map(static fn (array $color): array => [
                'code' => $color['code'],
                'name' => $color['name'],
                'slug' => $color['slug'],
                'hex' => $color['hex'],
                'sets_in_stock' => $color['sets_in_stock'],
                'image' => $color['images'][0] ?? null,
                'hover_image' => $color['images'][1] ?? null,
            ], $colors),
        ];
    }
}
