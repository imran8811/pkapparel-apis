<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\ProformaInvoice;

/** Checkout and the buyer's own orders: list, detail, proforma invoice, cancel, report a payment. */
final class OrderController
{
    public function __construct(
        private readonly Order $orders,
        private readonly OrderService $service,
        private readonly ProformaInvoice $invoices,
    ) {
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
            'shipping_mode' => 'required|in:' . implode(',', array_keys(Order::MODES)),
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
        $pdf = $this->invoices->render($this->service->present($order, false), $this->service->bank());

        return Response::file($pdf, 'application/pdf', ProformaInvoice::filename($order['order_no']));
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
