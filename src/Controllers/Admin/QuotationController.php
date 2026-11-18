<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Format;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Quotation;
use App\Services\Quotations;

/**
 * /v1/admin/quotations: quotations staff make when a buyer sends artwork, sizes and colors and asks for prices
 * (sales staff and admins). Each style is costed per piece; the buyer's PDF shows the prices, the costing sheet how
 * they were made.
 */
final class QuotationController extends AdminController
{
    public function __construct(private readonly Quotation $quotations, private readonly Quotations $service)
    {
    }

    /** GET /v1/admin/quotations?status=open|expired|accepted|declined&q=&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');
        [$page, $perPage] = $this->pagination($request, 30);
        $query = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:open,expired,accepted,declined',
            'q' => 'nullable|string|max:120',
        ])->validate();
        $today = Format::local()->format('Y-m-d');
        $result = $this->quotations->adminList($query['status'] ?? null, $query['q'] ?? null, $today, $page, $perPage);

        return Response::data(
            array_map($this->service->present(...), $result['items']),
            self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->quotations->counts($today)],
        );
    }

    /** GET /v1/admin/quotations/defaults: what a new quotation starts from (dates, terms, the latest exchange rates). */
    public function defaults(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->defaults());
    }

    /** POST /v1/admin/quotations {customer_id?, inquiry_id?, buyer{…}, currency, exchange_rate?, issue_date, valid_until?, styles[…], …} */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->create($request->all(), $this->staff($request)), [], 201);
    }

    /** GET /v1/admin/quotations/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->present($this->quotation($request)));
    }

    /** PUT /v1/admin/quotations/{id}: the same fields as when creating. Open quotations only. */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->update($this->quotation($request), $request->all()));
    }

    /** GET /v1/admin/quotations/{id}/pdf: the buyer's quotation (prices only). */
    public function pdf(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $quotation = $this->quotation($request);

        return Response::file($this->service->pdf($quotation), 'application/pdf', Quotations::filename($quotation));
    }

    /** GET /v1/admin/quotations/{id}/costing: the costing sheet, for staff. */
    public function costing(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $quotation = $this->quotation($request);

        return Response::file($this->service->costingSheet($quotation), 'application/pdf', Quotations::costingFilename($quotation));
    }

    /** POST /v1/admin/quotations/{id}/send {to, message?}: emails the quotation's PDF to the buyer. */
    public function send(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->send($this->quotation($request), $request->all(), $this->staff($request)));
    }

    /** POST /v1/admin/quotations/{id}/status {status: open|accepted|declined} */
    public function status(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->changeStatus($this->quotation($request), $request->all()));
    }

    /** POST /v1/admin/quotations/{id}/duplicate: a new quotation from this one (e.g. a revised offer). */
    public function duplicate(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->duplicate($this->quotation($request), $this->staff($request)), [], 201);
    }

    /** @return array<string, mixed> */
    private function quotation(Request $request): array
    {
        return $this->service->findOrFail($this->id($request));
    }
}
