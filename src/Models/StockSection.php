<?php

declare(strict_types=1);

namespace App\Models;

/**
 * How stock listings are grouped (owner request 2026-11-17): departments (Men, Women, Boys) and categories (Jeans,
 * Cotton pants), kept by staff in Admin → Stock categories. A listing has one of each, by slug. Slugs are part of the
 * stock pages' addresses, so they never change once made; names can.
 */
abstract class StockSection extends Repository
{
    /** The stock_styles column that holds the slug. */
    protected const STYLE_COLUMN = '';

    public const NAME_MAX = 60;

    public const SLUG_MAX = 20;

    /** @return list<array<string, mixed>> Every one in staff order, with the number of listings in it (any status) as `styles`. */
    public function withCounts(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.*, (SELECT COUNT(*) FROM stock_styles s WHERE s.' . static::STYLE_COLUMN . ' = t.slug) AS styles
             FROM ' . $this->table() . ' t ORDER BY t.sort_order, t.id',
        );

        return array_map(static function (array $row): array {
            $row = static::cast($row);
            $row['styles'] = (int) $row['styles'];

            return $row;
        }, $rows);
    }

    /** @return list<string> Every slug, in staff order. */
    public function slugs(): array
    {
        return array_map('strval', array_column($this->db->fetchAll('SELECT slug FROM ' . $this->table() . ' ORDER BY sort_order, id'), 'slug'));
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetch('SELECT * FROM ' . $this->table() . ' WHERE slug = ?', [$slug]);

        return $row === null ? null : static::cast($row);
    }

    public function slugExists(string $slug): bool
    {
        return $this->db->fetchValue('SELECT id FROM ' . $this->table() . ' WHERE slug = ?', [$slug]) !== null;
    }

    /** Whether another one has this name (in any letter case). */
    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM ' . $this->table() . ' WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, $exceptId ?? 0]) !== null;
    }

    /** Listings in it, whatever their status. */
    public function styleCount(string $slug): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM stock_styles WHERE ' . static::STYLE_COLUMN . ' = ?', [$slug]);
    }
}
