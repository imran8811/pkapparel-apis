<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderService;
use App\Services\PaymentSlips;
use App\Services\ProformaInvoice;

/**
 * Orders in the admin (Phase 3: read only). Status changes, final freight and the payment
 * confirmation queue come in Phase 4.
 */
final class OrderController extends AdminController
{
    public function __construct(
        private readonly Order $orders,
        private readonly OrderPayment $payments,
        private readonly Customer $customers,
        private readonly OrderService $service,
        private readonly ProformaInvoice $invoices,
        private readonly PaymentSlips $slips,
    ) {
    }

    /** GET /v1/admin/orders?status=&q=&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $query = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:' . implode(',', array_keys(Order::STATUSES)),
            'q' => 'nullable|string|max:100',
        ])->validate();
        [$page, $perPage] = $this->pagination($request, 25);

        $result = $this->orders->adminList($query['status'] ?? null, $query['q'] ?? null, $page, $perPage);

        return Response::data(
            array_map(static fn (array $order): array => OrderService::summary($order, true), $result['items']),
            self::paginationMeta($page, $perPage, $result['total']) + [
                'counts' => $this->orders->countByStatus(),
                'payments_to_check' => $this->payments->countByStatus('submitted'),
            ],
        );
    }

    /** GET /v1/admin/orders/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $order = $this->order($request);
        $customer = $this->customers->find($order['customer_id']);

        return Response::data($this->service->present($order, true) + [
            'account' => $customer === null ? null : [
                'id' => (int) $customer['id'],
                'email' => (string) $customer['email'],
                'status' => (string) $customer['status'],
                'email_verified' => $customer['email_verified_at'] !== null,
            ],
        ]);
    }

    /** GET /v1/admin/orders/{id}/proforma */
    public function proforma(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        $pdf = $this->invoices->render($this->service->present($order, true), $this->service->bank());

        return Response::file($pdf, 'application/pdf', ProformaInvoice::filename($order['order_no']));
    }

    /** GET /v1/admin/payments/{id}/slip: the transfer slip a buyer attached. */
    public function slip(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $payment = $this->payments->find($this->id($request));
        $content = $payment === null || $payment['slip_path'] === null ? null : $this->slips->read((string) $payment['slip_path']);
        if ($payment === null || $content === null) {
            throw HttpException::notFound('This slip does not exist.');
        }
        $extension = pathinfo((string) $payment['slip_path'], PATHINFO_EXTENSION);
        $order = $this->orders->find($payment['order_id']);

        return Response::file($content, (string) $payment['slip_type'], sprintf('slip-%s-%d.%s', $order['order_no'] ?? 'order', $payment['id'], $extension));
    }

    /** @return array<string, mixed> */
    private function order(Request $request): array
    {
        $order = $this->orders->find($this->id($request));
        if ($order === null) {
            throw HttpException::notFound('This order does not exist.');
        }

        return $order;
    }
}
