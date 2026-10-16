<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class FactoryDepartment
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array{slug: string, name: string, summary: string, stats: list<array{label: string, value: string}>, images: list<array{path: string, alt: string}>}>
     */
    public function published(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT slug, name, summary, stats, images FROM factory_departments WHERE is_published = 1 ORDER BY sort_order, id'
        );

        return array_map([self::class, 'present'], $rows);
    }

    /** @return list<array<string, mixed>> Every department, published or not (admin). */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM factory_departments ORDER BY sort_order, id');

        return array_map(static fn (array $row): array => self::present($row) + [
            'sort_order' => (int) $row['sort_order'],
            'is_published' => (bool) $row['is_published'],
            'updated_at' => gmdate(DATE_ATOM, (int) strtotime($row['updated_at'] . ' UTC')),
        ], $rows);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetch('SELECT * FROM factory_departments WHERE slug = ?', [$slug]);

        return $row === null ? null : self::present($row) + [
            'sort_order' => (int) $row['sort_order'],
            'is_published' => (bool) $row['is_published'],
            'updated_at' => gmdate(DATE_ATOM, (int) strtotime($row['updated_at'] . ' UTC')),
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(string $slug, array $data): void
    {
        $this->db->update('factory_departments', [
            'name' => $data['name'],
            'summary' => $data['summary'],
            'stats' => json_encode($data['stats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'images' => json_encode($data['images'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'sort_order' => $data['sort_order'],
            'is_published' => $data['is_published'] ? 1 : 0,
        ], ['slug' => $slug]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{slug: string, name: string, summary: string, stats: list<array{label: string, value: string}>, images: list<array{path: string, alt: string}>}
     */
    private static function present(array $row): array
    {
        return [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'summary' => (string) $row['summary'],
            'stats' => json_decode((string) $row['stats'], true) ?: [],
            'images' => json_decode((string) $row['images'], true) ?: [],
        ];
    }
}
