<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Tests that use the real local database (XAMPP). Skipped when it is not reachable. */
abstract class IntegrationTestCase extends TestCase
{
    protected App $app;

    protected Database $db;

    protected function setUp(): void
    {
        $this->app = App::create(dirname(__DIR__, 2));
        $this->db = $this->app->container->get(Database::class);

        try {
            $this->db->fetchValue('SELECT 1');
        } catch (Throwable $e) {
            self::markTestSkipped('Database not reachable: ' . $e->getMessage());
        }
    }
}
