<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Orders with frozen copies of the lines, prices, buyer and address.
 * Status changes always go through changeStatus(), which also writes the timeline.
 */
final class Order
{
    public const STATUSES = [
        'awaiting_deposit' => 'Awaiting deposit',
        'payment_review' => 'Payment under review',
        'confirmed' => 'Confirmed',
        'in_production' => 'In production',
        'ready_to_ship' => 'Ready to ship, balance due',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'on_hold' => 'On hold',
        'cancelled' => 'Cancelled',
    ];

    public const MODES = ['air' => 'Air freight', 'sea' => 'Sea freight', 'forwarder' => 'Your own forwarder'];

    /** Statuses in which an order still counts as active for the buyer. */
    public const OPEN_STATUSES = ['awaiting_deposit', 'payment_review', 'confirmed', 'in_production', 'ready_to_ship', 'shipped', 'on_hold'];

    private const JSON_COLUMNS = ['packing', 'customer', 'address', 'forwarder'];

    public function __construct(private readonly Database $db)
    {
    }

    public function orderNoExists(string $orderNo): bool
    {
        return $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$orderNo]) !== null;
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $addons
     */
    public function create(array $order, array $lines, array $addons): int
    {
        return $this->db->transaction(function () use ($order, $lines, $addons): int {
            foreach (self::JSON_COLUMNS as $column) {
                if (array_key_exists($column, $order) && $order[$column] !== null) {
                    $order[$column] = json_encode($order[$column], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
            $id = $this->db->insert('orders', $order);
            foreach ($lines as $index => $line) {
                $line['size_qty'] = json_encode((object) $line['size_qty'], JSON_THROW_ON_ERROR);
                $this->db->insert('order_lines', ['order_id' => $id, 'sort_order' => $index * 10] + $line);
            }
            foreach ($addons as $addon) {
                $this->db->insert('order_addons', ['order_id' => $id] + $addon);
            }

            return $id;
        });
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM orders WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null */
    public function findByNo(string $orderNo): ?array
    {
        $row = $this->db->fetch('SELECT * FROM orders WHERE order_no = ?', [$orderNo]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null Only when the order belongs to this buyer. */
    public function findForCustomer(int $customerId, string $orderNo): ?array
    {
        $row = $this->db->fetch('SELECT * FROM orders WHERE order_no = ? AND customer_id = ?', [$orderNo, $customerId]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * A buyer's orders, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forCustomer(int $customerId, int $page, int $perPage): array
    {
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders WHERE customer_id = ?', [$customerId]);
        $rows = $this->db->fetchAll(
            'SELECT * FROM orders WHERE customer_id = ? ORDER BY placed_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            [$customerId],
        );

        return ['items' => $this->withLineSummaries(array_map(static fn (array $row): array => self::decode($row), $rows)), 'total' => $total];
    }

    /**
     * Staff list, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, ?string $search, int $page, int $perPage, ?string $lateOn = null): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status !== null) {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        if ($lateOn !== null) {
            // Due before this (Pakistan) date and not ready yet.
            $where[] = "o.status IN ('confirmed', 'in_production') AND o.production_due_on < ?";
            $params[] = $lateOn;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(o.order_no LIKE ? OR c.company_name LIKE ? OR c.email LIKE ? OR o.buyer_reference LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id WHERE {$sqlWhere}", $params);
        $rows = $this->db->fetchAll(
            "SELECT o.* FROM orders o JOIN customers c ON c.id = o.customer_id WHERE {$sqlWhere}
             ORDER BY o.placed_at DESC, o.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params,
        );

        return ['items' => $this->withLineSummaries(array_map(static fn (array $row): array => self::decode($row), $rows)), 'total' => $total];
    }

    /**
     * Sets columns of an order (staff workflow: final freight, due date, balance invoice…).
     *
     * @param array<string, mixed> $fields
     */
    public function updateFields(int $id, array $fields): void
    {
        if ($fields !== []) {
            $this->db->update('orders', $fields, ['id' => $id]);
        }
    }

    /** Prices an "on request" add-on (or changes a price). */
    public function priceAddon(int $orderId, int $addonId, int $unitCents): void
    {
        $this->db->execute(
            'UPDATE order_addons SET unit_price_cents = ?, line_cents = ? * qty WHERE id = ? AND order_id = ?',
            [$unitCents, $unitCents, $addonId, $orderId],
        );
        $this->db->execute(
            'UPDATE orders SET addons_cents = (SELECT COALESCE(SUM(line_cents), 0) FROM order_addons WHERE order_id = ?) WHERE id = ?',
            [$orderId, $orderId],
        );
    }

    public function addPaid(int $orderId, int $cents): void
    {
        $this->db->execute('UPDATE orders SET paid_cents = paid_cents + ? WHERE id = ?', [$cents, $orderId]);
    }

    /** Orders whose production is late on this (Pakistan) date. */
    public function countLate(string $today): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM orders WHERE status IN ('confirmed', 'in_production') AND production_due_on < ?",
            [$today],
        );
    }

    /**
     * Orders in production (or waiting for it) that are late or due by $until, soonest first (daily summary).
     *
     * @return list<array<string, mixed>>
     */
    public function dueBy(string $until): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM orders WHERE status IN ('confirmed', 'in_production') AND production_due_on <= ? ORDER BY production_due_on, id",
            [$until],
        );

        return array_map(static fn (array $row): array => self::decode($row), $rows);
    }

    /**
     * Orders still waiting for the deposit with no payment reported (reminders and automatic cancelling).
     *
     * @return list<array<string, mixed>>
     */
    public function unpaid(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT o.* FROM orders o
             WHERE o.status = 'awaiting_deposit'
               AND NOT EXISTS (SELECT 1 FROM order_payments p WHERE p.order_id = o.id AND p.status IN ('submitted', 'confirmed'))
             ORDER BY o.placed_at, o.id",
        );

        return array_map(static fn (array $row): array => self::decode($row), $rows);
    }

    /** @return array<string, int> status => number of orders */
    public function countByStatus(): array
    {
        $rows = $this->db->fetchAll('SELECT status, COUNT(*) AS n FROM orders GROUP BY status');

        return array_map('intval', array_column($rows, 'n', 'status'));
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $orderId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM order_lines WHERE order_id = ? ORDER BY sort_order, id', [$orderId]);

        return array_map(static function (array $row): array {
            $sizes = json_decode((string) $row['size_qty'], true);

            return [
                'product_id' => $row['product_id'] === null ? null : (int) $row['product_id'],
                'color_id' => $row['color_id'] === null ? null : (int) $row['color_id'],
                'style_no' => $row['style_no'],
                'style_name' => (string) $row['style_name'],
                'style_slug' => (string) $row['style_slug'],
                'segment' => (string) $row['segment'],
                'product_type' => (string) $row['product_type'],
                'color_code' => (string) $row['color_code'],
                'color_name' => (string) $row['color_name'],
                'color_hex' => $row['color_hex'],
                'image_path' => $row['image_path'],
                'size_qty' => array_map('intval', is_array($sizes) ? $sizes : []),
                'qty' => (int) $row['qty'],
                'unit_price_cents' => (int) $row['unit_price_cents'],
                'line_cents' => (int) $row['line_cents'],
            ];
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    public function addons(int $orderId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM order_addons WHERE order_id = ? ORDER BY id', [$orderId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'pricing' => (string) $row['pricing'],
            'unit_price_cents' => $row['unit_price_cents'] === null ? null : (int) $row['unit_price_cents'],
            'qty' => (int) $row['qty'],
            'line_cents' => $row['line_cents'] === null ? null : (int) $row['line_cents'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> Oldest first. */
    public function events(int $orderId, bool $publicOnly): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM order_events WHERE order_id = ?' . ($publicOnly ? ' AND is_public = 1' : '') . ' ORDER BY created_at, id',
            [$orderId],
        );

        return array_map(static fn (array $row): array => [
            'type' => (string) $row['type'],
            'from_status' => $row['from_status'],
            'to_status' => $row['to_status'],
            'note' => $row['note'],
            'actor_type' => (string) $row['actor_type'],
            'actor_id' => $publicOnly || $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'created_at' => gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC')),
        ], $rows);
    }

    public function addEvent(int $orderId, string $type, ?string $from, ?string $to, ?string $note, string $actorType, ?int $actorId, bool $public = true): void
    {
        $this->db->insert('order_events', [
            'order_id' => $orderId,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'is_public' => $public ? 1 : 0,
        ]);
    }

    /**
     * Moves an order from one status to another and records it. Returns false (and changes nothing)
     * when the order is no longer in the expected status, e.g. after a change in another tab.
     *
     * @param array<string, mixed> $extra other columns to set, e.g. cancelled_at
     */
    public function changeStatus(int $orderId, string $from, string $to, string $actorType, ?int $actorId, ?string $note = null, array $extra = []): bool
    {
        return $this->db->transaction(function () use ($orderId, $from, $to, $actorType, $actorId, $note, $extra): bool {
            $fields = ['status' => $to] + $extra;
            $set = implode(', ', array_map(static fn (string $c): string => Database::quoteIdentifier($c) . ' = ?', array_keys($fields)));
            $changed = $this->db->execute(
                "UPDATE orders SET {$set} WHERE id = ? AND status = ?",
                [...array_values($fields), $orderId, $from],
            ) > 0;
            if ($changed) {
                $this->addEvent($orderId, 'status', $from, $to, $note, $actorType, $actorId);
            }

            return $changed;
        });
    }

    /**
     * Adds the style names and first photo of each order (for lists).
     *
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    private function withLineSummaries(array $orders): array
    {
        if ($orders === []) {
            return [];
        }
        $ids = array_map(static fn (array $o): int => (int) $o['id'], $orders);
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT order_id, style_name, color_name, image_path FROM order_lines WHERE order_id IN ({$in}) ORDER BY sort_order, id",
            $ids,
        );
        $byOrder = [];
        foreach ($rows as $row) {
            $byOrder[(int) $row['order_id']][] = $row;
        }

        return array_map(static function (array $order) use ($byOrder): array {
            $lines = $byOrder[$order['id']] ?? [];
            $order['styles'] = array_values(array_unique(array_map(static fn (array $l): string => (string) $l['style_name'], $lines)));
            $order['color_count'] = count($lines);
            $order['image_path'] = $lines[0]['image_path'] ?? null;

            return $order;
        }, $orders);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : json_decode((string) $row[$column], true);
        }
        foreach (['id', 'customer_id', 'total_pcs', 'goods_cents', 'addons_cents', 'deposit_percent', 'deposit_cents', 'paid_cents', 'lead_time_days', 'reminders_sent'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        foreach (['freight_estimate_cents', 'freight_final_cents'] as $column) {
            $row[$column] = $row[$column] === null ? null : (int) $row[$column];
        }
        foreach (['placed_at', 'confirmed_at', 'balance_invoiced_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'created_at', 'updated_at'] as $column) {
            $row[$column] = $row[$column] === null ? null : gmdate(DATE_ATOM, (int) strtotime($row[$column] . ' UTC'));
        }

        return $row;
    }
}
