<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** The log of every change to a stock color's sets in stock: what changed, why, and who changed it. */
final class StockMovement
{
    /**
     * Why stock changed. Sets received, returned or back from a cancelled order are added; sets sold or damaged are
     * taken out; a correction sets the counted total.
     */
    public const REASONS = [
        'received' => 'Received',
        'returned' => 'Returned',
        'sold' => 'Sold',
        'damaged' => 'Damaged or lost',
        'correction' => 'Count correction',
        'cancelled' => 'Order cancelled',
    ];

    /** The reasons staff choose when changing stock by hand ("cancelled" is recorded when a paid order is cancelled). */
    public const MANUAL_REASONS = ['received', 'returned', 'sold', 'damaged', 'correction'];

    public function __construct(private readonly Database $db)
    {
    }

    public function record(int $colorId, int $change, int $after, string $reason, ?string $note, ?int $orderId, ?int $staffId): int
    {
        return $this->db->insert('stock_movements', [
            'color_id' => $colorId,
            'change_sets' => $change,
            'sets_after' => $after,
            'reason' => $reason,
            'note' => $note,
            'order_id' => $orderId,
            'staff_id' => $staffId,
        ]);
    }

    /**
     * Movements, newest first, for one style or one color (or all).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(?int $styleId, ?int $colorId, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($styleId !== null) {
            $where[] = 'c.style_id = ?';
            $params[] = $styleId;
        }
        if ($colorId !== null) {
            $where[] = 'm.color_id = ?';
            $params[] = $colorId;
        }
        $condition = implode(' AND ', $where);
        $from = "FROM stock_movements m JOIN stock_colors c ON c.id = m.color_id JOIN stock_styles s ON s.id = c.style_id
            LEFT JOIN staff_users u ON u.id = m.staff_id WHERE {$condition}";

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from}", $params);
        $rows = $this->db->fetchAll(
            "SELECT m.*, c.code AS color_code, c.name AS color_name, s.id AS style_id, s.name AS style_name, u.name AS staff_name {$from}
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) max(0, ($page - 1) * $perPage),
            $params,
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'style_id' => (int) $row['style_id'],
                'style_name' => (string) $row['style_name'],
                'color_id' => (int) $row['color_id'],
                'color_code' => (string) $row['color_code'],
                'color_name' => (string) $row['color_name'],
                'change_sets' => (int) $row['change_sets'],
                'sets_after' => (int) $row['sets_after'],
                'reason' => (string) $row['reason'],
                'note' => $row['note'],
                'order_id' => $row['order_id'] === null ? null : (int) $row['order_id'],
                'staff_name' => $row['staff_name'],
                'created_at' => gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC')),
            ], $rows),
            'total' => $total,
        ];
    }
}
