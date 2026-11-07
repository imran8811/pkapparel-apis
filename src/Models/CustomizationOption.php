<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Private-label add-ons offered at checkout (labels, tags, patches, packaging…).
 * An add-on can be limited to some product types (e.g. a back patch only for denim and twill); none means every type.
 */
final class CustomizationOption extends Repository
{
    /** per_piece: price × every piece of the order · per_order: once · on_request: quoted later, paid with the balance */
    public const PRICING = ['per_piece', 'per_order', 'on_request'];

    protected const TABLE = 'customization_options';

    protected const FILLABLE = ['name', 'description', 'pricing', 'price_cents', 'product_types', 'is_active', 'sort_order'];

    /** @return list<array<string, mixed>> Options buyers can choose. */
    public function active(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM customization_options WHERE is_active = 1 ORDER BY sort_order, id');

        return array_map(static fn (array $row): array => self::present($row), $rows);
    }

    /**
     * Active options that fit at least one of these product types.
     *
     * @param list<string> $types
     * @return list<array<string, mixed>>
     */
    public function activeFor(array $types): array
    {
        return array_values(array_filter(
            $this->active(),
            static fn (array $option): bool => $option['product_types'] === [] || array_intersect($option['product_types'], $types) !== [],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(static fn (array $row): array => self::present($row), parent::all());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = parent::find($id);

        return $row === null ? null : self::present($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fillable(array $data): array
    {
        $fields = parent::fillable($data);
        if (array_key_exists('product_types', $fields)) {
            $types = array_values($fields['product_types'] ?? []);
            $fields['product_types'] = $types === [] ? null : json_encode($types, JSON_THROW_ON_ERROR);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $types = json_decode((string) ($row['product_types'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'pricing' => (string) $row['pricing'],
            'price_cents' => $row['pricing'] === 'on_request' || $row['price_cents'] === null ? null : (int) $row['price_cents'],
            // Empty: offered for every product type.
            'product_types' => is_array($types) ? array_values($types) : [],
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
