<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Quotations staff make in the admin (tables quotations and quotation_styles). The buyer is a JSON copy, so a
 * quotation keeps what it said when the buyer's account changes. Prices are stored as App\Services\Quotations
 * worked them out when the quotation was saved.
 */
final class Quotation
{
    public const STATUSES = ['open' => 'Open', 'accepted' => 'Accepted', 'declined' => 'Declined'];

    /** Columns the service writes (everything except the id, number, status and the dates it manages). */
    private const FILLABLE = [
        'customer_id', 'inquiry_id', 'buyer', 'currency', 'exchange_rate', 'issue_date', 'valid_until', 'buyer_reference',
        'incoterm', 'delivery_time', 'total_qty', 'total_cents', 'shipping_cents', 'notes', 'terms', 'staff_notes',
    ];

    /** A style's columns, in the order the service returns them. */
    public const STYLE_COLUMNS = [
        'article_no', 'description', 'fabric', 'colors', 'sizes', 'details', 'qty',
        'fabric_rate_cents', 'fabric_unit', 'fabric_consumption', 'fabric_cost_cents',
        'cutting_cents', 'stitching_cents', 'washing_cents', 'packing_cents', 'printing_cents', 'embroidery_cents',
        'accessories_cents', 'overhead_cents', 'extra_cents', 'extra_label',
        'cost_cents', 'profit_type', 'profit_percent', 'profit_cents', 'price_cents', 'unit_price_cents', 'amount_cents',
    ];

    private const STYLE_INTS = [
        'fabric_rate_cents', 'fabric_cost_cents', 'cutting_cents', 'stitching_cents', 'washing_cents', 'packing_cents',
        'printing_cents', 'embroidery_cents', 'accessories_cents', 'overhead_cents', 'extra_cents', 'cost_cents',
        'profit_cents', 'price_cents', 'unit_price_cents',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $fields FILLABLE columns
     * @param list<array<string, mixed>> $styles
     */
    public function create(string $quotationNo, array $fields, array $styles, ?int $staffId): int
    {
        return $this->db->transaction(function () use ($quotationNo, $fields, $styles, $staffId): int {
            $now = gmdate('Y-m-d H:i:s');
            $id = $this->db->insert('quotations', ['quotation_no' => $quotationNo, 'status' => 'open', 'created_by' => $staffId, 'created_at' => $now, 'updated_at' => $now] + self::encode($fields));
            $this->insertStyles($id, $styles);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $fields FILLABLE columns
     * @param list<array<string, mixed>> $styles
     */
    public function replace(int $id, array $fields, array $styles): void
    {
        $this->db->transaction(function () use ($id, $fields, $styles): void {
            $this->db->update('quotations', self::encode($fields) + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
            $this->db->execute('DELETE FROM quotation_styles WHERE quotation_id = ?', [$id]);
            $this->insertStyles($id, $styles);
        });
    }

    /** @param array<string, mixed> $fields e.g. status, sent_at, decided_at (columns this class manages) */
    public function mark(int $id, array $fields): void
    {
        $this->db->update('quotations', $fields + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** @return array<string, mixed>|null the quotation with its styles, the account and inquiry it is for, and who made it */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT q.*, s.name AS staff_name, c.company_name AS customer_company, c.email AS customer_email
             FROM quotations q LEFT JOIN staff_users s ON s.id = q.created_by LEFT JOIN customers c ON c.id = q.customer_id
             WHERE q.id = ?',
            [$id],
        );
        if ($row === null) {
            return null;
        }
        $quotation = self::decode($row);
        $quotation['styles'] = array_map(self::decodeStyle(...), $this->db->fetchAll('SELECT * FROM quotation_styles WHERE quotation_id = ? ORDER BY sort_order, id', [$id]));
        $quotation['styles_count'] = count($quotation['styles']);

        return $quotation;
    }

    /**
     * Newest first. $status is open, accepted, declined or expired (open and past its validity date in Pakistan).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, ?string $search, string $today, int $page, int $perPage): array
    {
        [$where, $params] = $this->filters($status, $search, $today);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM quotations q WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT q.*, NULL AS staff_name, NULL AS customer_company, NULL AS customer_email,
                    (SELECT COUNT(*) FROM quotation_styles st WHERE st.quotation_id = q.id) AS styles_count,
                    (SELECT GROUP_CONCAT(st.article_no ORDER BY st.sort_order SEPARATOR ', ') FROM quotation_styles st WHERE st.quotation_id = q.id AND st.article_no IS NOT NULL) AS article_nos
             FROM quotations q WHERE {$where}
             ORDER BY q.issue_date DESC, q.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map(static fn (array $row): array => self::decode($row) + [
            'styles_count' => (int) $row['styles_count'],
            'article_nos' => $row['article_nos'] === null ? null : mb_substr((string) $row['article_nos'], 0, 200),
        ], $rows), 'total' => $total];
    }

    /** @return array{open: int, expired: int, accepted: int, declined: int} */
    public function counts(string $today): array
    {
        $row = $this->db->fetch(
            "SELECT SUM(status = 'open') AS open, SUM(status = 'open' AND valid_until < ?) AS expired,
                    SUM(status = 'accepted') AS accepted, SUM(status = 'declined') AS declined
             FROM quotations",
            [$today],
        ) ?? [];

        return [
            'open' => (int) ($row['open'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
            'accepted' => (int) ($row['accepted'] ?? 0),
            'declined' => (int) ($row['declined'] ?? 0),
        ];
    }

    /**
     * The exchange rate of the latest quotation in each foreign currency, for a new quotation to start from.
     *
     * @return array<string, string> e.g. ['USD' => '280.5']
     */
    public function latestExchangeRates(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT q.currency, q.exchange_rate FROM quotations q
             JOIN (SELECT currency, MAX(id) AS id FROM quotations WHERE exchange_rate IS NOT NULL GROUP BY currency) latest ON latest.id = q.id',
        );
        $rates = [];
        foreach ($rows as $row) {
            $rates[(string) $row['currency']] = self::decimal($row['exchange_rate']);
        }

        return $rates;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filters(?string $status, ?string $search, string $today): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status === 'expired') {
            $where[] = "q.status = 'open' AND q.valid_until < ?";
            $params[] = $today;
        } elseif ($status !== null) {
            $where[] = 'q.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            // JSON strings compare in binary in MySQL: lower-case both sides for a case-insensitive search.
            $where[] = "(q.quotation_no LIKE ? OR q.buyer_reference LIKE ?
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(q.buyer, '$.company'))) LIKE ?
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(q.buyer, '$.contact_name'))) LIKE ?
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(q.buyer, '$.email'))) LIKE ?
                OR EXISTS (SELECT 1 FROM quotation_styles st WHERE st.quotation_id = q.id AND (st.article_no LIKE ? OR st.description LIKE ?)))";
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $lower = mb_strtolower($like);
            array_push($params, $like, $like, $lower, $lower, $lower, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param list<array<string, mixed>> $styles */
    private function insertStyles(int $quotationId, array $styles): void
    {
        foreach (array_values($styles) as $index => $style) {
            $row = ['quotation_id' => $quotationId, 'sort_order' => $index + 1];
            foreach (self::STYLE_COLUMNS as $column) {
                $row[$column] = $style[$column] ?? null;
            }
            $this->db->insert('quotation_styles', $row);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function encode(array $fields): array
    {
        $fields = array_intersect_key($fields, array_flip(self::FILLABLE));
        if (array_key_exists('buyer', $fields)) {
            $fields['buyer'] = json_encode($fields['buyer'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $fields;
    }

    /** "1.350" → "1.35", "280.0000" → "280": a DECIMAL column as typed, without the padding. */
    private static function decimal(mixed $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decodeStyle(array $row): array
    {
        $style = [];
        foreach (self::STYLE_COLUMNS as $column) {
            $style[$column] = $row[$column];
        }
        foreach (self::STYLE_INTS as $column) {
            $style[$column] = (int) $row[$column];
        }
        $style['qty'] = $row['qty'] === null ? null : (int) $row['qty'];
        $style['amount_cents'] = $row['amount_cents'] === null ? null : (int) $row['amount_cents'];
        $style['fabric_unit'] = (string) $row['fabric_unit'];
        $style['fabric_consumption'] = self::decimal($row['fabric_consumption']);
        $style['profit_type'] = (string) $row['profit_type'];
        $style['profit_percent'] = $row['profit_percent'] === null ? null : self::decimal($row['profit_percent']);

        return $style;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $time = static fn (?string $value): ?string => $value === null ? null : gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));
        $buyer = json_decode((string) $row['buyer'], true);

        return [
            'id' => (int) $row['id'],
            'quotation_no' => (string) $row['quotation_no'],
            'status' => (string) $row['status'],
            'customer_id' => $row['customer_id'] === null ? null : (int) $row['customer_id'],
            'customer' => $row['customer_id'] === null || $row['customer_company'] === null ? null : [
                'id' => (int) $row['customer_id'],
                'company_name' => (string) $row['customer_company'],
                'email' => (string) $row['customer_email'],
            ],
            'inquiry_id' => $row['inquiry_id'] === null ? null : (int) $row['inquiry_id'],
            'inquiry' => $row['inquiry_id'] === null ? null : ['id' => (int) $row['inquiry_id'], 'reference' => Inquiry::reference((int) $row['inquiry_id'])],
            'buyer' => is_array($buyer) ? $buyer : [],
            'currency' => (string) $row['currency'],
            'exchange_rate' => $row['exchange_rate'] === null ? null : self::decimal($row['exchange_rate']),
            'issue_date' => (string) $row['issue_date'],
            'valid_until' => $row['valid_until'] === null ? null : (string) $row['valid_until'],
            'buyer_reference' => $row['buyer_reference'],
            'incoterm' => $row['incoterm'],
            'delivery_time' => $row['delivery_time'],
            'total_qty' => (int) $row['total_qty'],
            'total_cents' => (int) $row['total_cents'],
            // The shipping charge for the whole order, on top of the styles (0: none).
            'shipping_cents' => (int) ($row['shipping_cents'] ?? 0),
            'notes' => $row['notes'],
            'terms' => $row['terms'],
            'staff_notes' => $row['staff_notes'],
            'sent_at' => $time($row['sent_at']),
            'sent_to' => $row['sent_to'],
            'decided_at' => $time($row['decided_at']),
            'created_by' => $row['staff_name'] === null ? null : (string) $row['staff_name'],
            'created_at' => $time($row['created_at']),
            'updated_at' => $time($row['updated_at']),
        ];
    }
}
