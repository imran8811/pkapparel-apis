<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductTypes;
use App\Models\StockColor;
use App\Models\StockStyle;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Copies the wholesale shop's jeans for men (and for boys, should it have any) into the stock (owner request
 * 2026-11-09): name, style no., specs, the set (the wholesale waist sizes with the wholesale set's pieces per size),
 * colors and photos. A copy is a stock draft with no price and no sets in stock; staff add both, then show it.
 * From then on it is separate from the wholesale style: its photos are copied files, and neither shop changes the other.
 *
 * Description paragraphs about the made-to-order wholesale business (minimum order, own labels, shipping…) are left
 * out, and so are the wholesale SEO texts: the stock pages describe ready stock and never name the country.
 */
final class StockFromWholesale
{
    /** Text about the wholesale business, which the stock pages must not show. */
    private const WHOLESALE_WORDING = '/pakistan|wholesale|export|made[ -]to[ -]order|minimum order|\bmoq\b|pieces per colou?r|from \d+ pieces|private[ -]label|own (?:brand )?labels?|packaging|lead time|deposit|\bship(?:s|ped|ping)?\b|by air|by sea|freight|forwarder|tech pack|\bquotes?\b|\bsamples?\b/i';

    public function __construct(
        private readonly Database $db,
        private readonly Product $products,
        private readonly ProductColor $productColors,
        private readonly Catalog $catalog,
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
        private readonly ImageStore $images,
        private readonly HtmlSanitizer $sanitizer,
    ) {
    }

    /**
     * The wholesale jeans for men and boys (shown and drafts, not archived), each with what its copy would be. A style
     * whose web address a stock style has is copied already (or was made by hand); `problem` says why one cannot be.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(): array
    {
        return array_map(self::summary(...), $this->plans(null));
    }

    /**
     * Copies the given wholesale styles that are not in the stock yet, each one whole or not at all.
     *
     * @param list<int> $productIds
     * @return list<array{product_id: int, stock_style_id: int, name: string, colors: int, photos: int}>
     */
    public function copy(array $productIds): array
    {
        $copied = [];
        foreach ($this->plans($productIds) as $plan) {
            if ($plan['stock_style_id'] === null && $plan['problem'] === null) {
                $copied[] = $this->copyStyle($plan);
            }
        }

        return $copied;
    }

    /**
     * @param list<int>|null $productIds null: every wholesale style that may be copied
     * @return list<array<string, mixed>>
     */
    private function plans(?array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $where = "product_type = ? AND segment IN ('men', 'boys') AND status <> 'archived'";
        $params = [StockStyle::PRODUCT_TYPE];
        if ($productIds !== null) {
            $where .= ' AND id IN (' . implode(', ', array_fill(0, count($productIds), '?')) . ')';
            array_push($params, ...$productIds);
        }
        $ids = array_map('intval', array_column($this->db->fetchAll("SELECT id FROM products WHERE {$where} ORDER BY sort_order, id", $params), 'id'));
        $colors = $this->productColors->forProducts($ids, false);

        $plans = [];
        foreach ($ids as $id) {
            $product = $this->products->find($id);
            if ($product !== null) {
                $plans[] = $this->plan($product, $colors[$id] ?? []);
            }
        }

        return $plans;
    }

    /**
     * @param array<string, mixed> $product decoded products row
     * @param list<array<string, mixed>> $colors its colors (shown and hidden) with their photos
     * @return array<string, mixed>
     */
    private function plan(array $product, array $colors): array
    {
        $stockStyleId = $this->db->fetchValue('SELECT id FROM stock_styles WHERE slug = ?', [$product['slug']]);
        $name = (string) $product['name'];
        $sizes = $this->setSizes($product);
        [$description, $leftOut] = $this->description((string) $product['description_html'], $product['features']);

        $copies = [];
        $skipped = [];
        foreach ($colors as $color) {
            // Color codes are unique in the stock: a code that another stock style uses stays with that style.
            if ($stockStyleId === null && $this->colors->codeExists($color['code'])) {
                $skipped[] = $color['code'];
                continue;
            }
            $photos = array_filter($color['images'], fn (array $image): bool => $this->images->exists($image['path']));
            $copies[] = [
                'data' => [
                    'code' => $color['code'],
                    'name' => $color['name'],
                    'slug' => $color['slug'],
                    'hex' => $color['hex'],
                    'is_active' => $color['is_active'],
                    'sort_order' => $color['sort_order'],
                ],
                'photos' => array_values(array_map(
                    static fn (array $image): array => ['path' => $image['path'], 'alt' => self::alt($image['alt'], $name, $color['name'])],
                    $photos,
                )),
            ];
        }

        return [
            'product' => $product,
            'stock_style_id' => $stockStyleId === null ? null : (int) $stockStyleId,
            'problem' => match (true) {
                $stockStyleId !== null => null,
                $sizes === [] => sprintf('Its sizes are not waist sizes from %d to %d.', StockStyle::SIZE_MIN, StockStyle::SIZE_MAX),
                $colors === [] => 'It has no colors yet.',
                $copies === [] => 'The codes of its colors are used by other stock styles.',
                default => null,
            },
            'style' => [
                'slug' => $product['slug'],
                'name' => $name,
                'style_no' => $product['style_no'],
                'segment' => $product['segment'] === 'boys' || preg_match('/\bboys?\b/i', $name) === 1 ? 'boys' : 'men',
                'specs' => self::specs($product),
                'description_html' => $description,
                'set_sizes' => $sizes,
                // No price yet: staff enter the price per set before they show the style.
                'set_price_cents' => 0,
                'seo_title' => null,
                'seo_description' => null,
                'status' => 'draft',
                'is_featured' => false,
                'sort_order' => $product['sort_order'],
            ],
            'colors' => $copies,
            'skipped_colors' => $skipped,
            'left_out' => $leftOut,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function summary(array $plan): array
    {
        $product = $plan['product'];
        $photos = array_merge(...array_map(static fn (array $color): array => $color['photos'], $plan['colors']));

        return [
            'product_id' => $product['id'],
            'name' => $product['name'],
            'slug' => $product['slug'],
            'style_no' => $product['style_no'],
            'wholesale_status' => $product['status'],
            'segment' => $plan['style']['segment'],
            'set_sizes' => $plan['style']['set_sizes'],
            'colors' => count($plan['colors']),
            'photos' => count($photos),
            'thumbnail' => $photos[0]['path'] ?? null,
            'stock_style_id' => $plan['stock_style_id'],
            'problem' => $plan['problem'],
            'skipped_colors' => $plan['skipped_colors'],
            'left_out' => $plan['left_out'],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{product_id: int, stock_style_id: int, name: string, colors: int, photos: int}
     */
    private function copyStyle(array $plan): array
    {
        $files = [];
        try {
            // The photos first: the stock gets its own files under uploads/stock.
            $colors = [];
            foreach ($plan['colors'] as $color) {
                $photos = [];
                foreach ($color['photos'] as $photo) {
                    $copy = $this->images->copy($photo['path'], 'stock');
                    $files[] = $copy['path'];
                    $photos[] = ['path' => $copy['path'], 'alt' => $photo['alt'], 'width' => $copy['width'], 'height' => $copy['height']];
                }
                $colors[] = ['data' => $color['data'], 'photos' => $photos];
            }

            $id = $this->db->transaction(function () use ($plan, $colors): int {
                $id = $this->styles->create($plan['style']);
                foreach ($colors as $color) {
                    $this->colors->create($id, $color['data'], $color['photos']);
                }

                return $id;
            });
        } catch (Throwable $e) {
            foreach ($files as $path) {
                $this->images->discard($path);
            }
            throw $e;
        }

        return [
            'product_id' => $plan['product']['id'],
            'stock_style_id' => $id,
            'name' => $plan['style']['name'],
            'colors' => count($colors),
            'photos' => count($files),
        ];
    }

    /**
     * The set: the wholesale sizes that are waist sizes the stock sells, each with the wholesale set's pieces per size.
     *
     * @param array<string, mixed> $product
     * @return list<array{size: string, pcs: int}>
     */
    private function setSizes(array $product): array
    {
        $pcs = min(max(1, (int) $this->catalog->rules($product)['set_pcs_per_size']), StockStyle::MAX_PCS_PER_SIZE);
        $sizes = [];
        foreach ($product['sizes'] as $size) {
            $size = trim((string) $size);
            if (preg_match('/^\d{2}$/', $size) === 1 && (int) $size >= StockStyle::SIZE_MIN && (int) $size <= StockStyle::SIZE_MAX) {
                $sizes[$size] = $pcs;
            }
        }
        ksort($sizes, SORT_NUMERIC);

        return array_map(static fn (int|string $size, int $count): array => ['size' => (string) $size, 'pcs' => $count], array_keys($sizes), $sizes);
    }

    /**
     * The jeans specs that are filled in, as the stock keeps them: {key: value}.
     *
     * @param array<string, mixed> $product
     * @return array<string, string>
     */
    private static function specs(array $product): array
    {
        $specs = [];
        foreach (ProductTypes::values($product) as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '' && ($key !== 'stretch' || in_array($value, ['stretch', 'non-stretch'], true))) {
                $specs[$key] = $value;
            }
        }

        return $specs;
    }

    /**
     * The wholesale description without its paragraphs about the wholesale business, and the features as a list.
     *
     * @param array<mixed> $features
     * @return array{0: string, 1: list<string>} the description, and the text of each paragraph or feature left out
     */
    private function description(string $html, array $features): array
    {
        $kept = '';
        $leftOut = [];
        if (trim($html) !== '') {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="pk-copy-root">' . $html . '</div></body></html>', LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            foreach ($doc->getElementById('pk-copy-root')?->childNodes ?? [] as $node) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
                if ($text !== '' && preg_match(self::WHOLESALE_WORDING, $text) === 1) {
                    $leftOut[] = $text;
                } elseif ($text !== '' || $node instanceof DOMElement) {
                    $kept .= $doc->saveHTML($node);
                }
            }
        }

        $items = [];
        foreach ($features as $feature) {
            $feature = is_string($feature) ? trim($feature) : '';
            if ($feature !== '' && preg_match(self::WHOLESALE_WORDING, $feature) === 1) {
                $leftOut[] = $feature;
            } elseif ($feature !== '') {
                $items[] = '<li>' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }
        if ($items !== []) {
            $kept .= '<ul>' . implode('', $items) . '</ul>';
        }

        return [$this->sanitizer->sanitize($kept), $leftOut];
    }

    /** The photo's text, unless it is empty or about the wholesale business. */
    private static function alt(string $alt, string $style, string $color): string
    {
        $alt = trim($alt);

        return $alt === '' || preg_match(self::WHOLESALE_WORDING, $alt) === 1 ? mb_substr("{$style} in " . mb_strtolower($color), 0, 200) : $alt;
    }
}
