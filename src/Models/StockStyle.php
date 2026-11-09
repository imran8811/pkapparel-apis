<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Stock styles: ready jeans sold from stock on hand, separate from the wholesale shop's made-to-order styles (Product).
 * Each style is sold by set only (one set per style: its sizes with a number of pieces per size), priced per set.
 * Colors and the sets each has in stock are in StockColor. The stock pages show active styles with an active color.
 */
final class StockStyle
{
    public const SEGMENTS = ['men', 'boys'];

    public const STATUSES = ['draft', 'active', 'archived'];

    /** Stock is jeans only: the specs (labels and suggestions) are those of the wholesale shop's jeans pants. */
    public const PRODUCT_TYPE = 'jeans-pant';

    /** Waist sizes in inches: boys' jeans start at 16, men's go up to 48. */
    public const SIZE_MIN = 16;

    public const SIZE_MAX = 48;

    public const MAX_PCS_PER_SIZE = 24;

    private const FILLABLE = [
        'slug', 'name', 'style_no', 'segment', 'specs', 'description_html', 'set_sizes', 'set_price_cents',
        'seo_title', 'seo_description', 'status', 'is_featured', 'sort_order',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Active styles with at least one active color, featured first, for the stock pages.
     *
     * @return list<array<string, mixed>>
     */
    public function active(?string $segment): array
    {
        $where = "s.status = 'active' AND EXISTS (SELECT 1 FROM stock_colors c WHERE c.style_id = s.id AND c.is_active = 1)";
        $params = [];
        if ($segment !== null) {
            $where .= ' AND s.segment = ?';
            $params[] = $segment;
        }
        $rows = $this->db->fetchAll("SELECT s.* FROM stock_styles s WHERE {$where} ORDER BY s.is_featured DESC, s.sort_order, s.id", $params);

        return array_map([self::class, 'decode'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        $row = $this->db->fetch("SELECT * FROM stock_styles WHERE slug = ? AND status = 'active'", [$slug]);

        return $row === null ? null : self::decode($row);
    }

    // ----- Admin -----

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function adminList(?string $status, ?string $search, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status !== null) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(s.name LIKE ? OR s.style_no LIKE ? OR EXISTS (SELECT 1 FROM stock_colors c WHERE c.style_id = s.id AND (c.code LIKE ? OR c.name LIKE ?)))';
            array_push($params, $like, $like, $like, $like);
        }
        $condition = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM stock_styles s WHERE {$condition}", $params);
        $rows = $this->db->fetchAll(
            "SELECT s.*,
                (SELECT COUNT(*) FROM stock_colors c WHERE c.style_id = s.id) AS color_count,
                (SELECT COALESCE(SUM(c.sets_in_stock), 0) FROM stock_colors c WHERE c.style_id = s.id AND c.is_active = 1) AS sets_in_stock,
                (SELECT c.name FROM stock_colors c WHERE c.style_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS color_name,
                (SELECT c.code FROM stock_colors c WHERE c.style_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS color_code,
                (SELECT c.hex FROM stock_colors c WHERE c.style_id = s.id ORDER BY c.sort_order, c.id LIMIT 1) AS color_hex,
                (SELECT i.path FROM stock_images i JOIN stock_colors c ON c.id = i.color_id
                 WHERE i.style_id = s.id ORDER BY c.sort_order, c.id, i.sort_order, i.id LIMIT 1) AS thumbnail
             FROM stock_styles s WHERE {$condition}
             ORDER BY s.segment, s.sort_order, s.id
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map([self::class, 'decode'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null Any style, whatever its status. */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM stock_styles WHERE id = ?', [$id]);

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
        $row['is_featured'] = (bool) $row['is_featured'];
        foreach (['created_at', 'updated_at'] as $column) {
            $row[$column] = gmdate(DATE_ATOM, (int) strtotime($row[$column] . ' UTC'));
        }

        return $row;
    }
}
