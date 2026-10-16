<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Garment measurements per size, e.g. waist, hip and inseam for sizes 30–40.
 * `measurements` are the column names; `size_rows` hold one value per measurement for each size.
 */
final class SizeChart
{
    public const UNITS = ['in', 'cm'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.size_chart_id = s.id) AS product_count FROM size_charts s ORDER BY s.name, s.id'
        );

        return array_map(static fn (array $row): array => self::present($row) + ['product_count' => (int) $row['product_count']], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM size_charts WHERE id = ?', [$id]);

        return $row === null ? null : self::present($row);
    }

    /** @param array{name: string, unit: string, measurements: list<string>, size_rows: list<array{size: string, values: list<string>}>, note: ?string} $data */
    public function create(array $data): int
    {
        return $this->db->insert('size_charts', self::encode($data));
    }

    /** @param array{name: string, unit: string, measurements: list<string>, size_rows: list<array{size: string, values: list<string>}>, note: ?string} $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('size_charts', self::encode($data), ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM size_charts WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function encode(array $data): array
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        return [
            'name' => $data['name'],
            'unit' => $data['unit'],
            'measurements' => json_encode(array_values($data['measurements']), $flags),
            'size_rows' => json_encode(array_values($data['size_rows']), $flags),
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, unit: string, measurements: list<string>, size_rows: list<array{size: string, values: list<string>}>, note: ?string, updated_at: string}
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'measurements' => json_decode((string) $row['measurements'], true) ?: [],
            'size_rows' => json_decode((string) $row['size_rows'], true) ?: [],
            'note' => $row['note'],
            'updated_at' => gmdate(DATE_ATOM, (int) strtotime($row['updated_at'] . ' UTC')),
        ];
    }
}
