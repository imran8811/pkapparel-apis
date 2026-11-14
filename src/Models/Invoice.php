<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Invoices staff make in the admin (tables invoices and invoice_lines). The buyer and consignee are JSON copies,
 * so an invoice keeps what it said even when the buyer's account changes. Amounts are stored as computed by
 * App\Services\Invoices when the invoice is saved.
 */
final class Invoice
{
    public const TYPES = ['sample' => 'Sample invoice', 'proforma' => 'Proforma invoice', 'commercial' => 'Invoice'];

    public const STATUSES = ['open' => 'Open', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

    private const JSON_COLUMNS = ['buyer', 'consignee'];

    /** Columns the service writes (everything except the id, number, type, status and timestamps it manages). */
    private const FILLABLE = [
        'customer_id', 'buyer', 'consignee', 'currency', 'issue_date', 'due_date', 'buyer_reference', 'incoterm',
        'shipping_method', 'delivery_time', 'subtotal_cents', 'shipping_cents', 'discount_cents', 'total_cents',
        'received_cents', 'deposit_percent', 'due_now_cents', 'notes', 'terms', 'staff_notes',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $fields FILLABLE columns
     * @param list<array<string, mixed>> $lines
     */
    public function create(string $invoiceNo, string $type, array $fields, array $lines, ?int $staffId): int
    {
        return $this->db->transaction(function () use ($invoiceNo, $type, $fields, $lines, $staffId): int {
            $now = gmdate('Y-m-d H:i:s');
            $id = $this->db->insert('invoices', ['invoice_no' => $invoiceNo, 'type' => $type, 'status' => 'open', 'created_by' => $staffId, 'created_at' => $now, 'updated_at' => $now] + self::encode($fields));
            $this->insertLines($id, $lines);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $fields FILLABLE columns
     * @param list<array<string, mixed>> $lines
     */
    public function replace(int $id, array $fields, array $lines): void
    {
        $this->db->transaction(function () use ($id, $fields, $lines): void {
            $this->db->update('invoices', self::encode($fields) + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
            $this->db->execute('DELETE FROM invoice_lines WHERE invoice_id = ?', [$id]);
            $this->insertLines($id, $lines);
        });
    }

    /** @param array<string, mixed> $fields e.g. status, sent_at, paid_on (columns this class manages) */
    public function mark(int $id, array $fields): void
    {
        $this->db->update('invoices', $fields + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** @return array<string, mixed>|null the invoice with its lines, the account it was made for and who made it */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT i.*, s.name AS staff_name, c.company_name AS customer_company, c.email AS customer_email
             FROM invoices i LEFT JOIN staff_users s ON s.id = i.created_by LEFT JOIN customers c ON c.id = i.customer_id
             WHERE i.id = ?',
            [$id],
        );
        if ($row === null) {
            return null;
        }
        $invoice = self::decode($row);
        $invoice['lines'] = array_map(static fn (array $line): array => [
            'description' => (string) $line['description'],
            'details' => $line['details'] === null ? null : (string) $line['details'],
            'hs_code' => $line['hs_code'] === null ? null : (string) $line['hs_code'],
            'qty' => (int) $line['qty'],
            'unit' => (string) $line['unit'],
            'unit_price_cents' => (int) $line['unit_price_cents'],
            'amount_cents' => (int) $line['amount_cents'],
        ], $this->db->fetchAll('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY sort_order, id', [$id]));

        return $invoice;
    }

    /**
     * Newest first. $status is open, paid, cancelled or overdue (open and past the due date in Pakistan).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, ?string $type, ?string $search, string $today, int $page, int $perPage): array
    {
        [$where, $params] = $this->filters($status, $type, $search, $today);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM invoices i WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT i.*, NULL AS staff_name, NULL AS customer_company, NULL AS customer_email,
                    (SELECT COALESCE(SUM(l.qty), 0) FROM invoice_lines l WHERE l.invoice_id = i.id) AS total_qty
             FROM invoices i WHERE {$where}
             ORDER BY i.issue_date DESC, i.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map(self::decode(...), $rows), 'total' => $total];
    }

    /** @return array{open: int, overdue: int, paid: int, cancelled: int, sample: int, proforma: int, commercial: int} */
    public function counts(string $today): array
    {
        $row = $this->db->fetch(
            "SELECT SUM(status = 'open') AS open, SUM(status = 'open' AND due_date < ?) AS overdue,
                    SUM(status = 'paid') AS paid, SUM(status = 'cancelled') AS cancelled,
                    SUM(type = 'sample') AS sample, SUM(type = 'proforma') AS proforma, SUM(type = 'commercial') AS commercial
             FROM invoices",
            [$today],
        ) ?? [];

        return array_map(static fn (mixed $value): int => (int) $value, [
            'open' => $row['open'] ?? 0, 'overdue' => $row['overdue'] ?? 0, 'paid' => $row['paid'] ?? 0, 'cancelled' => $row['cancelled'] ?? 0,
            'sample' => $row['sample'] ?? 0, 'proforma' => $row['proforma'] ?? 0, 'commercial' => $row['commercial'] ?? 0,
        ]);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function filters(?string $status, ?string $type, ?string $search, string $today): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status === 'overdue') {
            $where[] = "i.status = 'open' AND i.due_date < ?";
            $params[] = $today;
        } elseif ($status !== null) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if ($type !== null) {
            $where[] = 'i.type = ?';
            $params[] = $type;
        }
        if ($search !== null && $search !== '') {
            // JSON strings compare in binary in MySQL: lower-case both sides for a case-insensitive search.
            $where[] = "(i.invoice_no LIKE ? OR i.buyer_reference LIKE ?
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(i.buyer, '$.company'))) LIKE ?
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(i.buyer, '$.contact_name'))) LIKE ?
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(i.buyer, '$.email'))) LIKE ?)";
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $lower = mb_strtolower($like);
            array_push($params, $like, $like, $lower, $lower, $lower);
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param list<array<string, mixed>> $lines */
    private function insertLines(int $invoiceId, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $this->db->insert('invoice_lines', [
                'invoice_id' => $invoiceId,
                'sort_order' => $index + 1,
                'description' => $line['description'],
                'details' => $line['details'] ?? null,
                'hs_code' => $line['hs_code'] ?? null,
                'qty' => $line['qty'],
                'unit' => $line['unit'] ?? 'pcs',
                'unit_price_cents' => $line['unit_price_cents'],
                'amount_cents' => $line['amount_cents'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function encode(array $fields): array
    {
        $fields = array_intersect_key($fields, array_flip(self::FILLABLE));
        foreach (self::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $fields) && $fields[$column] !== null) {
                $fields[$column] = json_encode($fields[$column], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $time = static fn (?string $value): ?string => $value === null ? null : gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));
        $json = static function (?string $value): ?array {
            $decoded = $value === null ? null : json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        };

        return [
            'id' => (int) $row['id'],
            'invoice_no' => (string) $row['invoice_no'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'customer_id' => $row['customer_id'] === null ? null : (int) $row['customer_id'],
            'customer' => $row['customer_id'] === null || $row['customer_company'] === null ? null : [
                'id' => (int) $row['customer_id'],
                'company_name' => (string) $row['customer_company'],
                'email' => (string) $row['customer_email'],
            ],
            'buyer' => $json($row['buyer']) ?? [],
            'consignee' => $json($row['consignee']),
            'currency' => (string) $row['currency'],
            'issue_date' => (string) $row['issue_date'],
            'due_date' => $row['due_date'] === null ? null : (string) $row['due_date'],
            'buyer_reference' => $row['buyer_reference'],
            'incoterm' => $row['incoterm'],
            'shipping_method' => $row['shipping_method'],
            'delivery_time' => $row['delivery_time'],
            'subtotal_cents' => (int) $row['subtotal_cents'],
            'shipping_cents' => (int) $row['shipping_cents'],
            'discount_cents' => (int) $row['discount_cents'],
            'total_cents' => (int) $row['total_cents'],
            'received_cents' => (int) $row['received_cents'],
            'deposit_percent' => (int) $row['deposit_percent'],
            'due_now_cents' => (int) $row['due_now_cents'],
            'total_qty' => isset($row['total_qty']) ? (int) $row['total_qty'] : null,
            'notes' => $row['notes'],
            'terms' => $row['terms'],
            'staff_notes' => $row['staff_notes'],
            'sent_at' => $time($row['sent_at']),
            'sent_to' => $row['sent_to'],
            'paid_on' => $row['paid_on'] === null ? null : (string) $row['paid_on'],
            'payment_reference' => $row['payment_reference'],
            'cancelled_at' => $time($row['cancelled_at']),
            'created_by' => $row['staff_name'] === null ? null : (string) $row['staff_name'],
            'created_at' => $time($row['created_at']),
            'updated_at' => $time($row['updated_at']),
        ];
    }
}
