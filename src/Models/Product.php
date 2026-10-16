<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Styles: one product page per cut, with colors in ProductColor.
 * The shop only shows active styles that have at least one active color.
 */
final class Product
{
    public const SEGMENTS = ['men', 'women', 'boys', 'girls'];

    public const TYPES = ['jeans-pant', 'denim-jacket', 'jeans-short', 'cargo-trouser', 'chino-pant', 'denim-skirt'];

    public const STATUSES = ['draft', 'active', 'archived'];

    public const SORTS = ['featured', 'newest', 'price-asc', 'price-desc'];

    /** Columns the admin may write. */
    public const FILLABLE = [
        'slug', 'name', 'style_no', 'segment', 'product_type', 'fit', 'fabric', 'composition', 'fabric_weight', 'stretch',
        'wash', 'closure', 'description_html', 'features', 'sizes', 'size_chart_id', 'price_cents', 'moq_per_color',
        'set_pcs_per_size', 'lead_time_days', 'sample_price_cents', 'piece_weight_g', 'pcs_per_carton', 'carton_length_mm',
        'carton_width_mm', 'carton_height_mm', 'carton_weight_g', 'seo_title', 'seo_description', 'status', 'is_featured',
        'sort_order',
    ];

    private const JSON_COLUMNS = ['features', 'sizes'];

    /** Lowest and highest price over a style's active colors (a color price overrides the style price). */
    private const PRICE_RANGE = '(SELECT c.product_id, MIN(COALESCE(c.price_cents, pp.price_cents)) AS price_min,
            MAX(COALESCE(c.price_cents, pp.price_cents)) AS price_max
        FROM product_colors c JOIN products pp ON pp.id = c.product_id
        WHERE c.is_active = 1 GROUP BY c.product_id)';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Active styles for the shop.
     *
     * @param array{segment?: ?string, type?: ?string, fit?: ?string, stretch?: ?string, q?: ?string, featured?: ?bool, exclude?: ?int} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listing(array $filters, string $sort, int $page, int $perPage): array
    {
        [$where, $params] = self::filters($filters);
        $order = match ($sort) {
            'newest' => 'p.created_at DESC, p.id DESC',
            'price-asc' => 'r.price_min ASC, p.sort_order ASC, p.id ASC',
            'price-desc' => 'r.price_min DESC, p.sort_order ASC, p.id ASC',
            default => 'p.is_featured DESC, p.sort_order ASC, p.id ASC',
        };
        $from = 'FROM products p JOIN ' . self::PRICE_RANGE . " r ON r.product_id = p.id WHERE {$where}";

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from}", $params);
        $rows = $this->db->fetchAll(
            "SELECT p.*, r.price_min, r.price_max {$from} ORDER BY {$order} LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map([self::class, 'decode'], $rows), 'total' => $total];
    }

    /** @return list<array{segment: string, product_type: string, count: int}> Active styles per category (for menus and filters). */
    public function counts(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.segment, p.product_type, COUNT(*) AS styles FROM products p JOIN ' . self::PRICE_RANGE . " r ON r.product_id = p.id
             WHERE p.status = 'active' GROUP BY p.segment, p.product_type ORDER BY p.segment, p.product_type"
        );

        return array_map(static fn (array $row): array => [
            'segment' => (string) $row['segment'],
            'product_type' => (string) $row['product_type'],
            'count' => (int) $row['styles'],
        ], $rows);
    }

    /** @return array<string, mixed>|null An active style with its price range. */
    public function findActiveBySlug(string $slug): ?array
    {
        $row = $this->db->fetch(
            'SELECT p.*, r.price_min, r.price_max FROM products p JOIN ' . self::PRICE_RANGE . " r ON r.product_id = p.id
             WHERE p.slug = ? AND p.status = 'active'",
            [$slug],
        );

        return $row === null ? null : self::decode($row);
    }

    // ----- Admin -----

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function adminList(?string $status, ?string $search, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status !== null) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR p.style_no LIKE ? OR EXISTS (SELECT 1 FROM product_colors c WHERE c.product_id = p.id AND (c.code LIKE ? OR c.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like);
        }
        $condition = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM products p WHERE {$condition}", $params);
        $rows = $this->db->fetchAll(
            "SELECT p.*,
                (SELECT COUNT(*) FROM product_colors c WHERE c.product_id = p.id) AS color_count,
                (SELECT i.path FROM product_images i JOIN product_colors c ON c.id = i.color_id
                 WHERE i.product_id = p.id ORDER BY c.sort_order, c.id, i.sort_order, i.id LIMIT 1) AS thumbnail
             FROM products p WHERE {$condition}
             ORDER BY p.segment, p.product_type, p.sort_order, p.id
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map([self::class, 'decode'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null Any style, whatever its status. */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM products WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM products WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('products', self::encode($data));
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $fields = self::encode($data);
        if ($fields !== []) {
            $this->db->update('products', $fields, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM products WHERE id = ?', [$id]);
    }

    /** Marks the style as changed even when only its price tiers changed (the admin editor reloads on a new updated_at). */
    public function touch(int $id): void
    {
        $this->db->execute('UPDATE products SET updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
    }

    /** @return list<array{min_qty: int, price_cents: int}> */
    public function tiers(int $productId): array
    {
        $rows = $this->db->fetchAll('SELECT min_qty, price_cents FROM product_price_tiers WHERE product_id = ? ORDER BY min_qty', [$productId]);

        return array_map(static fn (array $row): array => ['min_qty' => (int) $row['min_qty'], 'price_cents' => (int) $row['price_cents']], $rows);
    }

    /** @param list<array{min_qty: int, price_cents: int}> $tiers */
    public function replaceTiers(int $productId, array $tiers): void
    {
        $this->db->execute('DELETE FROM product_price_tiers WHERE product_id = ?', [$productId]);
        foreach ($tiers as $tier) {
            $this->db->insert('product_price_tiers', ['product_id' => $productId, 'min_qty' => $tier['min_qty'], 'price_cents' => $tier['price_cents']]);
        }
    }

    /** @return array<string, int> Styles per status. */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS total FROM products GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array{segment?: ?string, type?: ?string, fit?: ?string, stretch?: ?string, q?: ?string, featured?: ?bool, exclude?: ?int} $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private static function filters(array $filters): array
    {
        $where = ["p.status = 'active'"];
        $params = [];
        foreach (['segment' => 'p.segment', 'type' => 'p.product_type', 'fit' => 'p.fit', 'stretch' => 'p.stretch'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = ?";
                $params[] = $filters[$key];
            }
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.is_featured = 1';
        }
        if (!empty($filters['exclude'])) {
            $where[] = 'p.id <> ?';
            $params[] = $filters['exclude'];
        }
        if (!empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR p.style_no = ? OR EXISTS (SELECT 1 FROM product_colors qc
                WHERE qc.product_id = p.id AND qc.is_active = 1 AND (qc.code = ? OR qc.name LIKE ?)))';
            array_push($params, $like, $search, $search, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function encode(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::FILLABLE));
        foreach (self::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $fields)) {
                $fields[$column] = json_encode(array_values($fields[$column] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
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
        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = json_decode((string) $row[$column], true) ?: [];
        }
        foreach (['id', 'size_chart_id', 'price_cents', 'moq_per_color', 'set_pcs_per_size', 'lead_time_days', 'sample_price_cents',
            'piece_weight_g', 'pcs_per_carton', 'carton_length_mm', 'carton_width_mm', 'carton_height_mm', 'carton_weight_g',
            'sort_order', 'price_min', 'price_max', 'color_count'] as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }
        $row['is_featured'] = (bool) $row['is_featured'];
        foreach (['created_at', 'updated_at'] as $column) {
            $row[$column] = gmdate(DATE_ATOM, (int) strtotime($row[$column] . ' UTC'));
        }

        return $row;
    }
}
