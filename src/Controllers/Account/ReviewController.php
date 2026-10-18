<?php

declare(strict_types=1);

namespace App\Controllers\Account;

use App\Controllers\ReviewController as PublicReviewController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Order;
use App\Services\Reviews;

/** The review of a delivered order, written from the buyer's order page. */
final class ReviewController
{
    public function __construct(private readonly Order $orders, private readonly Reviews $reviews)
    {
    }

    /** GET /v1/account/orders/{orderNo}/review */
    public function show(Request $request): Response
    {
        return Response::data($this->reviews->forOrder($this->order($request)));
    }

    /** POST /v1/account/orders/{orderNo}/review {rating, body, show_as} */
    public function store(Request $request): Response
    {
        $order = $this->order($request);
        $this->reviews->submitForOrder($order, PublicReviewController::input($request));

        return Response::data($this->reviews->forOrder($order), [], 201);
    }

    /** @return array<string, mixed> */
    private function order(Request $request): array
    {
        $customer = AuthController::customer($request);

        return $this->orders->findForCustomer((int) $customer['id'], (string) $request->param('orderNo'))
            ?? throw HttpException::notFound('This order does not exist.');
    }
}
