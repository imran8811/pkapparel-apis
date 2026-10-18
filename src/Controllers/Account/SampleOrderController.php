<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\OrderService;
use App\Services\SampleOrders;

/**
 * Sample orders: samples of shop styles (and a custom-design sample) paid in full before dispatch.
 *
 *   items:  [{color_id, size, qty}]  up to 10 pieces, 1–3 per color and size
 *   custom: {product, description, qty, files: [upload tokens]}  optional; staff price it first
 */
final class SampleOrderController
{
    public function __construct(private readonly SampleOrders $samples, private readonly OrderService $service)
    {
    }

    /** POST /v1/account/sample-orders/quote: prices and courier for a saved address, or for a country (a new address). */
    public function quote(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'items' => 'nullable|array|max:10',
            'custom' => 'nullable|array',
            'address_id' => 'nullable|int|min:1',
            'country' => 'nullable|country',
        ])->validate();

        return Response::data($this->samples->quote(
            AuthController::customer($request),
            $data['items'] ?? [],
            $data['custom'] ?? null,
            $data['address_id'] ?? null,
            $data['country'] ?? null,
        ));
    }

    /** POST /v1/account/sample-orders: place the sample order. */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'items' => 'nullable|array|max:10',
            'custom' => 'nullable|array',
            'address_id' => 'required|int|min:1',
            'buyer_reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:2000',
            'accept_terms' => 'required|bool',
            'expected_total_cents' => 'nullable|int|min:0',
        ])->validate();
        if ($data['accept_terms'] !== true) {
            throw new ValidationException(['accept_terms' => 'Please accept the terms of sale to place the order.']);
        }

        $order = $this->samples->place(AuthController::customer($request), $data);

        return Response::data($this->service->present($order, false), [], 201);
    }
}
