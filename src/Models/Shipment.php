<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** How an order travelled: carrier, tracking, ship date, cartons and weight (one shipment per order). */
final class Shipment
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function forOrder(int $orderId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM shipments WHERE order_id = ?', [$orderId]);
        if ($row === null) {
            return null;
        }

        return [
            'carrier' => (string) $row['carrier'],
            'tracking_no' => $row['tracking_no'],
            'tracking_url' => $row['tracking_url'],
            'shipped_on' => (string) $row['shipped_on'],
            'cartons' => $row['cartons'] === null ? null : (int) $row['cartons'],
            'gross_g' => $row['gross_g'] === null ? null : (int) $row['gross_g'],
            'note' => $row['note'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(int $orderId, array $data, int $staffId): void
    {
        $this->db->execute(
            'INSERT INTO shipments (order_id, carrier, tracking_no, tracking_url, shipped_on, cartons, gross_g, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE carrier = VALUES(carrier), tracking_no = VALUES(tracking_no), tracking_url = VALUES(tracking_url),
                 shipped_on = VALUES(shipped_on), cartons = VALUES(cartons), gross_g = VALUES(gross_g), note = VALUES(note)',
            [
                $orderId,
                $data['carrier'],
                $data['tracking_no'] ?? null,
                $data['tracking_url'] ?? null,
                $data['shipped_on'],
                $data['cartons'] ?? null,
                $data['gross_g'] ?? null,
                $data['note'] ?? null,
                $staffId,
            ],
        );
    }
}
