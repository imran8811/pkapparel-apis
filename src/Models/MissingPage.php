<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Addresses that showed the "page not found" page, with how often, so staff can redirect them or ignore them. */
final class MissingPage
{
    /** Open entries not seen for this long are deleted by the daily job. */
    public const KEEP_DAYS = 90;

    public function __construct(private readonly Database $db)
    {
    }

    /** Counts one more visit to a missing address (a new row the first time). */
    public function record(string $path, ?string $referrer): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO missing_pages (path, hits, last_referrer, first_seen_at, last_seen_at) VALUES (?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen_at = VALUES(last_seen_at), last_referrer = COALESCE(VALUES(last_referrer), last_referrer)',
            [$path, $referrer, $now, $now],
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} most visited first */
    public function adminList(bool $ignored, int $page, int $perPage): array
    {
        $where = $ignored ? 'ignored_at IS NOT NULL' : 'ignored_at IS NULL';
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM missing_pages WHERE {$where}");
        $rows = $this->db->fetchAll(
            "SELECT * FROM missing_pages WHERE {$where} ORDER BY hits DESC, last_seen_at DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
        );

        return ['items' => array_map(self::decode(...), $rows), 'total' => $total];
    }

    /** @return array{open: int, ignored: int} */
    public function counts(): array
    {
        $row = $this->db->fetch('SELECT SUM(ignored_at IS NULL) AS open_count, SUM(ignored_at IS NOT NULL) AS ignored_count FROM missing_pages');

        return ['open' => (int) ($row['open_count'] ?? 0), 'ignored' => (int) ($row['ignored_count'] ?? 0)];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM missing_pages WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    public function setIgnored(int $id, bool $ignored): void
    {
        $this->db->update('missing_pages', ['ignored_at' => $ignored ? gmdate('Y-m-d H:i:s') : null], ['id' => $id]);
    }

    /** The address now redirects somewhere: it is no longer missing. */
    public function resolve(string $path): int
    {
        return $this->db->execute('DELETE FROM missing_pages WHERE path = ?', [$path]);
    }

    /** @return int open entries deleted because nobody has hit them since $before */
    public function prune(string $before): int
    {
        return $this->db->execute('DELETE FROM missing_pages WHERE ignored_at IS NULL AND last_seen_at < ?', [$before]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $date = static fn (?string $value): ?string => $value === null ? null : gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));

        return [
            'id' => (int) $row['id'],
            'path' => (string) $row['path'],
            'hits' => (int) $row['hits'],
            'last_referrer' => $row['last_referrer'],
            'first_seen_at' => $date($row['first_seen_at']),
            'last_seen_at' => $date($row['last_seen_at']),
            'ignored' => $row['ignored_at'] !== null,
        ];
    }
}
