<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Production photos of an order (private files with a preview copy). */
final class OrderPhoto
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(int $orderId, array $data): int
    {
        return $this->db->insert('order_photos', ['order_id' => $orderId] + $data);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM order_photos WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @return list<array<string, mixed>> Oldest first. */
    public function forOrder(int $orderId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM order_photos WHERE order_id = ? ORDER BY created_at, id', [$orderId]);

        return array_map(static fn (array $row): array => self::decode($row), $rows);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM order_photos WHERE id = ?', [$id]);
    }

    /**
     * What the website needs: the files themselves are served through the API by id.
     *
     * @param array<string, mixed> $photo
     * @return array{id: int, stage: ?string, caption: ?string, width: int, height: int, created_at: string}
     */
    public static function present(array $photo): array
    {
        return [
            'id' => $photo['id'],
            'stage' => $photo['stage'],
            'caption' => $photo['caption'],
            'width' => $photo['width'],
            'height' => $photo['height'],
            'created_at' => $photo['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (['id', 'order_id', 'width', 'height'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        $row['uploaded_by'] = $row['uploaded_by'] === null ? null : (int) $row['uploaded_by'];
        $row['created_at'] = gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC'));

        return $row;
    }
}
