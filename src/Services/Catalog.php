<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductColor;
use App\Models\Review;
use App\Models\Settings;
use App\Models\SizeChart;

/**
 * What the shop shows: style cards for lists and full product pages (with approved buyer reviews).
 * Order rules a style leaves empty (MOQ, minimum per size, set size, lead time, sample price) come from the "commerce" settings.
 */
final class Catalog
{
    private const SPEC_KEYS = ['fit', 'fabric', 'composition', 'fabric_weight', 'stretch', 'wash', 'closure'];

    /** Reviews shown on a product page. */
    private const REVIEWS_PER_PRODUCT = 20;

    /**
     * The old site's sitemap numbered its product links 10051–10076 in order, instead of using the article numbers
     * (its pages linked the article numbers). Google indexed those links, so each number maps to its article.
     */
    private const SITEMAP_NUMBERS = [
        '10051' => '1051', '10052' => '1052', '10053' => '1053', '10054' => '1054', '10055' => '1055', '10056' => '1056', '10057' => '1057', '10058' => '1058',
        '10059' => '9650', '10060' => '9651', '10061' => '9652', '10062' => '9653', '10063' => '9654', '10064' => '9655', '10065' => '9656', '10066' => '9657',
        '10067' => '2230', '10068' => '2231', '10069' => '2232', '10070' => '2233', '10071' => '2234', '10072' => '2235',
        '10073' => '4333', '10074' => '4334', '10075' => '4335', '10076' => '4336',
    ];

    public function __construct(
        private readonly Product $products,
        private readonly ProductColor $colors,
        private readonly SizeChart $charts,
        private readonly Settings $settings,
        private readonly Review $reviews,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listing(array $filters, string $sort, int $page, int $perPage): array
    {
        $result = $this->products->listing($filters, $sort, $page, $perPage);
        $colors = $this->colors->forProducts(array_map(static fn (array $p): int => (int) $p['id'], $result['items']), true, 2);

        return [
            'items' => array_map(fn (array $product): array => $this->card($product, $colors[$product['id']] ?? []), $result['items']),
            'total' => $result['total'],
        ];
    }

    /** @return array<string, mixed>|null The full product page, or null when the style is not for sale. */
    public function product(string $slug): ?array
    {
        $product = $this->products->findActiveBySlug($slug);
        if ($product === null) {
            return null;
        }
        $colors = $this->colors->forProducts([$product['id']], true)[$product['id']] ?? [];
        if ($colors === []) {
            return null;
        }

        $chart = $product['size_chart_id'] !== null ? $this->charts->find($product['size_chart_id']) : null;
        $specs = array_intersect_key($product, array_flip(self::SPEC_KEYS));

        return [
            'slug' => $product['slug'],
            'name' => $product['name'],
            'style_no' => $product['style_no'],
            'segment' => $product['segment'],
            'product_type' => $product['product_type'],
            'specs' => $specs,
            'description_html' => (string) $product['description_html'],
            'features' => $product['features'],
            'sizes' => $product['sizes'],
            'size_chart' => $chart === null ? null : [
                'name' => $chart['name'],
                'unit' => $chart['unit'],
                'measurements' => $chart['measurements'],
                'rows' => $chart['size_rows'],
                'note' => $chart['note'],
            ],
            'price_cents' => $product['price_cents'],
            'price_from_cents' => $product['price_min'],
            'price_to_cents' => $product['price_max'],
            'tiers' => $this->products->tiers($product['id']),
            'rules' => $this->rules($product),
            'piece_weight_g' => $product['piece_weight_g'],
            'colors' => array_map(static fn (array $color): array => [
                'id' => $color['id'],
                'code' => $color['code'],
                'name' => $color['name'],
                'slug' => $color['slug'],
                'hex' => $color['hex'],
                'price_cents' => $color['price_cents'] ?? $product['price_cents'],
                'specs' => array_merge($specs, $color['spec_overrides']),
                'images' => $color['images'],
            ], $colors),
            'is_featured' => $product['is_featured'],
            'seo_title' => $product['seo_title'],
            'seo_description' => $product['seo_description'],
            'updated_at' => $product['updated_at'],
            'related' => $this->related($product),
            'reviews' => Reviews::present(
                $this->reviews->statsForProduct($product['id']),
                $this->reviews->approvedForProduct($product['id'], self::REVIEWS_PER_PRODUCT),
            ),
        ];
    }

    /**
     * Where an old product URL should go: the style and color for an old article number,
     * or the category page when that style is no longer for sale.
     *
     * @return array{segment: string, product_type: string, slug: ?string, color: ?string}|null
     */
    public function legacy(string $article): ?array
    {
        $match = $this->colors->findByArticle($article)
            ?? (isset(self::SITEMAP_NUMBERS[$article]) ? $this->colors->findByArticle(self::SITEMAP_NUMBERS[$article]) : null);
        $product = $match === null ? null : $this->products->find($match['product_id']);
        if ($match === null || $product === null) {
            return null;
        }
        $forSale = $product['status'] === 'active' && $this->products->findActiveBySlug($product['slug']) !== null;

        return [
            'segment' => $product['segment'],
            'product_type' => $product['product_type'],
            'slug' => $forSale ? $product['slug'] : null,
            'color' => $forSale ? $match['color_slug'] : null,
        ];
    }

    /** @return array{moq_per_color: int, set_pcs_per_size: int, min_pcs_per_size: int, lead_time_days: int, sample_price_cents: int} */
    public function rules(array $product): array
    {
        $commerce = $this->settings->get('commerce', []);
        $commerce = is_array($commerce) ? $commerce : [];

        return [
            'moq_per_color' => $product['moq_per_color'] ?? (int) ($commerce['moq_per_color'] ?? 100),
            'set_pcs_per_size' => $product['set_pcs_per_size'] ?? (int) ($commerce['set_pcs_per_size'] ?? 2),
            'min_pcs_per_size' => $product['min_pcs_per_size'] ?? (int) ($commerce['min_pcs_per_size'] ?? Commerce::MIN_PCS_PER_SIZE),
            'lead_time_days' => $product['lead_time_days'] ?? (int) ($commerce['lead_time_days'] ?? 30),
            'sample_price_cents' => $product['sample_price_cents'] ?? (int) ($commerce['sample_price_cents'] ?? Commerce::SAMPLE_PRICE_CENTS),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param list<array<string, mixed>> $colors
     * @return array<string, mixed>
     */
    private function card(array $product, array $colors): array
    {
        return [
            'slug' => $product['slug'],
            'name' => $product['name'],
            'style_no' => $product['style_no'],
            'segment' => $product['segment'],
            'product_type' => $product['product_type'],
            'fit' => $product['fit'],
            'stretch' => $product['stretch'],
            'fabric_weight' => $product['fabric_weight'],
            'price_from_cents' => $product['price_min'],
            'price_to_cents' => $product['price_max'],
            'is_featured' => $product['is_featured'],
            'colors' => array_map(static fn (array $color): array => [
                'code' => $color['code'],
                'name' => $color['name'],
                'slug' => $color['slug'],
                'hex' => $color['hex'],
                'image' => $color['images'][0] ?? null,
                'hover_image' => $color['images'][1] ?? null,
            ], $colors),
        ];
    }

    /**
     * Up to 4 other styles: same category first, then the same segment.
     *
     * @param array<string, mixed> $product
     * @return list<array<string, mixed>>
     */
    private function related(array $product): array
    {
        $related = $this->listing(['segment' => $product['segment'], 'type' => $product['product_type'], 'exclude' => $product['id']], 'featured', 1, 4)['items'];
        if (count($related) < 4) {
            $more = $this->listing(['segment' => $product['segment'], 'exclude' => $product['id']], 'featured', 1, 8)['items'];
            $seen = array_column($related, 'slug');
            foreach ($more as $item) {
                if (count($related) >= 4) {
                    break;
                }
                if (!in_array($item['slug'], $seen, true)) {
                    $related[] = $item;
                    $seen[] = $item['slug'];
                }
            }
        }

        return $related;
    }
}
