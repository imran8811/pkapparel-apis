<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Text;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductTypes;
use App\Models\StockColor;
use App\Models\StockStyle;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Copies the wholesale shop's jeans for men (and for boys, should it have any) into the stock (owner requests
 * 2026-11-09). Every stock listing is one color with its own details, so each wholesale color becomes a listing of
 * its own, at {style}-{color}: the style's name, style no., specs (with the color's own fabric details), the set (the
 * wholesale waist sizes with the wholesale set's pieces per size), the color and its photos. From then on a copy is
 * separate from the wholesale style: its photos are copied files, and neither shop changes the other.
 *
 * A copy is shown on the stock pages straight away when the wholesale style and color are shown and the color has a
 * photo. It has no price ("Price on request", and it cannot be ordered online until staff enter one) and no sets in
 * stock until staff enter them. Copies made earlier as hidden drafts are shown by copying again. The others are drafts.
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
     * Every color of the wholesale jeans for men and boys (styles shown or drafts, not archived), each with what its
     * stock listing would be. A color is in the stock already when a stock color has its code or a listing has its
     * web address (copied before, or made by hand); `problem` says why one cannot be copied.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(): array
    {
        return array_map(self::summary(...), $this->plans(null));
    }

    /**
     * Copies the given wholesale colors that are not in the stock yet, a listing each and each one whole or not at
     * all, and shows their earlier copies that are still hidden drafts without a price.
     *
     * @param list<int> $colorIds wholesale colors (product_colors.id)
     * @return list<array{color_id: int, stock_style_id: int, name: string, color: string, photos: int, new: bool, shown: bool}>
     */
    public function copy(array $colorIds): array
    {
        $done = [];
        foreach ($this->plans($colorIds) as $plan) {
            if ($plan['stock_style_id'] === null && $plan['problem'] === null) {
                $done[] = $this->copyListing($plan);
            } elseif ($plan['hidden_copy'] && $plan['show']) {
                $this->styles->update($plan['stock_style_id'], ['status' => 'active']);
                $done[] = self::result($plan, $plan['stock_style_id'], count($plan['photos']), false);
            }
        }

        return $done;
    }

    /**
     * @param list<int>|null $colorIds null: every wholesale color that may be copied
     * @return list<array<string, mixed>>
     */
    private function plans(?array $colorIds): array
    {
        if ($colorIds === []) {
            return [];
        }
        $where = "p.product_type = ? AND p.segment IN ('men', 'boys') AND p.status <> 'archived'";
        $params = [StockStyle::PRODUCT_TYPE];
        if ($colorIds !== null) {
            $where .= ' AND c.id IN (' . implode(', ', array_fill(0, count($colorIds), '?')) . ')';
            array_push($params, ...$colorIds);
        }
        $rows = $this->db->fetchAll(
            "SELECT p.id AS product_id, c.id AS color_id FROM products p JOIN product_colors c ON c.product_id = p.id
             WHERE {$where} ORDER BY p.sort_order, p.id, c.sort_order, c.id",
            $params,
        );
        $productIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product_id'], $rows)));
        $colors = [];
        foreach ($this->productColors->forProducts($productIds, false) as $productColors) {
            foreach ($productColors as $color) {
                $colors[$color['id']] = $color;
            }
        }

        $plans = [];
        $shared = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $color = $colors[(int) $row['color_id']] ?? null;
            if (!array_key_exists($productId, $shared)) {
                $product = $this->products->find($productId);
                $shared[$productId] = $product === null ? null : ['product' => $product, 'sizes' => $this->setSizes($product)]
                    + array_combine(['description', 'left_out'], $this->description((string) $product['description_html'], $product['features']));
            }
            if ($color !== null && $shared[$productId] !== null) {
                $plans[] = $this->plan($shared[$productId], $color);
            }
        }

        return $plans;
    }

    /**
     * @param array{product: array<string, mixed>, sizes: list<array{size: string, pcs: int}>, description: string, left_out: list<string>} $shared
     *        the wholesale style (decoded products row) and what all its colors share
     * @param array<string, mixed> $color one of its colors, with its photos
     * @return array<string, mixed>
     */
    private function plan(array $shared, array $color): array
    {
        $product = $shared['product'];
        $name = (string) $product['name'];
        $slug = self::slug((string) $product['slug'], (string) $color['slug']);
        // In the stock already: a stock color with this code (made by hand or copied), or a listing at this address.
        $existing = $this->db->fetch(
            'SELECT s.id, s.status, s.set_price_cents FROM stock_colors c JOIN stock_styles s ON s.id = c.style_id WHERE c.code = ?',
            [$color['code']],
        ) ?? $this->db->fetch('SELECT id, status, set_price_cents FROM stock_styles WHERE slug = ?', [$slug]);
        $stockStyleId = $existing === null ? null : (int) $existing['id'];
        // Only a copy is a draft without a price: staff cannot save a listing without one.
        $hiddenCopy = $existing !== null && $existing['status'] === 'draft' && (int) $existing['set_price_cents'] === 0;

        $photos = array_values(array_map(
            static fn (array $image): array => ['path' => $image['path'], 'alt' => self::alt($image['alt'], $name, $color['name'])],
            array_filter($color['images'], fn (array $image): bool => $this->images->exists($image['path'])),
        ));

        // Shown on the stock pages: a new copy, or an earlier hidden one, when the wholesale style and color are shown
        // and the color has a photo (as the admin requires); any other listing as it is now.
        $shownInWholesale = $product['status'] === 'active' && $color['is_active'];
        $show = match (true) {
            $stockStyleId === null => $shownInWholesale && $photos !== [],
            $hiddenCopy => $shownInWholesale && $this->hasShownColorWithPhoto($stockStyleId),
            default => $existing['status'] === 'active',
        };

        return [
            'product' => $product,
            'color' => $color,
            'stock_style_id' => $stockStyleId,
            'hidden_copy' => $hiddenCopy,
            'show' => $show,
            'draft_reason' => match (true) {
                $show || ($stockStyleId !== null && !$hiddenCopy) => null,
                $product['status'] !== 'active' => 'It is a draft in the wholesale shop.',
                !$color['is_active'] => 'This color is hidden in the wholesale shop.',
                default => 'It has no photos.',
            },
            'problem' => $stockStyleId === null && $shared['sizes'] === []
                ? sprintf('Its sizes are not waist sizes from %d to %d.', StockStyle::SIZE_MIN, StockStyle::SIZE_MAX)
                : null,
            'style' => [
                'slug' => $slug,
                'name' => $name,
                'style_no' => $product['style_no'],
                'segment' => $product['segment'] === 'boys' || preg_match('/\bboys?\b/i', $name) === 1 ? 'boys' : 'men',
                'specs' => self::specs($product, $color['spec_overrides']),
                'description_html' => $shared['description'],
                'set_sizes' => $shared['sizes'],
                // No price yet: "Price on request" until staff enter the price per set.
                'set_price_cents' => 0,
                'seo_title' => null,
                'seo_description' => null,
                'status' => $show ? 'active' : 'draft',
                'is_featured' => false,
                // The colors of a style are added in their order, and listed in it (then by id).
                'sort_order' => $product['sort_order'],
            ],
            // The listing's one color; whether it is shown follows the listing's status.
            'stock_color' => ['code' => $color['code'], 'name' => $color['name'], 'slug' => $color['slug'], 'hex' => $color['hex'], 'is_active' => true, 'sort_order' => 10],
            'photos' => $photos,
            'left_out' => $shared['left_out'],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function summary(array $plan): array
    {
        return [
            'color_id' => $plan['color']['id'],
            'product_id' => $plan['product']['id'],
            'name' => $plan['style']['name'],
            'color' => $plan['color']['name'],
            'code' => $plan['color']['code'],
            'hex' => $plan['color']['hex'],
            'slug' => $plan['style']['slug'],
            'style_no' => $plan['product']['style_no'],
            'wholesale_status' => $plan['product']['status'],
            'segment' => $plan['style']['segment'],
            'set_sizes' => $plan['style']['set_sizes'],
            'photos' => count($plan['photos']),
            'thumbnail' => $plan['photos'][0]['path'] ?? null,
            'stock_style_id' => $plan['stock_style_id'],
            'hidden_copy' => $plan['hidden_copy'],
            'shown' => $plan['show'],
            'draft_reason' => $plan['draft_reason'],
            'problem' => $plan['problem'],
            'left_out' => $plan['left_out'],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{color_id: int, stock_style_id: int, name: string, color: string, photos: int, new: bool, shown: bool}
     */
    private function copyListing(array $plan): array
    {
        $files = [];
        try {
            // The photos first: the stock gets its own files under uploads/stock.
            $photos = [];
            foreach ($plan['photos'] as $photo) {
                $copy = $this->images->copy($photo['path'], 'stock');
                $files[] = $copy['path'];
                $photos[] = ['path' => $copy['path'], 'alt' => $photo['alt'], 'width' => $copy['width'], 'height' => $copy['height']];
            }

            $id = $this->db->transaction(function () use ($plan, $photos): int {
                $id = $this->styles->create($plan['style']);
                $this->colors->create($id, $plan['stock_color'], $photos);

                return $id;
            });
        } catch (Throwable $e) {
            foreach ($files as $path) {
                $this->images->discard($path);
            }
            throw $e;
        }

        return self::result($plan, $id, count($files), true);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{color_id: int, stock_style_id: int, name: string, color: string, photos: int, new: bool, shown: bool}
     */
    private static function result(array $plan, int $stockStyleId, int $photos, bool $new): array
    {
        return [
            'color_id' => $plan['color']['id'],
            'stock_style_id' => $stockStyleId,
            'name' => $plan['style']['name'],
            'color' => $plan['color']['name'],
            'photos' => $photos,
            'new' => $new,
            'shown' => $plan['show'],
        ];
    }

    private function hasShownColorWithPhoto(int $styleId): bool
    {
        foreach ($this->colors->forStyles([$styleId], true, 1)[$styleId] ?? [] as $color) {
            if ($color['images'] !== []) {
                return true;
            }
        }

        return false;
    }

    /** The listing's web address, the style's and the color's: mens-slim-stretch-jeans-sky-blue. */
    private static function slug(string $style, string $color): string
    {
        return Text::slug(substr($style, 0, max(1, 189 - strlen($color))) . '-' . $color);
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
     * The jeans specs that are filled in, as the stock keeps them ({key: value}): the style's, with the color's own
     * fabric details (e.g. the olive color in cotton twill).
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $overrides the color's spec_overrides
     * @return array<string, string>
     */
    private static function specs(array $product, array $overrides): array
    {
        $values = ProductTypes::values($product);
        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $values) && is_string($value) && trim($value) !== '') {
                $values[$key] = $value;
            }
        }
        $specs = [];
        foreach ($values as $key => $value) {
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
