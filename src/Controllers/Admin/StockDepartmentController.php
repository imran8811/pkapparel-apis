<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\StockDepartment;
use App\Services\WebRevalidator;

/** /v1/admin/stock/departments: Men, Women, Boys… (a department's listings are at /stock/{slug}). */
final class StockDepartmentController extends StockSectionController
{
    public function __construct(StockDepartment $departments, WebRevalidator $revalidator)
    {
        parent::__construct($departments, $revalidator);
    }

    protected function label(): string
    {
        return 'department';
    }

    protected function slugProblem(string $slug): ?string
    {
        return in_array($slug, StockDepartment::RESERVED, true) ? "/stock/{$slug} is one of the stock pages: choose another web address." : null;
    }

    protected function deleteProblem(array $section): ?string
    {
        return $section['slug'] === StockDepartment::HOME ? "{$section['name']} is listed at /stock itself, so it stays." : null;
    }
}
