<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Format;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\StockCounts;
use App\Services\StockFromWholesale;

/**
 * Stock in bulk (editors and admins): the stock list as CSV, stock counts imported from a CSV file (a preview first,
 * then applied), the wholesale shop's jeans copied into the stock, and the stock report (sales staff may read it too).
 */
final class StockToolsController extends AdminController
{
    public function __construct(
        private readonly StockCounts $counts,
        private readonly StockFromWholesale $fromWholesale,
    ) {
    }

    /** GET /v1/admin/stock/export: every stock color with its sets in stock, as CSV. */
    public function export(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::file($this->counts->export(), 'text/csv; charset=utf-8', 'stock-' . Format::local()->format('Y-m-d') . '.csv');
    }

    /** POST /v1/admin/stock/import {csv, apply}: what the file would change; with apply true, the change itself. */
    public function import(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = Validator::make($request->all(), [
            'csv' => 'required|string|max:' . StockCounts::MAX_BYTES,
            'apply' => 'nullable|bool',
        ])->validate();

        return Response::data($this->counts->import($data['csv'], (bool) ($data['apply'] ?? false), $this->staff($request)['id']));
    }

    /** GET /v1/admin/stock/from-wholesale: the wholesale shop's jeans for men and boys, and what copying each one makes. */
    public function wholesale(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->fromWholesale->preview());
    }

    /** POST /v1/admin/stock/from-wholesale {product_ids}: copies those not in the stock yet, as stock drafts. */
    public function copyFromWholesale(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $ids = Validator::make($request->all(), ['product_ids' => 'required|array|max:500'])->validate()['product_ids'];
        if (!array_is_list($ids) || array_filter($ids, static fn (mixed $id): bool => !is_int($id) || $id < 1) !== []) {
            throw new ValidationException(['product_ids' => 'Choose the styles to copy.']);
        }

        return Response::data(['copied' => $this->fromWholesale->copy(array_values(array_unique($ids)))]);
    }

    /** GET /v1/admin/stock/report?days=30 */
    public function report(Request $request): Response
    {
        $this->authorize($request, 'editor', 'sales');
        $days = Validator::make($request->allQuery(), ['days' => 'nullable|int|min:1|max:366'])->validate()['days'] ?? 30;

        return Response::data($this->counts->report($days));
    }
}
