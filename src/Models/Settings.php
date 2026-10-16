<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use JsonException;

/** Key/value site settings stored as JSON (company info, contacts, business defaults…). */
final class Settings
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> Settings that may be shown on the public website. */
    public function public(): array
    {
        $rows = $this->db->fetchAll('SELECT name, value FROM settings WHERE is_public = 1 ORDER BY name');

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['name']] = self::decode((string) $row['value']);
        }

        return $settings;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $value = $this->db->fetchValue('SELECT value FROM settings WHERE name = ?', [$name]);

        return $value === null ? $default : self::decode((string) $value);
    }

    public function set(string $name, mixed $value, bool $public = false): void
    {
        $this->db->execute(
            'INSERT INTO settings (name, value, is_public) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), is_public = VALUES(is_public)',
            [$name, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $public ? 1 : 0],
        );
    }

    private static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
