<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\StockCartService;

/**
 * The stock cart, for guests (X-Cart-Token) and buyers (Bearer token): sets of stock colors. Every answer is the
 * freshly priced stock cart. When a guest adds the first item, meta.cart_token holds the token for their cart cookie
 * (the same cart, and cookie, as the wholesale cart).
 */
final class StockCartController
{
    public function __construct(private readonly StockCartService $carts)
    {
    }

    /** GET /v1/stock/cart */
    public function show(Request $request): Response
    {
        [$customerId, $guestToken] = self::identity($request);

        return Response::data($this->carts->summary($this->carts->find($customerId, $guestToken)));
    }

    /** POST /v1/stock/cart/lines {color_id, sets}: adds sets of a color. */
    public function add(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'color_id' => 'required|int|min:1',
            'sets' => 'required|int|min:1|max:' . StockCartService::MAX_SETS,
        ])->validate();
        [$customerId, $guestToken] = self::identity($request);

        $cart = $this->carts->findOrCreate($customerId, $guestToken);
        $this->carts->change($cart['id'], $data['color_id'], $data['sets'], false);

        return Response::data($this->carts->summary($cart['id']), $cart['token'] === null ? [] : ['cart_token' => $cart['token']]);
    }

    /** PUT /v1/stock/cart/lines/{colorId} {sets}: sets a color's number of sets (0 removes it). */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), ['sets' => 'required|int|min:0|max:' . StockCartService::MAX_SETS])->validate();
        [$customerId, $guestToken] = self::identity($request);
        $cartId = $this->carts->find($customerId, $guestToken);
        if ($cartId === null) {
            throw HttpException::notFound('Your cart is empty.');
        }

        $this->carts->change($cartId, (int) $request->param('colorId'), $data['sets'], true);

        return Response::data($this->carts->summary($cartId));
    }

    /** DELETE /v1/stock/cart/lines/{colorId} */
    public function destroy(Request $request): Response
    {
        [$customerId, $guestToken] = self::identity($request);
        $cartId = $this->carts->find($customerId, $guestToken);
        if ($cartId !== null) {
            $this->carts->remove($cartId, (int) $request->param('colorId'));
        }

        return Response::data($this->carts->summary($cartId));
    }

    /** @return array{0: ?int, 1: ?string} [buyer id, guest cart token] */
    private static function identity(Request $request): array
    {
        $customer = $request->attribute('customer');
        $token = $request->attribute('cart_token');

        return [is_array($customer) ? (int) $customer['id'] : null, is_string($token) ? $token : null];
    }
}
