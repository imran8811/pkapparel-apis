<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** People who log in to the admin panel. */
final class StaffUser
{
    /** admin: everything · editor: website content · sales: inquiries, customers, orders · production: production updates */
    public const ROLES = ['admin', 'editor', 'sales', 'production'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM staff_users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch('SELECT * FROM staff_users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public function create(string $name, string $email, string $password, string $role): int
    {
        return $this->db->insert('staff_users', [
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->db->update('staff_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $id]);
    }

    /** @return list<array<string, mixed>> Active accounts first, then by name. */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM staff_users ORDER BY is_active DESC, name, id');
    }

    public function updateAccount(int $id, string $name, string $role, bool $isActive): void
    {
        $this->db->update('staff_users', ['name' => $name, 'role' => $role, 'is_active' => $isActive ? 1 : 0], ['id' => $id]);
    }

    /** 16 characters without look-alikes (0/O, 1/l/I), shown once to whoever creates or resets the account. */
    public static function generatePassword(int $length = 16): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * The staff list in the admin (never the password hash).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentForAdmin(array $row): array
    {
        return self::present($row) + [
            'is_active' => (bool) $row['is_active'],
            'last_login_at' => $row['last_login_at'] === null ? null : gmdate(DATE_ATOM, (int) strtotime($row['last_login_at'] . ' UTC')),
            'created_at' => gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC')),
        ];
    }

    public function recordLogin(int $id): void
    {
        $this->db->execute('UPDATE staff_users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    /**
     * What the website may see about a staff member (never the password hash).
     *
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, email: string, role: string}
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
        ];
    }
}
