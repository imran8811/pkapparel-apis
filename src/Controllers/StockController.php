<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\StockCategory;
use App\Models\StockDepartment;
use App\Services\StockCatalog;

/** The stock pages of the website: ready clothes sold by set, with the sets in stock per color. */
final class StockController
{
    public function __construct(
        private readonly StockCatalog $catalog,
        private readonly StockDepartment $departments,
        private readonly StockCategory $categories,
    ) {
    }

    /** GET /v1/stock/styles?segment=men&category=jeans: every style shown, in stock first (the website filters and sorts them). */
    public function styles(Request $request): Response
    {
        $query = Validator::make($request->allQuery(), [
            'segment' => 'nullable|in:' . implode(',', $this->departments->slugs()),
            'category' => 'nullable|in:' . implode(',', $this->categories->slugs()),
        ])->validate();

        return Response::data($this->catalog->listing($query['segment'] ?? null, $query['category'] ?? null));
    }

    /** GET /v1/stock/styles/{slug} */
    public function style(Request $request): Response
    {
        $style = $this->catalog->style((string) $request->param('slug'));
        if ($style === null) {
            throw HttpException::notFound('This style does not exist.');
        }

        return Response::data($style);
    }

    /**
     * GET /v1/stock/sections: the departments and categories in staff order, for the stock pages' menus and filters
     * (the website shows those with styles).
     */
    public function sections(Request $request): Response
    {
        $pick = static fn (array $section): array => ['slug' => $section['slug'], 'name' => $section['name']];

        return Response::data([
            'departments' => array_map($pick, $this->departments->all()),
            'categories' => array_map($pick, $this->categories->all()),
        ]);
    }
}
