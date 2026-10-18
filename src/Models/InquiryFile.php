<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Files buyers attach to requests (tech packs, spec sheets, photos), kept privately in storage/private/inquiries.
 * A file is uploaded on its own first and found by its upload token (no inquiry yet); sending the form attaches it.
 */
final class InquiryFile
{
    /** How long an upload waits for its form; the daily job deletes older ones. */
    public const PENDING_HOURS = 24;

    /** Files per quote request or sample order. */
    public const MAX_PER_REQUEST = 5;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array{path: string, name: string, type: string, size: int} $file */
    public function createPending(string $tokenHash, array $file, ?string $ip): int
    {
        return $this->db->insert('inquiry_files', [
            'upload_token_hash' => $tokenHash,
            'name' => $file['name'],
            'mime' => $file['type'],
            'size_bytes' => $file['size'],
            'path' => $file['path'],
            'ip_address' => $ip,
        ]);
    }

    /**
     * Uploads waiting for a form, found by their token hashes (attached or expired ones are left out).
     *
     * @param list<string> $tokenHashes
     * @return list<array<string, mixed>>
     */
    public function pending(array $tokenHashes): array
    {
        if ($tokenHashes === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($tokenHashes), '?'));

        return array_map([self::class, 'decode'], $this->db->fetchAll(
            "SELECT * FROM inquiry_files
             WHERE upload_token_hash IN ({$in}) AND inquiry_id IS NULL
               AND created_at >= UTC_TIMESTAMP() - INTERVAL " . self::PENDING_HOURS . ' HOUR
             ORDER BY id',
            $tokenHashes,
        ));
    }

    /** @param list<int> $ids pending uploads */
    public function attach(int $inquiryId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute("UPDATE inquiry_files SET inquiry_id = ? WHERE inquiry_id IS NULL AND id IN ({$in})", [$inquiryId, ...$ids]);
    }

    /** @return list<array<string, mixed>> */
    public function forInquiry(int $inquiryId): array
    {
        return array_map([self::class, 'decode'], $this->db->fetchAll('SELECT * FROM inquiry_files WHERE inquiry_id = ? ORDER BY id', [$inquiryId]));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM inquiry_files WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * Uploads never sent with a form within the time limit.
     *
     * @return list<array<string, mixed>>
     */
    public function stale(): array
    {
        return array_map([self::class, 'decode'], $this->db->fetchAll(
            'SELECT * FROM inquiry_files WHERE inquiry_id IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL ' . self::PENDING_HOURS . ' HOUR',
        ));
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM inquiry_files WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'inquiry_id' => $row['inquiry_id'] === null ? null : (int) $row['inquiry_id'],
            'name' => (string) $row['name'],
            'mime' => (string) $row['mime'],
            'size_bytes' => (int) $row['size_bytes'],
            'path' => (string) $row['path'],
            'created_at' => gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC')),
        ];
    }
}
