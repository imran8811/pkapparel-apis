<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies database/migrations/*.sql in file-name order, once each.
 * Applied versions are recorded in the schema_migrations table.
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /**
     * @param callable(string): void $output
     * @return int Number of migrations applied
     */
    public function run(callable $output): int
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(190) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = array_flip(array_column($this->db->fetchAll('SELECT version FROM schema_migrations'), 'version'));
        $count = 0;

        foreach ($this->files() as $file) {
            $version = basename($file, '.sql');
            if (isset($applied[$version])) {
                continue;
            }

            foreach (self::split((string) file_get_contents($file)) as $statement) {
                $this->db->exec($statement);
            }
            $this->db->execute('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
            $output("Applied {$version}");
            $count++;
        }

        return $count;
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Split a SQL script into statements on semicolons that are outside
     * quotes, back-ticks and comments.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '-' && $next === '-' || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            }
            if ($char === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statements[] = trim($current);

        return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
    }
}
