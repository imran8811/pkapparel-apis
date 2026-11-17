<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\StockCategory;
use App\Services\WebRevalidator;

/**
 * /v1/admin/stock/categories: Jeans, Cotton pants… Each has the specs of a wholesale product type sold in waist sizes
 * (product_type). Changing it keeps what listings saved; fields the new type lacks are no longer shown.
 */
final class StockCategoryController extends StockSectionController
{
    public function __construct(StockCategory $categories, WebRevalidator $revalidator)
    {
        parent::__construct($categories, $revalidator);
    }

    protected function label(): string
    {
        return 'category';
    }

    protected function rules(): array
    {
        return ['product_type' => 'required|in:' . implode(',', StockCategory::productTypes())];
    }
}
