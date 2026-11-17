<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Stock categories: Jeans, Cotton pants… Each has the details (specs) of one of the wholesale shop's product types: its
 * listings show those specs (e.g. "Finish" for cotton pants where jeans have "Wash"). Its slug is kept in
 * stock_styles.category and filters a department's listings (?category=cotton-pants).
 */
final class StockCategory extends StockSection
{
    public const SLUG_MAX = 40;

    protected const TABLE = 'stock_categories';

    protected const FILLABLE = ['slug', 'name', 'product_type', 'sort_order'];

    protected const STYLE_COLUMN = 'category';

    /**
     * The product types whose specs a category can have: those sold in waist sizes, as every stock set is.
     *
     * @return list<string>
     */
    public static function productTypes(): array
    {
        return array_values(array_filter(
            ProductTypes::slugs(),
            static fn (string $type): bool => in_array('waist', ProductTypes::sizeSystems($type), true),
        ));
    }

    /** @return array<string, mixed>|null The first category, in staff order, with this product type's specs. */
    public function firstOfType(string $productType): ?array
    {
        $row = $this->db->fetch('SELECT * FROM stock_categories WHERE product_type = ? ORDER BY sort_order, id LIMIT 1', [$productType]);

        return $row === null ? null : static::cast($row);
    }
}
