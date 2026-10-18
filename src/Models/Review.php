<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Reviews of delivered orders (owner decision 2026-10-17). A row is made when the review is requested (the emailed
 * link's token, stored hashed) or when the buyer writes one from their order page. Staff approve a review before it
 * appears on the product pages of the styles in the order.
 */
final class Review
{
    public const STATUSES = ['requested' => 'Requested', 'pending' => 'To approve', 'approved' => 'Published', 'hidden' => 'Hidden'];

    /** How the buyer is named: their company, or their business type and country. */
    public const SHOW_AS = ['company', 'business'];

    /** How long the emailed link works. */
    public const LINK_DAYS = 90;

    public function __construct(private readonly Database $db)
    {
    }

    public function request(int $orderId, string $tokenHash): int
    {
        return $this->db->insert('reviews', [
            'order_id' => $orderId,
            'token_hash' => $tokenHash,
            'requested_at' => gmdate('Y-m-d H:i:s'),
            'status' => 'requested',
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM reviews WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $orderId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM reviews WHERE order_id = ?', [$orderId]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null The review an emailed link belongs to, while the link works. */
    public function findByToken(string $token): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM reviews WHERE token_hash = ? AND requested_at >= UTC_TIMESTAMP() - INTERVAL ' . self::LINK_DAYS . ' DAY',
            [hash('sha256', $token)],
        );

        return $row === null ? null : self::decode($row);
    }

    /** Saves what the buyer wrote; the review then waits for approval. Returns the review id. */
    public function submit(int $orderId, int $rating, string $body, string $showAs): int
    {
        $fields = ['rating' => $rating, 'body' => $body, 'show_as' => $showAs, 'status' => 'pending', 'submitted_at' => gmdate('Y-m-d H:i:s')];
        $existing = $this->findForOrder($orderId);
        if ($existing !== null) {
            $this->db->update('reviews', $fields, ['id' => $existing['id']]);

            return $existing['id'];
        }

        return $this->db->insert('reviews', ['order_id' => $orderId] + $fields);
    }

    public function setStatus(int $id, string $status, int $staffId): void
    {
        $this->db->update('reviews', ['status' => $status, 'reviewed_by' => $staffId, 'reviewed_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function setTestimonial(int $id, ?int $testimonialId): void
    {
        $this->db->update('reviews', ['testimonial_id' => $testimonialId], ['id' => $id]);
    }

    /**
     * Submitted reviews for staff, newest first, with the order and buyer.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, int $page, int $perPage): array
    {
        $where = "r.status <> 'requested'" . ($status !== null ? ' AND r.status = ?' : '');
        $params = $status !== null ? [$status] : [];
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM reviews r WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT r.*, o.order_no, o.customer, o.address, o.total_pcs FROM reviews r JOIN orders o ON o.id = r.order_id
             WHERE {$where} ORDER BY r.submitted_at DESC, r.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map(fn (array $row): array => self::decode($row) + self::orderPart($row) + ['styles' => $this->styles((int) $row['order_id'])], $rows), 'total' => $total];
    }

    /** @return array<string, int> status => number of submitted reviews */
    public function counts(): array
    {
        $counts = ['pending' => 0, 'approved' => 0, 'hidden' => 0];
        foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS n FROM reviews WHERE status <> 'requested' GROUP BY status") as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Approved reviews of orders that included this style, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function approvedForProduct(int $productId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            "SELECT r.*, o.order_no, o.customer, o.address, o.total_pcs FROM reviews r JOIN orders o ON o.id = r.order_id
             WHERE r.status = 'approved' AND EXISTS (SELECT 1 FROM order_lines l WHERE l.order_id = r.order_id AND l.product_id = ?)
             ORDER BY r.submitted_at DESC, r.id DESC LIMIT " . (int) $limit,
            [$productId],
        );

        return array_map(static fn (array $row): array => self::decode($row) + self::orderPart($row), $rows);
    }

    /** @return array{count: int, average: ?float} approved reviews of orders that included this style */
    public function statsForProduct(int $productId): array
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS n, AVG(r.rating) AS average FROM reviews r
             WHERE r.status = 'approved' AND EXISTS (SELECT 1 FROM order_lines l WHERE l.order_id = r.order_id AND l.product_id = ?)",
            [$productId],
        );
        $count = (int) ($row['n'] ?? 0);

        return ['count' => $count, 'average' => $count > 0 ? round((float) $row['average'], 1) : null];
    }

    /**
     * Delivered bulk orders without a review (requested or written), delivered between the two times (UTC).
     *
     * @return list<array{id: int, order_no: string}>
     */
    public function dueForRequest(string $deliveredAfter, string $deliveredBefore): array
    {
        $rows = $this->db->fetchAll(
            "SELECT o.id, o.order_no FROM orders o
             WHERE o.kind = 'bulk' AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at <= ?
               AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.order_id = o.id)
             ORDER BY o.delivered_at, o.id",
            [$deliveredAfter, $deliveredBefore],
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'order_no' => (string) $row['order_no']], $rows);
    }

    /** @return list<array{product_id: ?int, name: string, slug: string}> the styles of an order */
    public function styles(int $orderId): array
    {
        $rows = $this->db->fetchAll('SELECT DISTINCT product_id, style_name, style_slug FROM order_lines WHERE order_id = ? ORDER BY style_name', [$orderId]);

        return array_map(static fn (array $row): array => [
            'product_id' => $row['product_id'] === null ? null : (int) $row['product_id'],
            'name' => (string) $row['style_name'],
            'slug' => (string) $row['style_slug'],
        ], $rows);
    }

    /**
     * How the review names the buyer: "Northwind Denim, Germany" or "Brand owner, Germany".
     *
     * @param array<string, mixed> $review with the order's customer and address (adminList, approvedForProduct)
     */
    public static function displayName(array $review): string
    {
        $customer = $review['customer'] ?? [];

        return $review['show_as'] === 'company' && trim((string) ($customer['company_name'] ?? '')) !== ''
            ? (string) $customer['company_name']
            : (Customer::BUSINESS_TYPES[$customer['business_type'] ?? ''] ?? 'Buyer');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function orderPart(array $row): array
    {
        $customer = json_decode((string) $row['customer'], true) ?: [];
        $address = json_decode((string) $row['address'], true) ?: [];

        return [
            'order_no' => (string) $row['order_no'],
            'customer' => $customer,
            'country_code' => $address['country_code'] ?? ($customer['country_code'] ?? null),
            'total_pcs' => (int) $row['total_pcs'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $date = static fn (?string $value): ?string => $value === null ? null : gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));

        return [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'rating' => $row['rating'] === null ? null : (int) $row['rating'],
            'body' => $row['body'],
            'show_as' => (string) $row['show_as'],
            'status' => (string) $row['status'],
            'requested_at' => $date($row['requested_at']),
            'submitted_at' => $date($row['submitted_at']),
            'reviewed_at' => $date($row['reviewed_at']),
            'testimonial_id' => $row['testimonial_id'] === null ? null : (int) $row['testimonial_id'],
        ];
    }
}
