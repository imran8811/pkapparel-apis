<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Basic table access for simple content lists (FAQs, reviews, certificates…).
 * Subclasses set TABLE and FILLABLE; only FILLABLE columns can be written.
 */
abstract class Repository
{
    protected const TABLE = '';

    /** @var list<string> */
    protected const FILLABLE = [];

    public function __construct(protected readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> Every row, published or not (admin). */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY sort_order, id');

        return array_map(static fn (array $row): array => static::cast($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM ' . $this->table() . ' WHERE id = ?', [$id]);

        return $row === null ? null : self::cast($row);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $data['sort_order'] ??= $this->nextSortOrder();

        return $this->db->insert(static::TABLE, $this->fillable($data));
    }

    /** @param array<string, mixed> $data */
    public function updateById(int $id, array $data): void
    {
        $fields = $this->fillable($data);
        if ($fields !== []) {
            $this->db->update(static::TABLE, $fields, ['id' => $id]);
        }
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM ' . $this->table() . ' WHERE id = ?', [$id]) > 0;
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->table());
    }

    /** New rows go to the end of the list. */
    protected function nextSortOrder(): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' . $this->table());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fillable(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(static::FILLABLE));
        foreach ($fields as $name => $value) {
            if (is_bool($value)) {
                $fields[$name] = $value ? 1 : 0;
            }
        }

        return $fields;
    }

    protected function table(): string
    {
        return Database::quoteIdentifier(static::TABLE);
    }

    /**
     * Database row → JSON-friendly values: ids and numbers as ints, is_* flags as booleans, times as ISO 8601.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function cast(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value === null) {
                continue;
            }
            $row[$key] = match (true) {
                in_array($key, ['id', 'sort_order', 'rating'], true) => (int) $value,
                str_starts_with($key, 'is_') => (bool) $value,
                str_ends_with($key, '_at') => gmdate(DATE_ATOM, (int) strtotime($value . ' UTC')),
                default => $value,
            };
        }

        return $row;
    }
}
