<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper. Connects lazily, always uses prepared statements,
 * stores times in UTC and runs MySQL/MariaDB in strict mode.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host: string, port: int, name: string, user: string, password: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
        );

        $pdo = new PDO($dsn, $this->config['user'], $this->config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");

        return $this->pdo = $pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<int|string, mixed> $params Returns the number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /** Run raw SQL without parameters (migrations only). */
    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    /** @param array<string, mixed> $data Returns the new row id. */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $columns = array_map(self::quoteIdentifier(...), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', self::quoteIdentifier($table), implode(', ', $columns), $placeholders);
        $this->query($sql, array_values($data));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where Column => value pairs joined with AND
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new InvalidArgumentException('Update needs both data and a WHERE condition.');
        }

        $set = implode(', ', array_map(static fn (string $c): string => self::quoteIdentifier($c) . ' = ?', array_keys($data)));
        $conditions = implode(' AND ', array_map(static fn (string $c): string => self::quoteIdentifier($c) . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::quoteIdentifier($table), $set, $conditions);

        return $this->execute($sql, [...array_values($data), ...array_values($where)]);
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier [{$name}].");
        }

        return '`' . $name . '`';
    }
}
