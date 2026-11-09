<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\StockColor;
use App\Models\StockStyle;
use PDOException;
use Throwable;

/**
 * Stock orders (owner decisions 2026-11-09): stock styles ordered by set from the cart, in rupees, paid in full before
 * dispatch. The order goes by cargo to the buyer's city (booked by us, paid by the buyer on arrival) or the buyer
 * collects it. Payment reports, receipts and the order history work as for the other orders (kind "stock").
 *
 * The stock is not held for an order: its sets are taken out of stock when the payment is confirmed (deduct), which
 * fails when a color no longer has them. Cancelling a confirmed order puts its sets back (restock).
 */
final class StockOrders
{
    public function __construct(
        private readonly Database $db,
        private readonly Order $orders,
        private readonly Cart $carts,
        private readonly StockCartService $stockCarts,
        private readonly CustomerAddress $addresses,
        private readonly StockColor $colors,
        private readonly StockLevels $levels,
        private readonly StockSettings $settings,
        private readonly OrderService $service,
        private readonly OrderMailer $mailer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Everything the stock checkout page needs.
     *
     * @param array<string, mixed> $customer database row
     * @return array<string, mixed>
     */
    public function checkout(array $customer): array
    {
        return [
            'cart' => $this->stockCarts->summary($this->carts->idForCustomer((int) $customer['id'])),
            'addresses' => $this->addresses->forCustomer((int) $customer['id']),
            'pickup' => $this->settings->pickup(),
            'dispatch_days' => $this->settings->dispatchDays(),
            'payment_days' => $this->settings->paymentDays(),
            'payment_methods' => $this->settings->paymentMethods(),
            'email_verified' => $customer['email_verified_at'] !== null,
        ];
    }

    /**
     * Places a stock order from the buyer's cart.
     *
     * @param array<string, mixed> $customer database row
     * @param array<string, mixed> $data validated input: delivery, address_id, cargo_company, notes, expected_total_cents
     * @return array<string, mixed> the new order (database row)
     */
    public function place(array $customer, array $data): array
    {
        if ($customer['email_verified_at'] === null) {
            throw new HttpException(403, 'email_unverified', 'Please confirm your email address before placing an order. We sent you a link.');
        }
        $address = null;
        if ($data['delivery'] === 'cargo') {
            $address = $this->addresses->find((int) $customer['id'], (int) ($data['address_id'] ?? 0));
            if ($address === null) {
                throw new ValidationException(['address_id' => 'Choose where the cargo should go.']);
            }
        }
        $cartId = $this->carts->idForCustomer((int) $customer['id']);
        $cart = $this->stockCarts->summary($cartId);
        if ($cartId === null || $cart['lines'] === []) {
            throw new HttpException(409, 'cart_empty', 'Your cart is empty.');
        }
        if (!$cart['can_checkout']) {
            throw new HttpException(409, 'cart_changed', $cart['issues'][0] ?? 'Please check your cart.');
        }
        if (isset($data['expected_total_cents']) && (int) $data['expected_total_cents'] !== $cart['total_cents']) {
            throw new HttpException(409, 'totals_changed', 'Prices or the stock changed since you opened this page. Please check the new total and place the order again.');
        }

        $orderId = null;
        for ($attempt = 1; $orderId === null; $attempt++) {
            try {
                $orderId = $this->db->transaction(function () use ($customer, $address, $cart, $cartId, $data): int {
                    $id = $this->create($customer, $address, $cart, $data);
                    $this->stockCarts->clear($cartId);

                    return $id;
                });
            } catch (PDOException $e) {
                // Two orders got the same random number at the same moment: try another one.
                if ($attempt >= 3 || !str_contains($e->getMessage(), 'orders_order_no')) {
                    throw $e;
                }
            }
        }

        $order = $this->orders->find($orderId) ?? throw new \RuntimeException('The new stock order could not be read back.');
        try {
            $this->mailer->stockPlaced($this->service->present($order, false), $this->service->present($order, true));
        } catch (Throwable $e) {
            $this->logger->error('Stock order emails could not be sent', ['order' => $order['order_no'], 'error' => $e->getMessage()]);
        }

        return $order;
    }

    /**
     * The order's payment was confirmed: its sets leave the stock. Call it inside the transaction that confirms the
     * order: when a color no longer has the sets, it throws and nothing is changed.
     *
     * @param array<string, mixed> $order database row
     */
    public function deduct(array $order, ?int $staffId): void
    {
        foreach ($this->orders->lines($order['id']) as $line) {
            if ($line['stock_color_id'] === null || $line['sets'] === null) {
                continue;
            }
            try {
                $this->levels->change($line['stock_color_id'], 'sold', $line['sets'], "Order {$order['order_no']}", $staffId, $order['id']);
            } catch (ValidationException) {
                $inStock = $this->colors->find($line['stock_color_id'])['sets_in_stock'] ?? 0;
                throw new HttpException(409, 'out_of_stock', sprintf(
                    '%s in %s (%s) has %d %s in stock, but this order needs %d. Add the stock first, or cancel the order and refund the buyer.',
                    $line['style_name'],
                    $line['color_name'],
                    $line['color_code'],
                    $inStock,
                    $inStock === 1 ? 'set' : 'sets',
                    $line['sets'],
                ));
            } catch (HttpException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
                // The color was deleted from the stock: there is nothing to take out.
                $this->orders->addEvent($order['id'], 'note', null, null, "{$line['style_name']}, {$line['color_name']} is no longer in the stock list: its sets were not taken out.", 'system', null, false);
            }
        }
    }

    /**
     * A confirmed order was cancelled: its sets go back into stock.
     *
     * @param array<string, mixed> $order database row
     */
    public function restock(array $order, ?int $staffId): void
    {
        foreach ($this->orders->lines($order['id']) as $line) {
            if ($line['stock_color_id'] === null || $line['sets'] === null) {
                continue;
            }
            try {
                $this->levels->change($line['stock_color_id'], 'cancelled', $line['sets'], "Order {$order['order_no']} cancelled", $staffId, $order['id']);
            } catch (HttpException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed>|null $address the cargo address (null when the buyer collects the order)
     * @param array<string, mixed> $cart StockCartService::summary()
     * @param array<string, mixed> $data
     */
    private function create(array $customer, ?array $address, array $cart, array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $cargoCompany = trim((string) ($data['cargo_company'] ?? ''));
        $lines = array_map(static function (array $line): array {
            $sizeQty = [];
            foreach ($line['set']['sizes'] as $size) {
                $sizeQty[$size['size']] = $size['pcs'] * $line['sets'];
            }

            return [
                'product_id' => null,
                'color_id' => null,
                'stock_color_id' => $line['color_id'],
                'style_no' => $line['style']['style_no'],
                'style_name' => $line['style']['name'],
                'style_slug' => $line['style']['slug'],
                'segment' => $line['style']['segment'],
                'product_type' => StockStyle::PRODUCT_TYPE,
                'color_code' => $line['code'],
                'color_name' => $line['name'],
                'color_hex' => $line['hex'],
                'image_path' => $line['image']['path'] ?? null,
                'size_qty' => $sizeQty,
                'qty' => $line['pieces'],
                'sets' => $line['sets'],
                'unit_price_cents' => $line['set_price_cents'],
                'line_cents' => $line['line_cents'],
            ];
        }, $cart['lines']);

        $id = $this->orders->create([
            'order_no' => $this->service->nextOrderNo(),
            'kind' => 'stock',
            'customer_id' => (int) $customer['id'],
            'status' => 'awaiting_deposit',
            'currency' => StockSettings::CURRENCY,
            'total_pcs' => $cart['total_pieces'],
            'goods_cents' => $cart['total_cents'],
            'addons_cents' => 0,
            'shipping_mode' => $data['delivery'],
            'incoterm' => '',
            'freight_estimate_cents' => null,
            'freight_final_cents' => null,
            // Paid in full before dispatch.
            'deposit_percent' => 100,
            'deposit_cents' => $cart['total_cents'],
            'lead_time_days' => $this->settings->dispatchDays(),
            'packing' => ['sets' => $cart['total_sets']],
            'customer' => [
                'company_name' => $customer['company_name'],
                'contact_name' => $customer['contact_name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'city' => $customer['city'] ?? null,
                'country_code' => $customer['country_code'],
                'business_type' => $customer['business_type'],
            ],
            // A collected order has no delivery address: the buyer's contact is kept instead.
            'address' => $address !== null
                ? array_diff_key($address, ['id' => true, 'is_default' => true])
                : ['pickup' => true, 'company' => $customer['company_name'], 'contact_name' => $customer['contact_name'], 'phone' => $customer['phone'], 'city' => $customer['city'] ?? null],
            // For stock orders, the cargo company the buyer would like us to use.
            'forwarder' => $data['delivery'] === 'cargo' && $cargoCompany !== '' ? ['name' => mb_substr($cargoCompany, 0, 120)] : null,
            'buyer_reference' => null,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'placed_at' => $now,
            'priced_at' => $now,
        ], $lines, []);

        $this->orders->addEvent($id, 'placed', null, 'awaiting_deposit', null, 'customer', (int) $customer['id']);

        return $id;
    }
}
