<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\OrderService;
use App\Services\StockOrders;

/**
 * The stock checkout and placing a stock order from the cart. The order itself (page, payment report, receipts,
 * cancelling) uses the buyer's order endpoints like every order (OrderController).
 */
final class StockOrderController
{
    public function __construct(private readonly StockOrders $stock, private readonly OrderService $service)
    {
    }

    /** GET /v1/account/stock-checkout */
    public function checkout(Request $request): Response
    {
        return Response::data($this->stock->checkout(AuthController::customer($request)));
    }

    /** POST /v1/account/stock-orders {delivery, address_id, cargo_company, notes, accept_terms, expected_total_cents} */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            // We book the cargo to the buyer's city (paid by the buyer on arrival), or the buyer collects the order.
            'delivery' => 'required|in:cargo,pickup',
            'address_id' => 'nullable|int|min:1',
            'cargo_company' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
            'accept_terms' => 'required|bool',
            'expected_total_cents' => 'nullable|int|min:0',
        ])->validate();
        if ($data['accept_terms'] !== true) {
            throw new ValidationException(['accept_terms' => 'Please accept the terms to place the order.']);
        }

        $order = $this->stock->place(AuthController::customer($request), $data);

        return Response::data($this->service->present($order, false), [], 201);
    }
}
