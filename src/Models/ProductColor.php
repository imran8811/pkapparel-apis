<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The colors of a style, each with its own code (the old article number for imported
 * colors), photos, and optionally its own price and fabric details.
 */
final class ProductColor
{
    /** Fabric details a color may change, e.g. the olive cargo is stretch while the others are not. */
    public const SPEC_KEYS = ['fabric', 'composition', 'fabric_weight', 'stretch', 'wash', 'closure'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Colors of the given styles with their photos, in display order.
     *
     * @param list<int> $productIds
     * @return array<int, list<array<string, mixed>>> product id => colors
     */
    public function forProducts(array $productIds, bool $activeOnly, int $imagesPerColor = 0): array
    {
        if ($productIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($productIds), '?'));
        $colors = $this->db->fetchAll(
            "SELECT * FROM product_colors WHERE product_id IN ({$in})" . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id',
            $productIds,
        );
        $images = $this->db->fetchAll(
            "SELECT id, color_id, path, alt, width, height FROM product_images WHERE product_id IN ({$in}) AND color_id IS NOT NULL ORDER BY sort_order, id",
            $productIds,
        );

        $byColor = [];
        foreach ($images as $image) {
            $colorId = (int) $image['color_id'];
            if ($imagesPerColor > 0 && count($byColor[$colorId] ?? []) >= $imagesPerColor) {
                continue;
            }
            $byColor[$colorId][] = [
                'path' => (string) $image['path'],
                'alt' => (string) $image['alt'],
                'width' => (int) $image['width'],
                'height' => (int) $image['height'],
            ];
        }

        $result = [];
        foreach ($colors as $color) {
            $result[(int) $color['product_id']][] = self::present($color) + ['images' => $byColor[(int) $color['id']] ?? []];
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM product_colors WHERE id = ?', [$id]);

        return $row === null ? null : self::present($row);
    }

    /** @return array{product_id: int, color_slug: string}|null The color an old article number (or color code) belongs to. */
    public function findByArticle(string $article): ?array
    {
        $row = $this->db->fetch(
            'SELECT product_id, slug FROM product_colors WHERE legacy_article_no = ? OR code = ? ORDER BY legacy_article_no = ? DESC LIMIT 1',
            [$article, $article, $article],
        );

        return $row === null ? null : ['product_id' => (int) $row['product_id'], 'color_slug' => (string) $row['slug']];
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM product_colors WHERE code = ? AND id <> ?', [$code, $exceptId ?? 0]) !== null;
    }

    public function slugExists(int $productId, string $slug, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue(
            'SELECT id FROM product_colors WHERE product_id = ? AND slug = ? AND id <> ?',
            [$productId, $slug, $exceptId ?? 0],
        ) !== null;
    }

    public function nextSortOrder(int $productId): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM product_colors WHERE product_id = ?', [$productId]);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{path: string, alt: string, width: int, height: int}> $images
     */
    public function create(int $productId, array $data, array $images): int
    {
        return $this->db->transaction(function () use ($productId, $data, $images): int {
            $id = $this->db->insert('product_colors', ['product_id' => $productId] + self::encode($data));
            $this->replaceImages($productId, $id, $images);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{path: string, alt: string, width: int, height: int}>|null $images null keeps the current photos
     */
    public function update(int $id, int $productId, array $data, ?array $images): void
    {
        $this->db->transaction(function () use ($id, $productId, $data, $images): void {
            $fields = self::encode($data);
            if ($fields !== []) {
                $this->db->update('product_colors', $fields, ['id' => $id]);
            }
            if ($images !== null) {
                $this->replaceImages($productId, $id, $images);
            }
        });
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM product_colors WHERE id = ?', [$id]);
    }

    /** @param list<array{path: string, alt: string, width: int, height: int}> $images */
    private function replaceImages(int $productId, int $colorId, array $images): void
    {
        $this->db->execute('DELETE FROM product_images WHERE color_id = ?', [$colorId]);
        foreach (array_values($images) as $index => $image) {
            $this->db->insert('product_images', [
                'product_id' => $productId,
                'color_id' => $colorId,
                'path' => $image['path'],
                'alt' => $image['alt'],
                'width' => $image['width'],
                'height' => $image['height'],
                'sort_order' => ($index + 1) * 10,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function encode(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['code', 'name', 'slug', 'hex', 'price_cents', 'legacy_article_no', 'is_active', 'sort_order']));
        if (array_key_exists('spec_overrides', $data)) {
            $specs = array_filter(
                array_intersect_key((array) $data['spec_overrides'], array_flip(self::SPEC_KEYS)),
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );
            $fields['spec_overrides'] = json_encode((object) $specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('is_active', $fields)) {
            $fields['is_active'] = $fields['is_active'] ? 1 : 0;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'hex' => $row['hex'],
            'price_cents' => $row['price_cents'] === null ? null : (int) $row['price_cents'],
            'spec_overrides' => json_decode((string) $row['spec_overrides'], true) ?: [],
            'legacy_article_no' => $row['legacy_article_no'],
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
