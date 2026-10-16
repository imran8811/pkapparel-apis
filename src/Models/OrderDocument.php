<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Files staff attach to an order (private): commercial invoice, packing list, B/L or AWB, QC report, other. */
final class OrderDocument
{
    public const TYPES = [
        'commercial_invoice' => 'Commercial invoice',
        'packing_list' => 'Packing list',
        'bl' => 'Bill of lading (B/L)',
        'awb' => 'Air waybill (AWB)',
        'qc_report' => 'QC report',
        'other' => 'Other document',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(int $orderId, array $data): int
    {
        return $this->db->insert('order_documents', ['order_id' => $orderId] + $data);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM order_documents WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return list<array<string, mixed>> Oldest first; the buyer only gets the public ones. */
    public function forOrder(int $orderId, bool $publicOnly): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM order_documents WHERE order_id = ?' . ($publicOnly ? ' AND is_public = 1' : '') . ' ORDER BY created_at, id',
            [$orderId],
        );

        return array_map(static fn (array $row): array => self::decode($row), $rows);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM order_documents WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (['id', 'order_id', 'size_bytes'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        $row['is_public'] = (bool) $row['is_public'];
        $row['uploaded_by'] = $row['uploaded_by'] === null ? null : (int) $row['uploaded_by'];
        $row['created_at'] = gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC'));

        return $row;
    }
}
