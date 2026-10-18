<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Old addresses that lead to a page of the site (or another website). The website follows them with a permanent
 * redirect when nothing else lives at the address. Paths are stored decoded, without a trailing slash; the
 * collation makes them case-insensitive. The redirect index on to_path is for staff searching the list.
 */
final class Redirect
{
    public const SOURCES = ['staff' => 'Added by staff', 'style' => 'Style address changed', 'post' => 'Post address changed'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array{from: string, to: string}> every redirect, for the website */
    public function map(): array
    {
        return array_map(
            static fn (array $row): array => ['from' => (string) $row['from_path'], 'to' => (string) $row['to_path']],
            $this->db->fetchAll('SELECT from_path, to_path FROM redirects ORDER BY id'),
        );
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function adminList(?string $search, int $page, int $perPage): array
    {
        $where = $search === null ? '1 = 1' : '(from_path LIKE ? OR to_path LIKE ?)';
        $params = $search === null ? [] : ['%' . $search . '%', '%' . $search . '%'];
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM redirects WHERE {$where}", $params);
        $rows = $this->db->fetchAll(
            "SELECT r.*, s.name AS staff_name FROM redirects r LEFT JOIN staff_users s ON s.id = r.created_by
             WHERE {$where} ORDER BY r.updated_at DESC, r.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map(self::decode(...), $rows), 'total' => $total];
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM redirects');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT r.*, s.name AS staff_name FROM redirects r LEFT JOIN staff_users s ON s.id = r.created_by WHERE r.id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null the redirect from this path (case-insensitive) */
    public function findFrom(string $path): ?array
    {
        $row = $this->db->fetch('SELECT r.*, NULL AS staff_name FROM redirects r WHERE r.from_path = ?', [$path]);

        return $row === null ? null : self::decode($row);
    }

    public function create(string $from, string $to, string $source, ?int $staffId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->db->insert('redirects', [
            'from_path' => $from,
            'to_path' => $to,
            'source' => $source,
            'created_by' => $staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $id, string $from, string $to): void
    {
        $this->db->update('redirects', ['from_path' => $from, 'to_path' => $to, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM redirects WHERE id = ?', [$id]) > 0;
    }

    public function deleteFrom(string $path): int
    {
        return $this->db->execute('DELETE FROM redirects WHERE from_path = ?', [$path]);
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
            'from_path' => (string) $row['from_path'],
            'to_path' => (string) $row['to_path'],
            'source' => (string) $row['source'],
            'source_label' => self::SOURCES[$row['source']] ?? 'Added by staff',
            'created_by' => $row['staff_name'] === null ? null : (string) $row['staff_name'],
            'created_at' => $date($row['created_at']),
            'updated_at' => $date($row['updated_at']),
        ];
    }
}
