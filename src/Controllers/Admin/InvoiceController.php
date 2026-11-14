<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Format;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Invoice;
use App\Services\Invoices;

/**
 * /v1/admin/invoices: invoices staff make for buyers who order outside the website (sales staff and admins).
 * Sample invoices, proforma invoices for production and final invoices, as a PDF with the company and bank details,
 * downloaded or emailed to the buyer.
 */
final class InvoiceController extends AdminController
{
    public function __construct(private readonly Invoice $invoices, private readonly Invoices $service)
    {
    }

    /** GET /v1/admin/invoices?status=open|overdue|paid|cancelled&type=sample|proforma|commercial&q=&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');
        [$page, $perPage] = $this->pagination($request, 30);
        $query = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:open,overdue,paid,cancelled',
            'type' => 'nullable|in:' . implode(',', array_keys(Invoice::TYPES)),
            'q' => 'nullable|string|max:120',
        ])->validate();
        $today = Format::local()->format('Y-m-d');
        $result = $this->invoices->adminList($query['status'] ?? null, $query['type'] ?? null, $query['q'] ?? null, $today, $page, $perPage);

        return Response::data(
            array_map($this->service->present(...), $result['items']),
            self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->invoices->counts($today)],
        );
    }

    /** GET /v1/admin/invoices/defaults: what a new invoice starts from (terms, deposit, dates, bank details ready). */
    public function defaults(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->defaults());
    }

    /** POST /v1/admin/invoices {type, customer_id?, buyer{…}, consignee?{…}, currency, issue_date, due_date?, lines[…], …} */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->create($request->all(), $this->staff($request)), [], 201);
    }

    /** GET /v1/admin/invoices/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->present($this->invoice($request)));
    }

    /** PUT /v1/admin/invoices/{id}: the same fields as when creating (the type stays). Open invoices only. */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->update($this->invoice($request), $request->all()));
    }

    /** GET /v1/admin/invoices/{id}/pdf */
    public function pdf(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $invoice = $this->invoice($request);

        return Response::file($this->service->pdf($invoice), 'application/pdf', Invoices::filename($invoice));
    }

    /** POST /v1/admin/invoices/{id}/send {to, message?}: emails the PDF with the bank details. */
    public function send(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->send($this->invoice($request), $request->all()));
    }

    /** POST /v1/admin/invoices/{id}/paid {paid_on, payment_reference?} */
    public function paid(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->markPaid($this->invoice($request), $request->all()));
    }

    /** POST /v1/admin/invoices/{id}/unpaid: undoes "paid" (e.g. marked by mistake). */
    public function unpaid(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->markUnpaid($this->invoice($request)));
    }

    /** POST /v1/admin/invoices/{id}/cancel: the invoice keeps its number and stays in the list as cancelled. */
    public function cancel(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->service->cancel($this->invoice($request)));
    }

    /** POST /v1/admin/invoices/{id}/duplicate {type?}: a new invoice from this one (e.g. the proforma after samples). */
    public function duplicate(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $data = Validator::make($request->all(), ['type' => 'nullable|in:' . implode(',', array_keys(Invoice::TYPES))])->validate();

        return Response::data($this->service->duplicate($this->invoice($request), $data['type'] ?? null, $this->staff($request)), [], 201);
    }

    /** @return array<string, mixed> */
    private function invoice(Request $request): array
    {
        return $this->service->findOrFail($this->id($request));
    }
}
