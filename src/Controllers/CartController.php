<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CartService;

/**
 * The cart, for guests (X-Cart-Token) and buyers (Bearer token). Every answer is the freshly priced cart.
 * When a guest adds the first item, meta.cart_token holds the token for their new cart cookie.
 */
final class CartController
{
    public function __construct(private readonly CartService $carts)
    {
    }

    /** GET /v1/cart */
    public function show(Request $request): Response
    {
        [$customerId, $guestToken] = self::identity($request);

        return Response::data($this->carts->summary($this->carts->find($customerId, $guestToken)));
    }

    /** POST /v1/cart/lines {color_id, size_qty}: adds pieces to a color. */
    public function add(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'color_id' => 'required|int|min:1',
            'size_qty' => 'required|array|max:40',
        ])->validate();
        [$customerId, $guestToken] = self::identity($request);

        $cart = $this->carts->findOrCreate($customerId, $guestToken);
        $this->carts->change($cart['id'], $data['color_id'], $data['size_qty'], false);

        return Response::data($this->carts->summary($cart['id']), $cart['token'] === null ? [] : ['cart_token' => $cart['token']]);
    }

    /** PUT /v1/cart/lines/{colorId} {size_qty}: replaces a color's quantities (all zero removes it). */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), ['size_qty' => 'required|array|max:40'])->validate();
        [$customerId, $guestToken] = self::identity($request);
        $cartId = $this->carts->find($customerId, $guestToken);
        if ($cartId === null) {
            throw HttpException::notFound('Your cart is empty.');
        }

        $this->carts->change($cartId, (int) $request->param('colorId'), $data['size_qty'], true);

        return Response::data($this->carts->summary($cartId));
    }

    /** DELETE /v1/cart/lines/{colorId} */
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
