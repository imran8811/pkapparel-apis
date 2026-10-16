<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;

final class RateLimiterTest extends IntegrationTestCase
{
    private const BUCKET = 'test:rate-limiter';

    protected function tearDown(): void
    {
        (new RateLimiter($this->db))->clear(self::BUCKET);
    }

    public function testAllowsUpToTheLimitThenRefuses(): void
    {
        $limiter = new RateLimiter($this->db);
        $limiter->clear(self::BUCKET);

        self::assertFalse($limiter->hit(self::BUCKET, 3, 60));
        self::assertFalse($limiter->hit(self::BUCKET, 3, 60));
        self::assertFalse($limiter->hit(self::BUCKET, 3, 60));
        self::assertTrue($limiter->hit(self::BUCKET, 3, 60));

        $retry = $limiter->retryAfter(self::BUCKET, 60);
        self::assertGreaterThan(0, $retry);
        self::assertLessThanOrEqual(60, $retry);
    }

    public function testExpiredWindowStartsAgain(): void
    {
        $limiter = new RateLimiter($this->db);
        $limiter->clear(self::BUCKET);
        $this->db->execute(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 99, ?)',
            [self::BUCKET, gmdate('Y-m-d H:i:s', time() - 120)],
        );

        self::assertFalse($limiter->hit(self::BUCKET, 3, 60));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT hits FROM rate_limits WHERE bucket = ?', [self::BUCKET]));
    }
}
