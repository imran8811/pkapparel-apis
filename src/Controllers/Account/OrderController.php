<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderPayment;
use App\Models\OrderPhoto;
use App\Services\OrderDocuments;
use App\Services\OrderPdfs;
use App\Services\OrderService;
use App\Services\Production;

/**
 * Checkout and the buyer's own orders: list, detail, documents (proforma invoice, balance invoice,
 * receipts, files from staff), production photos, cancel, report a payment.
 */
final class OrderController
{
    public function __construct(
        private readonly Order $orders,
        private readonly OrderService $service,
        private readonly OrderPdfs $pdfs,
        private readonly OrderPayment $payments,
        private readonly OrderDocument $documents,
        private readonly OrderDocuments $documentFiles,
        private readonly OrderPhoto $photos,
        private readonly Production $production,
    ) {
    }

    /** GET /v1/account/orders/{orderNo}/invoice: the balance invoice, once the order is ready to ship. */
    public function invoice(Request $request): Response
    {
        $order = $this->order($request);
        if ($order['balance_invoice_no'] === null) {
            throw HttpException::notFound('The invoice for the balance is issued when your order is ready to ship.');
        }
        $pdf = $this->pdfs->balanceInvoice($this->service->present($order, false), $this->service->bank());

        return Response::file($pdf, 'application/pdf', OrderPdfs::invoiceFilename($order['balance_invoice_no']));
    }

    /** GET /v1/account/orders/{orderNo}/receipts/{receiptNo} */
    public function receipt(Request $request): Response
    {
        $order = $this->order($request);
        $payment = $this->payments->findByReceipt($order['id'], (string) $request->param('receiptNo'));
        if ($payment === null) {
            throw HttpException::notFound('This receipt does not exist.');
        }
        $pdf = $this->pdfs->receipt($this->service->present($order, false), OrderService::presentPayment($payment, true, $order['kind']));

        return Response::file($pdf, 'application/pdf', OrderPdfs::receiptFilename((string) $payment['receipt_no']));
    }

    /** GET /v1/account/orders/{orderNo}/documents/{id}: a file staff shared with the buyer. */
    public function document(Request $request): Response
    {
        $order = $this->order($request);
        $document = $this->documents->find((int) $request->param('id'));
        if ($document === null || $document['order_id'] !== $order['id'] || !$document['is_public']) {
            throw HttpException::notFound('This document does not exist.');
        }
        $content = $this->documentFiles->content($document);
        if ($content === null) {
            throw HttpException::notFound('This file is missing.');
        }

        return Response::file($content, $document['mime'], OrderDocuments::filename($document, $order['order_no']));
    }

    /** GET /v1/account/orders/{orderNo}/photos/{id}?size=preview|full: a production photo of this order. */
    public function photo(Request $request): Response
    {
        $order = $this->order($request);
        $photo = $this->photos->find((int) $request->param('id'));
        if ($photo === null || $photo['order_id'] !== $order['id']) {
            throw HttpException::notFound('This photo does not exist.');
        }
        $content = $this->production->photoFile($photo, $request->query('size') !== 'full');
        if ($content === null) {
            throw HttpException::notFound('This photo file is missing.');
        }

        return Response::file($content, 'image/jpeg', "{$order['order_no']}-photo-{$photo['id']}.jpg", true);
    }

    /** GET /v1/account/checkout?address_id=&country= */
    public function checkout(Request $request): Response
    {
        $query = Validator::make($request->allQuery(), [
            'address_id' => 'nullable|int|min:1',
            'country' => 'nullable|country',
        ])->validate();

        return Response::data($this->service->checkout(AuthController::customer($request), $query['address_id'] ?? null, $query['country'] ?? null));
    }

    /** POST /v1/account/orders: place the order from the cart. */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'address_id' => 'required|int|min:1',
            // The courier is for sample orders (SampleOrderController).
            'shipping_mode' => 'required|in:air,sea,forwarder',
            'forwarder_name' => 'nullable|string|max:120',
            'forwarder_contact' => 'nullable|string|max:300',
            'addons' => 'nullable|array|max:20',
            'buyer_reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:2000',
            'accept_terms' => 'required|bool',
            'expected_total_cents' => 'nullable|int|min:0',
        ])->validate();
        if ($data['accept_terms'] !== true) {
            throw new ValidationException(['accept_terms' => 'Please accept the terms of sale to place the order.']);
        }

        $order = $this->service->place(AuthController::customer($request), $data);

        return Response::data($this->service->present($order, false), [], 201);
    }

    /** GET /v1/account/orders?page= */
    public function index(Request $request): Response
    {
        $page = (int) (Validator::make($request->allQuery(), ['page' => 'nullable|int|min:1|max:1000'])->validate()['page'] ?? 1);
        $perPage = 20;
        $result = $this->orders->forCustomer((int) AuthController::customer($request)['id'], $page, $perPage);

        return Response::data(
            array_map(static fn (array $order): array => OrderService::summary($order, false), $result['items']),
            ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total'], 'pages' => (int) ceil($result['total'] / $perPage)]],
        );
    }

    /** GET /v1/account/orders/{orderNo} */
    public function show(Request $request): Response
    {
        return Response::data($this->service->present($this->order($request), false));
    }

    /** GET /v1/account/orders/{orderNo}/proforma: the PI as a PDF. */
    public function proforma(Request $request): Response
    {
        $order = $this->order($request);
        if ($order['status'] === 'cancelled') {
            throw new HttpException(409, 'order_cancelled', 'This order was cancelled, so it has no proforma invoice.');
        }
        if ($order['status'] === 'awaiting_price') {
            throw new HttpException(409, 'not_priced', 'We are still pricing this sample order. The invoice follows by email.');
        }
        $pdf = $this->pdfs->proforma($this->service->present($order, false), $this->service->bank());

        return Response::file($pdf, 'application/pdf', OrderPdfs::proformaFilename($order['order_no'], $order['kind']));
    }

    /** POST /v1/account/orders/{orderNo}/cancel {reason} */
    public function cancel(Request $request): Response
    {
        $data = Validator::make($request->all(), ['reason' => 'nullable|string|max:500'])->validate();
        $order = $this->order($request);
        $this->service->cancelByCustomer($order, (int) AuthController::customer($request)['id'], $data['reason'] ?? null);

        return Response::data($this->service->present((array) $this->orders->find($order['id']), false));
    }

    /** POST /v1/account/orders/{orderNo}/payments (multipart, with an optional "slip" file) */
    public function submitPayment(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'transaction_id' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \/._#-]*$/'],
            'amount_cents' => 'required|int|min:1|max:1000000000',
            'transferred_on' => 'required|date',
            'payer_name' => 'required|string|min:2|max:120',
            'payer_bank' => 'required|string|min:2|max:120',
            'notes' => 'nullable|string|max:1000',
        ])->validate();
        $order = $this->order($request);
        $slip = $request->files()['slip'] ?? null;

        $payment = $this->service->submitPayment($order, (int) AuthController::customer($request)['id'], $data, is_array($slip) ? $slip : null);

        return Response::data(['payment' => $payment, 'order' => $this->service->present((array) $this->orders->find($order['id']), false)], [], 201);
    }

    /** @return array<string, mixed> The order, when it belongs to the logged-in buyer. */
    private function order(Request $request): array
    {
        $order = $this->orders->findForCustomer((int) AuthController::customer($request)['id'], (string) $request->param('orderNo'));
        if ($order === null) {
            throw HttpException::notFound('This order does not exist.');
        }

        return $order;
    }
}
