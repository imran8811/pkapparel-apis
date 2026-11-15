<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The colors of a stock style, each with its own code, photos and the number of sets in stock.
 * sets_in_stock is only changed through App\Services\StockLevels, which logs every change in stock_movements.
 */
final class StockColor
{
    /** Color codes: letters, digits and dashes, unique among stock colors (shown to buyers). */
    public const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]*$/';

    public const MAX_PHOTOS = 12;

    public const CODE_RULES = ['string', 'max:40', 'regex:' . self::CODE_PATTERN];

    /** What every color needs (the color form and a new listing's color). */
    public const RULES = [
        'code' => ['required', ...self::CODE_RULES],
        'name' => 'required|string|max:80',
        'hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Colors of the given styles with their photos, in display order.
     *
     * @param list<int> $styleIds
     * @return array<int, list<array<string, mixed>>> style id => colors
     */
    public function forStyles(array $styleIds, bool $activeOnly, int $imagesPerColor = 0): array
    {
        if ($styleIds === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($styleIds), '?'));
        $colors = $this->db->fetchAll(
            "SELECT * FROM stock_colors WHERE style_id IN ({$in})" . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id',
            $styleIds,
        );
        $images = $this->db->fetchAll(
            "SELECT color_id, path, alt, width, height FROM stock_images WHERE style_id IN ({$in}) ORDER BY sort_order, id",
            $styleIds,
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
            $result[(int) $color['style_id']][] = self::present($color) + ['images' => $byColor[(int) $color['id']] ?? []];
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM stock_colors WHERE id = ?', [$id]);

        return $row === null ? null : self::present($row);
    }

    /** A stock style has one color at most: each jeans color is a listing of its own (owner decision 2026-11-09). */
    public function styleHasColor(int $styleId): bool
    {
        return $this->db->fetchValue('SELECT id FROM stock_colors WHERE style_id = ? LIMIT 1', [$styleId]) !== null;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM stock_colors WHERE code = ? AND id <> ?', [$code, $exceptId ?? 0]) !== null;
    }

    /** The code for a color added without one: "ST-" and the number after the highest such code, e.g. "ST-1052". */
    public function nextCode(): string
    {
        $highest = (int) $this->db->fetchValue("SELECT MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) FROM stock_colors WHERE code REGEXP '^ST-[0-9]+$'");

        return 'ST-' . max($highest + 1, 1001);
    }

    public function slugExists(int $styleId, string $slug, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue(
            'SELECT id FROM stock_colors WHERE style_id = ? AND slug = ? AND id <> ?',
            [$styleId, $slug, $exceptId ?? 0],
        ) !== null;
    }

    public function nextSortOrder(int $styleId): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM stock_colors WHERE style_id = ?', [$styleId]);
    }

    /**
     * A new color with no stock yet (stock is added with a movement).
     *
     * @param array<string, mixed> $data
     * @param list<array{path: string, alt: string, width: int, height: int}> $images
     */
    public function create(int $styleId, array $data, array $images): int
    {
        return $this->db->transaction(function () use ($styleId, $data, $images): int {
            $id = $this->db->insert('stock_colors', ['style_id' => $styleId] + self::encode($data));
            $this->replaceImages($styleId, $id, $images);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{path: string, alt: string, width: int, height: int}>|null $images null keeps the current photos
     */
    public function update(int $id, int $styleId, array $data, ?array $images): void
    {
        $this->db->transaction(function () use ($id, $styleId, $data, $images): void {
            $fields = self::encode($data);
            if ($fields !== []) {
                $this->db->update('stock_colors', $fields, ['id' => $id]);
            }
            if ($images !== null) {
                $this->replaceImages($styleId, $id, $images);
            }
        });
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM stock_colors WHERE id = ?', [$id]);
    }

    /** The color's sets in stock, with its row locked until the transaction ends; null when the color does not exist. */
    public function lockSets(int $id): ?int
    {
        $sets = $this->db->fetchValue('SELECT sets_in_stock FROM stock_colors WHERE id = ? FOR UPDATE', [$id]);

        return $sets === null ? null : (int) $sets;
    }

    public function setSets(int $id, int $sets): void
    {
        $this->db->execute('UPDATE stock_colors SET sets_in_stock = ? WHERE id = ?', [$sets, $id]);
    }

    /** @param list<array{path: string, alt: string, width: int, height: int}> $images */
    private function replaceImages(int $styleId, int $colorId, array $images): void
    {
        $this->db->execute('DELETE FROM stock_images WHERE color_id = ?', [$colorId]);
        foreach (array_values($images) as $index => $image) {
            $this->db->insert('stock_images', [
                'style_id' => $styleId,
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
        $fields = array_intersect_key($data, array_flip(['code', 'name', 'slug', 'hex', 'is_active', 'sort_order']));
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
            'style_id' => (int) $row['style_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'hex' => $row['hex'],
            'sets_in_stock' => (int) $row['sets_in_stock'],
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
