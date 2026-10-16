<?php

declare(strict_types=1);

namespace App\Core;

/** Read-only configuration with dot-notation access, e.g. get('db.host'). */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }
}
