<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideStringsAndComments(): void
    {
        $sql = <<<'SQL'
            -- a comment; with a semicolon
            CREATE TABLE a (id INT);
            /* block; comment */
            INSERT INTO a (note) VALUES ('semi;colon'), ("it\"s; fine"), ('it''s; ok');
            # hash comment;
            INSERT INTO `odd;name` VALUES (1)
            SQL;

        self::assertSame([
            'CREATE TABLE a (id INT)',
            "INSERT INTO a (note) VALUES ('semi;colon'), (\"it\\\"s; fine\"), ('it''s; ok')",
            'INSERT INTO `odd;name` VALUES (1)',
        ], Migrator::split($sql));
    }

    public function testKeepsJsonWithHashesInsideStrings(): void
    {
        $statements = Migrator::split("INSERT INTO settings VALUES ('theme', '{\"color\":\"#0B132B\"}', 1);");

        self::assertSame(["INSERT INTO settings VALUES ('theme', '{\"color\":\"#0B132B\"}', 1)"], $statements);
    }

    public function testRealMigrationFilesSplitCleanly(): void
    {
        // Database connects lazily, so listing files never opens a connection.
        $db = new Database(['host' => '127.0.0.1', 'port' => 3306, 'name' => 'unused', 'user' => 'unused', 'password' => '']);
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations');

        foreach ($migrator->files() as $file) {
            $statements = Migrator::split((string) file_get_contents($file));
            self::assertNotEmpty($statements, basename($file) . ' has no statements');
        }
    }
}
