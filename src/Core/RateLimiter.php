<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiting stored in the rate_limits table,
 * e.g. at most 5 inquiries per IP address per hour.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Count one attempt. Returns true when the limit was already reached (the attempt must be refused). */
    public function hit(string $bucket, int $maxAttempts, int $windowSeconds): bool
    {
        $row = $this->db->fetch('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [$bucket]);

        if ($row === null || $this->windowEnds((string) $row['window_start'], $windowSeconds) <= time()) {
            $this->db->execute(
                'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE hits = 1, window_start = VALUES(window_start)',
                [$bucket, gmdate('Y-m-d H:i:s')],
            );

            return false;
        }

        if ((int) $row['hits'] >= $maxAttempts) {
            return true;
        }

        $this->db->execute('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);

        return false;
    }

    /** Seconds until the bucket's window resets (0 when it is not limited). */
    public function retryAfter(string $bucket, int $windowSeconds): int
    {
        $start = $this->db->fetchValue('SELECT window_start FROM rate_limits WHERE bucket = ?', [$bucket]);

        return $start === null ? 0 : max(0, $this->windowEnds((string) $start, $windowSeconds) - time());
    }

    public function clear(string $bucket): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
    }

    private function windowEnds(string $windowStart, int $windowSeconds): int
    {
        return (int) strtotime($windowStart . ' UTC') + $windowSeconds;
    }
}
