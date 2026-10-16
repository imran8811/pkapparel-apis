<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderPdfs;
use App\Services\OrderService;
use App\Services\PaymentReview;

/**
 * The payment confirmation queue. Admins and sales staff see it; only admins confirm, reject or
 * record payments (owner decision 2026-10-16).
 */
final class PaymentController extends AdminController
{
    public function __construct(
        private readonly OrderPayment $payments,
        private readonly Order $orders,
        private readonly PaymentReview $review,
        private readonly OrderService $service,
        private readonly OrderPdfs $pdfs,
    ) {
    }

    /** GET /v1/admin/payments?status=submitted|confirmed|rejected&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $status = (string) (Validator::make($request->allQuery(), ['status' => 'nullable|in:submitted,confirmed,rejected'])->validate()['status'] ?? 'submitted');
        [$page, $perPage] = $this->pagination($request, 25);

        $result = $this->payments->queue($status, $page, $perPage);
        $items = [];
        foreach ($result['items'] as $payment) {
            $order = $this->orders->find($payment['order_id']);
            if ($order === null) {
                continue;
            }
            $amounts = OrderService::amounts($order, $this->orders->addons($order['id']));
            $items[] = OrderService::presentPayment($payment, true) + [
                'order' => [
                    'id' => $order['id'],
                    'order_no' => $order['order_no'],
                    'status' => $order['status'],
                    'company_name' => $order['customer']['company_name'] ?? '',
                    'country_code' => $order['customer']['country_code'] ?? '',
                    'deposit_cents' => $amounts['deposit_cents'],
                    'total_cents' => $amounts['total_cents'],
                    'paid_cents' => $amounts['paid_cents'],
                    'due_now_cents' => $amounts['due_now_cents'],
                ],
            ];
        }

        return Response::data($items, self::paginationMeta($page, $perPage, $result['total']) + [
            'counts' => [
                'submitted' => $this->payments->countByStatus('submitted'),
                'confirmed' => $this->payments->countByStatus('confirmed'),
                'rejected' => $this->payments->countByStatus('rejected'),
            ],
            'can_review' => $this->staff($request)['role'] === 'admin',
        ]);
    }

    /** POST /v1/admin/payments/{id}/confirm {received_cents, received_on, start_anyway} (admins only) */
    public function confirm(Request $request): Response
    {
        $this->authorize($request);
        $data = Validator::make($request->all(), [
            'received_cents' => 'required|int|min:1|max:1000000000',
            'received_on' => 'required|date',
            'start_anyway' => 'nullable|bool',
        ])->validate();

        return Response::data($this->review->confirm($this->id($request), $this->staff($request), $data['received_cents'], $data['received_on'], (bool) ($data['start_anyway'] ?? false)));
    }

    /** POST /v1/admin/payments/{id}/reject {reason} (admins only) */
    public function reject(Request $request): Response
    {
        $this->authorize($request);
        $data = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500'])->validate();

        return Response::data($this->review->reject($this->id($request), $this->staff($request), $data['reason']));
    }

    /** POST /v1/admin/orders/{id}/payments: a transfer the buyer did not report (admins only). */
    public function record(Request $request): Response
    {
        $this->authorize($request);
        $order = $this->orders->find($this->id($request));
        if ($order === null) {
            throw HttpException::notFound('This order does not exist.');
        }
        $data = Validator::make($request->all(), [
            'transaction_id' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \/._#-]*$/'],
            'amount_cents' => 'required|int|min:1|max:1000000000',
            'transferred_on' => 'required|date',
            'received_on' => 'nullable|date',
            'payer_name' => 'required|string|min:2|max:120',
            'payer_bank' => 'required|string|min:2|max:120',
            'notes' => 'nullable|string|max:1000',
            'start_anyway' => 'nullable|bool',
        ])->validate();

        return Response::data($this->review->record($order, $this->staff($request), $data), [], 201);
    }

    /** GET /v1/admin/payments/{id}/receipt: the receipt PDF of a confirmed payment. */
    public function receipt(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $payment = $this->payments->find($this->id($request));
        $order = $payment === null ? null : $this->orders->find($payment['order_id']);
        if ($payment === null || $order === null || $payment['receipt_no'] === null) {
            throw HttpException::notFound('This receipt does not exist.');
        }
        $pdf = $this->pdfs->receipt($this->service->present($order, false), OrderService::presentPayment($payment, true));

        return Response::file($pdf, 'application/pdf', OrderPdfs::receiptFilename($payment['receipt_no']));
    }
}
