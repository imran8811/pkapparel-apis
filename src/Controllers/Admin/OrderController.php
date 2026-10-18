<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Format;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\OrderPdfs;
use App\Services\OrderService;
use App\Services\OrderWorkflow;
use App\Services\PaymentSlips;
use App\Services\SampleOrders;

/**
 * Orders in the admin: the list (with "late" and sample filters), the order page, its PDFs, and the staff workflow
 * (hold, cancel, due date, charges, ready to ship, shipped, delivered, notes, a sample order's price). Admins and
 * sales staff run the workflow; production staff can read orders. Payments are in PaymentController, production in
 * ProductionController.
 */
final class OrderController extends AdminController
{
    public function __construct(
        private readonly Order $orders,
        private readonly OrderPayment $payments,
        private readonly Customer $customers,
        private readonly OrderService $service,
        private readonly OrderWorkflow $workflow,
        private readonly SampleOrders $samples,
        private readonly OrderPdfs $pdfs,
        private readonly PaymentSlips $slips,
    ) {
    }

    /** GET /v1/admin/orders?status=&kind=&q=&page= (status "late": production due date passed; kind: bulk or sample) */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $query = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:late,' . implode(',', array_keys(Order::STATUSES)),
            'kind' => 'nullable|in:' . implode(',', Order::KINDS),
            'q' => 'nullable|string|max:100',
        ])->validate();
        [$page, $perPage] = $this->pagination($request, 25);
        $late = ($query['status'] ?? null) === 'late';
        $kind = $query['kind'] ?? null;
        $today = Format::local()->format('Y-m-d');

        $result = $this->orders->adminList($late ? null : ($query['status'] ?? null), $query['q'] ?? null, $page, $perPage, $late ? $today : null, $kind);

        return Response::data(
            array_map(static fn (array $order): array => OrderService::summary($order, true) + [
                'production_due_on' => $order['production_due_on'],
                'production_days_left' => OrderService::daysLeft($order),
            ], $result['items']),
            self::paginationMeta($page, $perPage, $result['total']) + [
                'counts' => $this->orders->countByStatus($kind) + ['late' => $this->orders->countLate($today)],
                'samples' => array_sum($this->orders->countByStatus('sample')),
                'payments_to_check' => $this->payments->countByStatus('submitted'),
            ],
        );
    }

    /** POST /v1/admin/orders/{id}/send-price: a sample order is priced, so the buyer can pay. */
    public function sendPrice(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        $this->samples->sendPrice($order, $this->staff($request));

        return $this->fresh($order);
    }

    /** GET /v1/admin/orders/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');

        return Response::data($this->details($this->order($request)));
    }

    /** GET /v1/admin/orders/{id}/proforma */
    public function proforma(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        $pdf = $this->pdfs->proforma($this->service->present($order, true), $this->service->bank());

        return Response::file($pdf, 'application/pdf', OrderPdfs::proformaFilename($order['order_no'], $order['kind']));
    }

    /** GET /v1/admin/orders/{id}/invoice: the balance invoice, once the order is ready to ship. */
    public function invoice(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        if ($order['balance_invoice_no'] === null) {
            throw HttpException::notFound('The balance invoice is issued when the order is ready to ship.');
        }
        $pdf = $this->pdfs->balanceInvoice($this->service->present($order, true), $this->service->bank());

        return Response::file($pdf, 'application/pdf', OrderPdfs::invoiceFilename($order['balance_invoice_no']));
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

    /** POST /v1/admin/orders/{id}/hold {reason} */
    public function hold(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $data = Validator::make($request->all(), ['reason' => 'required|string|min:3|max:500'])->validate();
        $order = $this->order($request);
        $this->workflow->hold($order, $this->staff($request), $data['reason']);

        return $this->fresh($order);
    }

    /** POST /v1/admin/orders/{id}/resume */
    public function resume(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        $this->workflow->resume($order, $this->staff($request));

        return $this->fresh($order);
    }

    /** POST /v1/admin/orders/{id}/cancel {reason, notify} */
    public function cancel(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $data = Validator::make($request->all(), ['reason' => 'required|string|min:3|max:500', 'notify' => 'nullable|bool'])->validate();
        $order = $this->order($request);
        $this->workflow->cancel($order, $this->staff($request), $data['reason'], (bool) ($data['notify'] ?? true));

        return $this->fresh($order);
    }

    /** PUT /v1/admin/orders/{id}/due-date {date, reason, notify} */
    public function dueDate(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $data = Validator::make($request->all(), [
            'date' => 'required|date',
            'reason' => 'required|string|min:3|max:500',
            'notify' => 'nullable|bool',
        ])->validate();
        if ($data['date'] < Format::local()->format('Y-m-d')) {
            throw new ValidationException(['date' => 'Choose today or a later date.']);
        }
        $order = $this->order($request);
        $this->workflow->changeDueDate($order, $this->staff($request), $data['date'], $data['reason'], (bool) ($data['notify'] ?? true));

        return $this->fresh($order);
    }

    /** PUT /v1/admin/orders/{id}/charges {freight_cents|null, addons: {id: cents}} */
    public function charges(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $input = $request->all();
        $data = Validator::make($input, [
            'freight_cents' => 'nullable|int|min:0|max:1000000000',
            'addons' => 'nullable|array|max:20',
        ])->validate();
        $prices = [];
        foreach ($data['addons'] ?? [] as $addonId => $cents) {
            $value = filter_var($cents, FILTER_VALIDATE_INT);
            if (!is_numeric($addonId) || $value === false || $value < 0 || $value > 100000000) {
                throw new ValidationException(['addons' => 'Enter a price for each add-on, in dollars and cents.']);
            }
            $prices[(int) $addonId] = $value;
        }
        $order = $this->order($request);
        $clear = array_key_exists('freight_cents', $input) && $input['freight_cents'] === null;
        $this->workflow->setCharges($order, $this->staff($request), $data['freight_cents'] ?? null, $clear, $prices);

        return $this->fresh($order);
    }

    /** POST /v1/admin/orders/{id}/ready-to-ship: issues the balance invoice and emails it. */
    public function readyToShip(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        $this->workflow->readyToShip($order, $this->staff($request));

        return $this->fresh($order);
    }

    /** POST /v1/admin/orders/{id}/ship {carrier, tracking_no, tracking_url, shipped_on, cartons, gross_kg, note, unpaid_reason} */
    public function ship(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $data = Validator::make($request->all(), [
            'carrier' => 'required|string|min:2|max:120',
            'tracking_no' => 'nullable|string|max:120',
            'tracking_url' => 'nullable|url|max:500',
            'shipped_on' => 'required|date',
            'cartons' => 'nullable|int|min:1|max:100000',
            'gross_kg' => 'nullable|numeric|min:0|max:1000000',
            'note' => 'nullable|string|max:500',
            'unpaid_reason' => 'nullable|string|max:500',
        ])->validate();
        $data['gross_g'] = isset($data['gross_kg']) ? (int) round($data['gross_kg'] * 1000) : null;
        $order = $this->order($request);
        $this->workflow->ship($order, $this->staff($request), $data, $data['unpaid_reason'] ?? null);

        return $this->fresh($order);
    }

    /** POST /v1/admin/orders/{id}/deliver */
    public function deliver(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $order = $this->order($request);
        $this->workflow->deliver($order, $this->staff($request));

        return $this->fresh($order);
    }

    /** POST /v1/admin/orders/{id}/notes {note}: for the team only. Production staff may add notes too. */
    public function note(Request $request): Response
    {
        $this->authorize($request, 'sales', 'production');
        $data = Validator::make($request->all(), ['note' => 'required|string|min:2|max:2000'])->validate();
        $order = $this->order($request);
        $this->workflow->addNote($order, $this->staff($request), $data['note']);

        return $this->fresh($order);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function details(array $order): array
    {
        $customer = $this->customers->find($order['customer_id']);

        return $this->service->present($order, true) + [
            'account' => $customer === null ? null : [
                'id' => (int) $customer['id'],
                'email' => (string) $customer['email'],
                'status' => (string) $customer['status'],
                'email_verified' => $customer['email_verified_at'] !== null,
            ],
        ];
    }

    /** @param array<string, mixed> $order */
    private function fresh(array $order): Response
    {
        return Response::data($this->details((array) $this->orders->find($order['id'])));
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
