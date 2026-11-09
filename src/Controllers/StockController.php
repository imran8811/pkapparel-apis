<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\StockStyle;
use App\Services\StockCatalog;

/** The stock pages of the website: ready jeans sold by set, with the sets in stock per color. */
final class StockController
{
    public function __construct(private readonly StockCatalog $catalog)
    {
    }

    /** GET /v1/stock/styles?segment=men: every style shown, in stock first (the website filters and sorts them). */
    public function styles(Request $request): Response
    {
        $query = Validator::make($request->allQuery(), [
            'segment' => 'nullable|in:' . implode(',', StockStyle::SEGMENTS),
        ])->validate();

        return Response::data($this->catalog->listing($query['segment'] ?? null));
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
}
