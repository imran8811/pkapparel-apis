<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Single-use links sent by email (confirm the email address, reset the password).
 * The link carries a random token; only its SHA-256 hash is stored.
 */
final class CustomerToken
{
    public const VERIFY_EMAIL = 'verify_email';
    public const RESET_PASSWORD = 'reset_password';

    public function __construct(private readonly Database $db)
    {
    }

    /** A new link replaces any unused link of the same kind. */
    public function issue(int $customerId, string $purpose, string $email, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function () use ($customerId, $purpose, $email, $ttlSeconds, $token): void {
            $this->db->execute('DELETE FROM customer_tokens WHERE customer_id = ? AND purpose = ? AND used_at IS NULL', [$customerId, $purpose]);
            $this->db->insert('customer_tokens', [
                'customer_id' => $customerId,
                'purpose' => $purpose,
                'token_hash' => hash('sha256', $token),
                'email' => $email,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            ]);
        });

        return $token;
    }

    /**
     * Uses up a valid token.
     *
     * @return array{customer_id: int, email: string}|null null when the token is unknown, used or expired
     */
    public function consume(string $purpose, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->transaction(function () use ($purpose, $token): ?array {
            $row = $this->db->fetch(
                'SELECT id, customer_id, email FROM customer_tokens
                 WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE',
                [hash('sha256', $token), $purpose],
            );
            if ($row === null) {
                return null;
            }
            $this->db->execute('UPDATE customer_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);

            return ['customer_id' => (int) $row['customer_id'], 'email' => (string) $row['email']];
        });
    }

    /** @return array{customer_id: int, email: string}|null The account of a token that was already used (verification links opened twice). */
    public function findUsed(string $purpose, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT customer_id, email FROM customer_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NOT NULL',
            [hash('sha256', $token), $purpose],
        );

        return $row === null ? null : ['customer_id' => (int) $row['customer_id'], 'email' => (string) $row['email']];
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM customer_tokens WHERE expires_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY');
    }
}
