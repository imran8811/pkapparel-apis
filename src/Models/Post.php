<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Blog posts. Only published posts with a publish date in the past are public. */
final class Post
{
    public const STATUSES = ['draft', 'published'];

    private const PUBLIC = "status = 'published' AND published_at IS NOT NULL AND published_at <= UTC_TIMESTAMP()";

    /** Columns the admin may write. */
    private const FILLABLE = [
        'slug', 'title', 'excerpt', 'body_html', 'cover_image_path', 'cover_image_alt', 'author_name',
        'status', 'published_at', 'seo_title', 'seo_description',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function published(int $page, int $perPage): array
    {
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM posts WHERE ' . self::PUBLIC);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->db->fetchAll(
            'SELECT slug, title, excerpt, cover_image_path, cover_image_alt, author_name, published_at, updated_at
             FROM posts WHERE ' . self::PUBLIC . '
             ORDER BY published_at DESC, id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );

        return ['items' => array_map([self::class, 'summary'], $rows), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findPublished(string $slug): ?array
    {
        $row = $this->db->fetch(
            'SELECT slug, title, excerpt, body_html, cover_image_path, cover_image_alt, author_name,
                    published_at, updated_at, seo_title, seo_description
             FROM posts WHERE slug = ? AND ' . self::PUBLIC,
            [$slug],
        );
        if ($row === null) {
            return null;
        }

        return self::summary($row) + [
            'body_html' => (string) $row['body_html'],
            'seo_title' => $row['seo_title'],
            'seo_description' => $row['seo_description'],
        ];
    }

    /** @param array<string, mixed> $data Insert or update by slug (used by the import script). */
    public function upsert(array $data): void
    {
        $existing = $this->db->fetchValue('SELECT id FROM posts WHERE slug = ?', [$data['slug']]);
        if ($existing === null) {
            $this->db->insert('posts', $data);
        } else {
            $this->db->update('posts', $data, ['id' => $existing]);
        }
    }

    // ----- Admin -----

    /**
     * Every post (any status) for the admin list, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, ?string $search, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(title LIKE ? OR slug LIKE ?)';
            array_push($params, $like, $like);
        }
        $condition = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM posts WHERE {$condition}", $params);
        $rows = $this->db->fetchAll(
            "SELECT id, slug, title, status, cover_image_path, cover_image_alt, published_at, updated_at
             FROM posts WHERE {$condition}
             ORDER BY COALESCE(published_at, created_at) DESC, id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params,
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'status' => (string) $row['status'],
                'visibility' => self::visibility($row),
                'cover' => empty($row['cover_image_path'])
                    ? null
                    : ['path' => (string) $row['cover_image_path'], 'alt' => (string) ($row['cover_image_alt'] ?? $row['title'])],
                'published_at' => self::isoDate($row['published_at']),
                'updated_at' => self::isoDate($row['updated_at']),
            ], $rows),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed>|null The full post for the editor. */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM posts WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'excerpt' => (string) $row['excerpt'],
            'body_html' => (string) $row['body_html'],
            'cover_image_path' => $row['cover_image_path'] ?: null,
            'cover_image_alt' => $row['cover_image_alt'],
            'author_name' => (string) $row['author_name'],
            'status' => (string) $row['status'],
            'visibility' => self::visibility($row),
            'published_at' => self::isoDate($row['published_at']),
            'seo_title' => $row['seo_title'],
            'seo_description' => $row['seo_description'],
            'created_at' => self::isoDate($row['created_at']),
            'updated_at' => self::isoDate($row['updated_at']),
        ];
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM posts WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('posts', array_intersect_key($data, array_flip(self::FILLABLE)));
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $fields = array_intersect_key($data, array_flip(self::FILLABLE));
        if ($fields !== []) {
            $this->db->update('posts', $fields, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM posts WHERE id = ?', [$id]);
    }

    /** @return array{published: int, scheduled: int, drafts: int} */
    public function counts(): array
    {
        $row = $this->db->fetch(
            "SELECT
                SUM(" . self::PUBLIC . ") AS published,
                SUM(status = 'published' AND published_at > UTC_TIMESTAMP()) AS scheduled,
                SUM(status = 'draft') AS drafts
             FROM posts"
        ) ?? [];

        return [
            'published' => (int) ($row['published'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'drafts' => (int) ($row['drafts'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function summary(array $row): array
    {
        return [
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'excerpt' => (string) $row['excerpt'],
            'cover' => empty($row['cover_image_path'])
                ? null
                : ['path' => (string) $row['cover_image_path'], 'alt' => (string) ($row['cover_image_alt'] ?? $row['title'])],
            'author_name' => (string) $row['author_name'],
            'published_at' => self::isoDate($row['published_at']),
            'updated_at' => self::isoDate($row['updated_at']),
        ];
    }

    /**
     * Whether the post can be seen on the website: draft, scheduled (published with a future date) or published.
     *
     * @param array<string, mixed> $row
     */
    private static function visibility(array $row): string
    {
        if ($row['status'] !== 'published' || $row['published_at'] === null) {
            return 'draft';
        }

        return strtotime($row['published_at'] . ' UTC') > time() ? 'scheduled' : 'published';
    }

    private static function isoDate(mixed $value): ?string
    {
        return $value === null ? null : gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));
    }
}
