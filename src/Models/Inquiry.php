<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Contact messages, quote requests and sample requests from the website. */
final class Inquiry
{
    public const TYPES = ['contact' => 'Message', 'quote' => 'Quote request', 'sample' => 'Sample request'];

    /** Follow-up pipeline used by the sales team. */
    public const STATUSES = [
        'new' => 'New',
        'in_progress' => 'In progress',
        'quoted' => 'Quote sent',
        'won' => 'Won',
        'lost' => 'Lost',
        'closed' => 'Closed',
        'spam' => 'Spam',
    ];

    /** Statuses that still need someone's attention. */
    public const OPEN = ['new', 'in_progress', 'quoted'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $data Returns the new inquiry id. */
    public function create(array $data): int
    {
        return $this->db->insert('inquiries', [
            'type' => $data['type'],
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'message' => $data['message'],
            'details' => empty($data['details']) ? null : json_encode($data['details'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'source_url' => $data['source_url'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ]);
    }

    public static function reference(int $id): string
    {
        return sprintf('INQ-%05d', $id);
    }

    // ----- Admin -----

    /**
     * @param array{status?: ?string, type?: ?string, q?: ?string} $filters status "open" means new + in progress + quoted
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(array $filters, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];

        $status = $filters['status'] ?? null;
        if ($status === 'open') {
            $where[] = 'i.status IN (' . implode(', ', array_fill(0, count(self::OPEN), '?')) . ')';
            array_push($params, ...self::OPEN);
        } elseif ($status !== null) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if (!empty($filters['type'])) {
            $where[] = 'i.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['q'])) {
            $search = (string) $filters['q'];
            if (preg_match('/^INQ-?0*(\d+)$/i', $search, $m) === 1) {
                $where[] = 'i.id = ?';
                $params[] = (int) $m[1];
            } else {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $where[] = '(i.name LIKE ? OR i.email LIKE ? OR i.company LIKE ? OR i.message LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
        }
        $condition = implode(' AND ', $where);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM inquiries i WHERE {$condition}", $params);
        $rows = $this->db->fetchAll(
            "SELECT i.id, i.type, i.name, i.company, i.email, i.country_code, i.message, i.status, i.created_at
             FROM inquiries i WHERE {$condition}
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params,
        );

        return ['items' => array_map([self::class, 'summary'], $rows), 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, type, name, company, email, country_code, message, status, created_at
             FROM inquiries ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
        );

        return array_map([self::class, 'summary'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT i.*, s.name AS handled_by_name
             FROM inquiries i LEFT JOIN staff_users s ON s.id = i.handled_by
             WHERE i.id = ?',
            [$id],
        );
        if ($row === null) {
            return null;
        }

        return self::summary($row) + [
            'phone' => $row['phone'],
            'message' => (string) $row['message'],
            'details' => $row['details'] === null ? [] : (json_decode((string) $row['details'], true) ?: []),
            'source_url' => $row['source_url'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'notes' => $row['notes'],
            'handled_by' => $row['handled_by'] === null ? null : ['id' => (int) $row['handled_by'], 'name' => $row['handled_by_name']],
            'updated_at' => self::isoDate($row['updated_at']),
        ];
    }

    public function updateFollowUp(int $id, string $status, ?string $notes, int $staffId): void
    {
        $this->db->update('inquiries', ['status' => $status, 'notes' => $notes, 'handled_by' => $staffId], ['id' => $id]);
    }

    /** @return array<string, int> Number of inquiries per status (every status is present). */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS total FROM inquiries GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countSince(int $days): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM inquiries WHERE status <> 'spam' AND created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY",
            [$days],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function summary(array $row): array
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', (string) $row['message']));

        return [
            'id' => (int) $row['id'],
            'reference' => self::reference((int) $row['id']),
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'company' => $row['company'],
            'email' => (string) $row['email'],
            'country_code' => $row['country_code'],
            'preview' => mb_strlen($message) > 140 ? rtrim(mb_substr($message, 0, 140)) . '…' : $message,
            'status' => (string) $row['status'],
            'created_at' => self::isoDate($row['created_at']),
        ];
    }

    private static function isoDate(string $value): string
    {
        return gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));
    }
}
