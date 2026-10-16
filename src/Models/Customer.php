<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Buyer accounts. Staff accounts are separate (StaffUser). */
final class Customer
{
    public const STATUSES = ['active', 'blocked'];

    public const BUSINESS_TYPES = [
        'brand' => 'Brand owner',
        'wholesaler' => 'Wholesaler',
        'retailer' => 'Retailer or store',
        'online_seller' => 'Online or Amazon seller',
        'importer' => 'Importer or distributor',
        'other' => 'Other',
    ];

    /** Profile fields the buyer can change. */
    public const PROFILE = ['company_name', 'contact_name', 'business_type', 'country_code', 'phone', 'website'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch('SELECT * FROM customers WHERE email = ?', [self::normaliseEmail($email)]);
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM customers WHERE email = ? AND id <> ?', [self::normaliseEmail($email), $exceptId ?? 0]) !== null;
    }

    /** @param array<string, mixed> $profile */
    public function create(string $email, string $password, array $profile): int
    {
        return $this->db->insert('customers', [
            'email' => self::normaliseEmail($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ] + array_intersect_key($profile, array_flip(self::PROFILE)));
    }

    /** @param array<string, mixed> $profile */
    public function updateProfile(int $id, array $profile): void
    {
        $fields = array_intersect_key($profile, array_flip(self::PROFILE));
        if ($fields !== []) {
            $this->db->update('customers', $fields, ['id' => $id]);
        }
    }

    /** A new email address must be confirmed again. */
    public function changeEmail(int $id, string $email): void
    {
        $this->db->update('customers', ['email' => self::normaliseEmail($email), 'email_verified_at' => null], ['id' => $id]);
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->db->update('customers', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
    }

    public function markVerified(int $id): void
    {
        $this->db->execute('UPDATE customers SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL', [$id]);
    }

    public function recordLogin(int $id): void
    {
        $this->db->execute('UPDATE customers SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    public function updateByStaff(int $id, string $status, ?string $notes): void
    {
        $this->db->update('customers', ['status' => $status, 'staff_notes' => $notes], ['id' => $id]);
    }

    /**
     * Admin list with order counts, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminList(?string $status, ?string $search, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status === 'unverified') {
            $where[] = 'c.email_verified_at IS NULL';
        } elseif ($status !== null) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(c.email LIKE ? OR c.company_name LIKE ? OR c.contact_name LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM customers c WHERE {$sqlWhere}", $params);
        $rows = $this->db->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count
             FROM customers c WHERE {$sqlWhere} ORDER BY c.created_at DESC, c.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params,
        );

        return ['items' => array_map(static fn (array $row): array => self::presentForStaff($row), $rows), 'total' => $total];
    }

    /** Accounts opened in the last $days days. */
    public function countSince(int $days): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM customers WHERE created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY', [$days]);
    }

    /** @return array{active: int, blocked: int, unverified: int} */
    public function counts(): array
    {
        $row = $this->db->fetch(
            "SELECT SUM(status = 'active') AS active, SUM(status = 'blocked') AS blocked, SUM(email_verified_at IS NULL) AS unverified FROM customers",
        ) ?? [];

        return ['active' => (int) ($row['active'] ?? 0), 'blocked' => (int) ($row['blocked'] ?? 0), 'unverified' => (int) ($row['unverified'] ?? 0)];
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * What the buyer sees about their own account (never the password hash or staff notes).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'email_verified' => $row['email_verified_at'] !== null,
            'company_name' => (string) $row['company_name'],
            'contact_name' => (string) $row['contact_name'],
            'business_type' => (string) $row['business_type'],
            'country_code' => (string) $row['country_code'],
            'phone' => (string) $row['phone'],
            'website' => $row['website'] === null ? null : (string) $row['website'],
            'created_at' => self::time($row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentForStaff(array $row): array
    {
        return self::present($row) + [
            'status' => (string) $row['status'],
            'staff_notes' => $row['staff_notes'] === null ? null : (string) $row['staff_notes'],
            'last_login_at' => self::time($row['last_login_at']),
            'email_verified_at' => self::time($row['email_verified_at']),
            'order_count' => isset($row['order_count']) ? (int) $row['order_count'] : null,
        ];
    }

    private static function time(mixed $value): ?string
    {
        return $value === null ? null : gmdate(DATE_ATOM, (int) strtotime($value . ' UTC'));
    }
}
