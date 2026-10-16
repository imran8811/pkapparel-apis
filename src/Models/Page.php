<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Simple content pages with rich text (terms of sale, privacy, cookies…).
 * The website has a fixed route for each page, so pages are edited but never added or removed.
 */
final class Page
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findPublished(string $slug): ?array
    {
        $row = $this->db->fetch(
            'SELECT slug, title, body_html, seo_title, seo_description, updated_at FROM pages WHERE slug = ? AND is_published = 1',
            [$slug],
        );
        if ($row === null) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'body_html' => (string) $row['body_html'],
            'seo_title' => $row['seo_title'],
            'seo_description' => $row['seo_description'],
            'updated_at' => self::isoDate($row['updated_at']),
        ];
    }

    /** @return list<array{slug: string, title: string, is_published: bool, updated_at: string}> */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT slug, title, is_published, updated_at FROM pages ORDER BY title');

        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'is_published' => (bool) $row['is_published'],
            'updated_at' => self::isoDate($row['updated_at']),
        ], $rows);
    }

    /** @return array<string, mixed>|null Any page, published or not (admin). */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetch('SELECT * FROM pages WHERE slug = ?', [$slug]);
        if ($row === null) {
            return null;
        }

        return [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'body_html' => (string) $row['body_html'],
            'seo_title' => $row['seo_title'],
            'seo_description' => $row['seo_description'],
            'is_published' => (bool) $row['is_published'],
            'updated_at' => self::isoDate($row['updated_at']),
        ];
    }

    /** @param array{title: string, body_html: string, seo_title: ?string, seo_description: ?string, is_published: bool} $data */
    public function update(string $slug, array $data): void
    {
        $this->db->update('pages', [
            'title' => $data['title'],
            'body_html' => $data['body_html'],
            'seo_title' => $data['seo_title'],
            'seo_description' => $data['seo_description'],
            'is_published' => $data['is_published'] ? 1 : 0,
        ], ['slug' => $slug]);
    }

    private static function isoDate(string $value): string
    {
        return gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));
    }
}
