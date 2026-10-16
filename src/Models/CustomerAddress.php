<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** A buyer's delivery addresses. Every query is scoped to the buyer, so one buyer can never reach another's address. */
final class CustomerAddress
{
    public const FIELDS = ['company', 'contact_name', 'phone', 'email', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'tax_id'];

    /** A buyer can keep this many addresses. */
    public const MAX_PER_CUSTOMER = 20;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> The default address first. */
    public function forCustomer(int $customerId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id', [$customerId]);

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $customerId, int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]);

        return $row === null ? null : self::present($row);
    }

    public function count(int $customerId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$customerId]);
    }

    /** @param array<string, mixed> $data The first address becomes the default. */
    public function create(int $customerId, array $data, bool $makeDefault): int
    {
        return $this->db->transaction(function () use ($customerId, $data, $makeDefault): int {
            $makeDefault = $makeDefault || $this->count($customerId) === 0;
            if ($makeDefault) {
                $this->db->execute('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [$customerId]);
            }

            return $this->db->insert('customer_addresses', ['customer_id' => $customerId, 'is_default' => $makeDefault ? 1 : 0] + self::fields($data));
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $customerId, int $id, array $data, bool $makeDefault): void
    {
        $this->db->transaction(function () use ($customerId, $id, $data, $makeDefault): void {
            if ($makeDefault) {
                $this->db->execute('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [$customerId]);
            }
            $fields = self::fields($data) + ($makeDefault ? ['is_default' => 1] : []);
            $this->db->update('customer_addresses', $fields, ['id' => $id, 'customer_id' => $customerId]);
        });
    }

    /** Deleting the default address makes the oldest remaining one the default. */
    public function delete(int $customerId, int $id): void
    {
        $this->db->transaction(function () use ($customerId, $id): void {
            $this->db->execute('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]);
            $hasDefault = $this->db->fetchValue('SELECT id FROM customer_addresses WHERE customer_id = ? AND is_default = 1', [$customerId]) !== null;
            if (!$hasDefault) {
                $this->db->execute('UPDATE customer_addresses SET is_default = 1 WHERE customer_id = ? ORDER BY id LIMIT 1', [$customerId]);
            }
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function fields(array $data): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            $fields[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $address = ['id' => (int) $row['id']];
        foreach (self::FIELDS as $field) {
            $address[$field] = $row[$field] === null ? null : (string) $row[$field];
        }
        $address['is_default'] = (bool) $row['is_default'];

        return $address;
    }
}
