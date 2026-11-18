<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Text;
use App\Core\ValidationException;

/**
 * Stock styles: ready clothes sold from stock on hand, separate from the wholesale shop's made-to-order styles (Product).
 * Each style is sold by set only (one set per style: its sizes with a number of pieces per size). Its price is per
 * piece (piece_price_cents); a set costs its pieces times that (set_price_cents, kept by encode()).
 * Colors and the sets each has in stock are in StockColor. The stock pages show active styles with an active color.
 *
 * A style is in a department (`segment`: the slug of a StockDepartment, e.g. men) and a category (`category`: the slug
 * of a StockCategory, e.g. jeans). Its specs are those of its category's product type, then the stock's own.
 */
final class StockStyle
{
    public const STATUSES = ['draft', 'active', 'archived'];

    /** Details only stock listings have, shown after their category's specs (owner request 2026-11-15: the label). */
    public const OWN_SPECS = [
        ['key' => 'label', 'label' => 'Label', 'max' => 80, 'suggestions' => []],
    ];

    /** A style with its department's and category's names, and its category's product type (whose specs it has). */
    private const SELECT = 'SELECT s.*, d.name AS department_name, k.name AS category_name, k.product_type
        FROM stock_styles s
        LEFT JOIN stock_departments d ON d.slug = s.segment
        LEFT JOIN stock_categories k ON k.slug = s.category';

    /** Waist sizes in inches: boys' jeans start at 16, men's go up to 48. */
    public const SIZE_MIN = 16;

    public const SIZE_MAX = 48;

    public const MAX_PCS_PER_SIZE = 24;

    /**
     * Lists show the newest listings first (owner request 2026-11-18: every list by date added): by the date added,
     * then by id (for listings added in the same second).
     */
    public const NEWEST = 's.created_at DESC, s.id DESC';

    private const FILLABLE = [
        'slug', 'name', 'style_no', 'segment', 'category', 'specs', 'description_html', 'set_sizes', 'piece_price_cents',
        'set_price_cents', 'seo_title', 'seo_description', 'status', 'is_featured', 'sort_order',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The details a stock listing has, in display order: the specs of its category's product type (as in the
     * wholesale shop: "Inseam" for the length of pants…), then the stock's own.
     *
     * @return list<array{key: string, label: string, max: int, suggestions: list<string>}>
     */
    public static function specFields(string $productType): array
    {
        return [...ProductTypes::specs($productType), ...self::OWN_SPECS];
    }

    /**
     * Active styles with at least one active color, the newest first, for the stock pages.
     *
     * @return list<array<string, mixed>>
     */
    public function active(?string $segment, ?string $category = null): array
    {
        $where = "s.status = 'active' AND EXISTS (SELECT 1 FROM stock_colors c WHERE c.style_id = s.id AND c.is_active = 1)";
        $params = [];
        if ($segment !== null) {
            $where .= ' AND s.segment = ?';
            $params[] = $segment;
        }
        if ($category !== null) {
            $where .= ' AND s.category = ?';
            $params[] = $category;
        }
        $rows = $this->db->fetchAll(self::SELECT . " WHERE {$where} ORDER BY " . self::NEWEST, $params);

        return array_map([self::class, 'decode'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        $row = $this->db->fetch(self::SELECT . " WHERE s.slug = ? AND s.status = 'active'", [$slug]);

        return $row === null ? null : self::decode($row);
    }

    // ----- Admin -----

    /**
     * Listings, the newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, ?string $search, int $page, int $perPage, ?string $segment = null, ?string $category = null): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status !== null) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        if ($segment !== null) {
            $where[] = 's.segment = ?';
            $params[] = $segment;
        }
        if ($category !== null) {
            $where[] = 's.category = ?';
            $params[] = $category;
        }
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(s.name LIKE ? OR s.style_no LIKE ? OR EXISTS (SELECT 1 FROM stock_colors c WHERE c.style_id = s.id AND (c.code LIKE ? OR c.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like);
        }
        $condition = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM stock_styles s WHERE {$condition}", $params);
        $rows = $this->db->fetchAll(
            "SELECT s.*, d.name AS department_name, k.name AS category_name, k.product_type,
                (SELECT COUNT(*) FROM stock_colors c WHERE c.style_id = s.id) AS color_count,
                (SELECT COALESCE(SUM(c.sets_in_stock), 0) FROM stock_colors c WHERE c.style_id = s.id AND c.is_active = 1) AS sets_in_stock,
                (SELECT c.name FROM stock_colors c WHERE c.style_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS color_name,
                (SELECT c.code FROM stock_colors c WHERE c.style_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS color_code,
                (SELECT c.hex FROM stock_colors c WHERE c.style_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS color_hex,
                (SELECT i.path FROM stock_images i JOIN stock_colors c ON c.id = i.color_id
                 WHERE i.style_id = s.id ORDER BY c.sort_order, c.id, i.sort_order, i.id LIMIT 1) AS thumbnail
             FROM stock_styles s
             LEFT JOIN stock_departments d ON d.slug = s.segment
             LEFT JOIN stock_categories k ON k.slug = s.category
             WHERE {$condition}
             ORDER BY " . self::NEWEST . "
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map([self::class, 'decode'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null Any style, whatever its status. */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch(self::SELECT . ' WHERE s.id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM stock_styles WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('stock_styles', self::encode($data));
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $fields = self::encode($data);
        if ($fields !== []) {
            $this->db->update('stock_styles', $fields, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM stock_styles WHERE id = ?', [$id]);
    }

    /** Marks the style as changed when only its colors or stock changed (the admin editor reloads on a new updated_at). */
    public function touch(int $id): void
    {
        $this->db->execute('UPDATE stock_styles SET updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
    }

    /** @return array<string, int> Styles per status. */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS total FROM stock_styles GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The specs that are filled in (those of the category's product type, and the label), as {key: value}. Others are
     * left out.
     *
     * @return array<string, string>
     */
    public static function cleanSpecs(mixed $input, string $productType): array
    {
        if (!is_array($input)) {
            throw new ValidationException(['specs' => 'Must be a list of details.']);
        }
        $specs = [];
        foreach (self::specFields($productType) as $field) {
            $value = $input[$field['key']] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new ValidationException(["specs.{$field['key']}" => 'Must be text.']);
            }
            $value = $value === null ? '' : trim($value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $field['max']) {
                throw new ValidationException(["specs.{$field['key']}" => "Use at most {$field['max']} characters."]);
            }
            if ($field['key'] === 'stretch' && !in_array($value, ['stretch', 'non-stretch'], true)) {
                throw new ValidationException(['specs.stretch' => 'Choose stretch or non-stretch.']);
            }
            $specs[$field['key']] = $value;
        }

        return $specs;
    }

    /**
     * The set: waist sizes (inches) with the pieces of each, smallest size first.
     *
     * @return list<array{size: string, pcs: int}>
     */
    public static function cleanSetSizes(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw new ValidationException(['set_sizes' => 'Add the sizes of the set.']);
        }
        $sizes = [];
        foreach ($items as $item) {
            $size = is_array($item) ? trim((string) ($item['size'] ?? '')) : '';
            $pcs = is_array($item) ? ($item['pcs'] ?? null) : null;
            if (!preg_match('/^\d{2}$/', $size) || (int) $size < self::SIZE_MIN || (int) $size > self::SIZE_MAX) {
                throw new ValidationException(['set_sizes' => sprintf('Sizes are waist sizes from %d to %d.', self::SIZE_MIN, self::SIZE_MAX)]);
            }
            if (!is_int($pcs) || $pcs < 1 || $pcs > self::MAX_PCS_PER_SIZE) {
                throw new ValidationException(['set_sizes' => sprintf('Each size needs 1 to %d pieces per set.', self::MAX_PCS_PER_SIZE)]);
            }
            if (isset($sizes[$size])) {
                throw new ValidationException(['set_sizes' => 'Each size can only be listed once.']);
            }
            $sizes[$size] = $pcs;
        }
        ksort($sizes, SORT_NUMERIC);

        return array_map(static fn (int|string $size, int $pcs): array => ['size' => (string) $size, 'pcs' => $pcs], array_keys($sizes), $sizes);
    }

    /** A listing's web address from its name and color, as for copies of the wholesale shop: mens-slim-jeans-sky-blue. */
    public static function slugFor(string $name, string $color): string
    {
        return Text::slug(substr($name, 0, max(1, 189 - strlen($color))) . '-' . $color);
    }

    /**
     * Pieces in one set.
     *
     * @param list<array{size: string, pcs: int}> $sizes
     */
    public static function setPieces(array $sizes): int
    {
        return array_sum(array_column($sizes, 'pcs'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function encode(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::FILLABLE));
        // Prices are per piece: a set costs its pieces times the piece price (kept for the cart, orders and reports).
        if (array_key_exists('piece_price_cents', $fields) && is_array($data['set_sizes'] ?? null)) {
            $fields['set_price_cents'] = (int) $fields['piece_price_cents'] * self::setPieces(array_values($data['set_sizes']));
        }
        if (array_key_exists('specs', $fields)) {
            $fields['specs'] = json_encode((object) ($fields['specs'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('set_sizes', $fields)) {
            $fields['set_sizes'] = json_encode(array_values($fields['set_sizes'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('is_featured', $fields)) {
            $fields['is_featured'] = $fields['is_featured'] ? 1 : 0;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $specs = json_decode((string) $row['specs'], true);
        $row['specs'] = is_array($specs) ? $specs : [];
        $sizes = json_decode((string) $row['set_sizes'], true);
        $row['set_sizes'] = array_map(
            static fn (array $item): array => ['size' => (string) $item['size'], 'pcs' => (int) $item['pcs']],
            is_array($sizes) ? array_values(array_filter($sizes, 'is_array')) : [],
        );
        $row['set_pieces'] = self::setPieces($row['set_sizes']);
        foreach (['id', 'set_price_cents', 'sort_order', 'color_count', 'sets_in_stock'] as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }
        $row['piece_price_cents'] = (int) ($row['piece_price_cents'] ?? 0);
        // Saved before prices were per piece: its set price spread over the set's pieces.
        if ($row['piece_price_cents'] === 0 && $row['set_price_cents'] > 0 && $row['set_pieces'] > 0) {
            $row['piece_price_cents'] = (int) round($row['set_price_cents'] / $row['set_pieces']);
        }
        $row['is_featured'] = (bool) $row['is_featured'];
        // A listing's department and category always exist (foreign keys); their slugs stand in for the names otherwise.
        $row['department_name'] = (string) ($row['department_name'] ?? $row['segment']);
        $row['category_name'] = (string) ($row['category_name'] ?? $row['category']);
        $row['product_type'] = (string) ($row['product_type'] ?? 'jeans-pant');
        foreach (['created_at', 'updated_at'] as $column) {
            $row[$column] = gmdate(DATE_ATOM, (int) strtotime($row[$column] . ' UTC'));
        }

        return $row;
    }
}
