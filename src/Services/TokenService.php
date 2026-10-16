<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Opaque login tokens. The client receives a random 64-character token once;
 * the database keeps only its SHA-256 hash, so a leaked database cannot be
 * used to log in, and any token can be revoked.
 */
final class TokenService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{token: string, expires_at: string} */
    public function issue(string $userType, int $userId, int $ttlSeconds, ?string $ip = null, ?string $userAgent = null): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $this->db->insert('auth_tokens', [
            'user_type' => $userType,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);

        return ['token' => $token, 'expires_at' => gmdate(DATE_ATOM, (int) strtotime($expiresAt . ' UTC'))];
    }

    /** The user id for a valid, unexpired token, or null. */
    public function resolve(string $userType, string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $row = $this->db->fetch(
            'SELECT id, user_id, last_used_at FROM auth_tokens
             WHERE token_hash = ? AND user_type = ? AND expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token), $userType],
        );
        if ($row === null) {
            return null;
        }

        // Record activity at most every 5 minutes to keep writes low.
        if ($row['last_used_at'] === null || strtotime($row['last_used_at'] . ' UTC') < time() - 300) {
            $this->db->execute('UPDATE auth_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        }

        return (int) $row['user_id'];
    }

    public function revoke(string $token): void
    {
        $this->db->execute('DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    /** Revoke all tokens of a user, optionally keeping one (e.g. the current session). */
    public function revokeAll(string $userType, int $userId, ?string $except = null): void
    {
        $this->db->execute(
            'DELETE FROM auth_tokens WHERE user_type = ? AND user_id = ? AND token_hash <> ?',
            [$userType, $userId, $except === null ? '' : hash('sha256', $except)],
        );
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM auth_tokens WHERE expires_at <= UTC_TIMESTAMP()');
    }
}
