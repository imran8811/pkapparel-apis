<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Bank transfers reported by buyers. Staff confirm or reject them (Phase 4). */
final class OrderPayment
{
    public const KINDS = ['deposit' => 'Deposit', 'balance' => 'Balance'];

    public const STATUSES = ['submitted' => 'Under review', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Transaction IDs are compared without spaces, dashes or letter case,
     * so "FT 2610-001" and "ft2610001" count as the same transfer.
     */
    public static function transactionKey(string $transactionId): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $transactionId));
    }

    public function transactionUsed(string $transactionId): bool
    {
        return $this->db->fetchValue('SELECT id FROM order_payments WHERE transaction_key = ?', [self::transactionKey($transactionId)]) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $orderId, array $data): int
    {
        return $this->db->insert('order_payments', [
            'order_id' => $orderId,
            'transaction_key' => self::transactionKey((string) $data['transaction_id']),
        ] + $data);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM order_payments WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return list<array<string, mixed>> Oldest first. */
    public function forOrder(int $orderId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM order_payments WHERE order_id = ? ORDER BY created_at, id', [$orderId]);

        return array_map(static fn (array $row): array => self::decode($row), $rows);
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM order_payments WHERE status = ?', [$status]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (['id', 'order_id', 'amount_cents'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        $row['reviewed_by'] = $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'];
        foreach (['reviewed_at', 'receipt_sent_at', 'created_at', 'updated_at'] as $column) {
            $row[$column] = $row[$column] === null ? null : gmdate(DATE_ATOM, (int) strtotime($row[$column] . ' UTC'));
        }

        return $row;
    }
}
