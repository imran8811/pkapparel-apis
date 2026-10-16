<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\TokenService;

/** Buyer accounts in the admin: list, details with addresses and orders, block or unblock, staff notes. */
final class CustomerController extends AdminController
{
    public function __construct(
        private readonly Customer $customers,
        private readonly CustomerAddress $addresses,
        private readonly Order $orders,
        private readonly TokenService $tokens,
    ) {
    }

    /** GET /v1/admin/customers?status=&q=&page= */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $query = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:active,blocked,unverified',
            'q' => 'nullable|string|max:100',
        ])->validate();
        [$page, $perPage] = $this->pagination($request, 25);

        $result = $this->customers->adminList($query['status'] ?? null, $query['q'] ?? null, $page, $perPage);

        return Response::data($result['items'], self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->customers->counts()]);
    }

    /** GET /v1/admin/customers/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'sales');

        return Response::data($this->details($this->id($request)));
    }

    /** PATCH /v1/admin/customers/{id} {status, staff_notes}: blocking also ends the buyer's sessions. */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'sales');
        $id = $this->id($request);
        $customer = $this->customers->find($id);
        if ($customer === null) {
            throw HttpException::notFound('This customer does not exist.');
        }
        $data = Validator::make($request->all(), [
            'status' => 'required|in:' . implode(',', Customer::STATUSES),
            'staff_notes' => 'nullable|string|max:5000',
        ])->validate();

        $this->customers->updateByStaff($id, $data['status'], $data['staff_notes'] ?? null);
        if ($data['status'] === 'blocked') {
            $this->tokens->revokeAll('customer', $id);
        }

        return Response::data($this->details($id));
    }

    /** @return array<string, mixed> */
    private function details(int $id): array
    {
        $customer = $this->customers->find($id);
        if ($customer === null) {
            throw HttpException::notFound('This customer does not exist.');
        }
        $orders = $this->orders->forCustomer($id, 1, 50)['items'];

        return Customer::presentForStaff($customer) + [
            'addresses' => $this->addresses->forCustomer($id),
            'orders' => array_map(static fn (array $order): array => OrderService::summary($order, true), $orders),
        ];
    }
}
