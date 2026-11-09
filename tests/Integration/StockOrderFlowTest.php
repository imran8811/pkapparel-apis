<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use App\Models\Order;
use App\Models\Settings;
use App\Models\StaffUser;
use App\Models\StockColor;
use App\Models\StockStyle;
use App\Services\DailyJob;
use App\Services\StockLevels;

/**
 * Stock orders: the stock cart (guest and buyer), a stock account, checkout by cargo or collection, payment reports,
 * the sets taken out of stock when the payment is confirmed (and put back when a paid order is cancelled), dispatch,
 * collection and the daily job. Fixture: a stock style of 6-piece sets at Rs 6,900 with 5 sets in stock.
 */
final class StockOrderFlowTest extends BuyerApiTestCase
{
    private const BUYER = 'stock-buyer@example.test';

    private const STAFF = ['sales' => 'stock-orders-sales@example.test', 'admin' => 'stock-orders-admin@example.test'];

    private const SET_PRICE = 690000;

    private int $colorId = 0;

    private mixed $savedPayment = null;

    private mixed $savedStock = null;

    /** @var list<array<string, mixed>> */
    private array $savedSequences = [];

    protected function testIp(): string
    {
        return '192.0.2.83';
    }

    protected function testEmails(): array
    {
        return [self::BUYER];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpFixtures();
        $container = $this->app->container;

        $styleId = $container->get(StockStyle::class)->create([
            'slug' => 'test-stock-order-jeans', 'name' => 'TEST stock jeans', 'segment' => 'men', 'specs' => [], 'description_html' => '',
            'set_sizes' => [['size' => '28', 'pcs' => 1], ['size' => '30', 'pcs' => 2], ['size' => '32', 'pcs' => 2], ['size' => '34', 'pcs' => 1]],
            'set_price_cents' => self::SET_PRICE, 'status' => 'active',
        ]);
        $this->colorId = $container->get(StockColor::class)->create($styleId, ['code' => 'TEST-SO-1', 'name' => 'Test blue', 'slug' => 'test-blue', 'is_active' => true, 'sort_order' => 10], []);
        $container->get(StockLevels::class)->change($this->colorId, 'received', 5, 'Test stock', null);

        $settings = $container->get(Settings::class);
        $this->savedPayment = $settings->get('stock_payment');
        $this->savedStock = $settings->get('stock');
        $settings->set('stock_payment', ['account_title' => 'PK Apparel', 'bank_name' => 'Test Bank', 'account_number' => '0101', 'jazzcash_name' => 'PK Apparel', 'jazzcash_number' => '0300 0000000'], false);
        $settings->set('stock', ['min_sets_per_order' => 1, 'dispatch_days' => 2, 'payment_days' => 7, 'pickup_address' => '1 Test Road', 'pickup_hours' => '10 to 6'], false);
        $this->savedSequences = $this->db->fetchAll('SELECT * FROM document_sequences');

        foreach (self::STAFF as $role => $email) {
            $container->get(StaffUser::class)->create('Stock ' . ucfirst($role), $email, self::PASSWORD, $role);
        }
    }

    protected function tearDown(): void
    {
        $settings = $this->app->container->get(Settings::class);
        foreach (['stock_payment' => $this->savedPayment, 'stock' => $this->savedStock] as $name => $value) {
            if ($value === null) {
                $this->db->execute('DELETE FROM settings WHERE name = ?', [$name]);
            } else {
                $settings->set($name, $value, false);
            }
        }
        parent::tearDown();
        $this->db->execute('DELETE FROM document_sequences');
        foreach ($this->savedSequences as $row) {
            $this->db->insert('document_sequences', $row);
        }
        $this->cleanUpFixtures();
    }

    private function cleanUpFixtures(): void
    {
        $this->db->execute("DELETE FROM stock_styles WHERE slug = 'test-stock-order-jeans'");
        $limiter = new RateLimiter($this->db);
        foreach (self::STAFF as $email) {
            $this->db->execute("DELETE t FROM auth_tokens t JOIN staff_users s ON s.id = t.user_id AND t.user_type = 'staff' WHERE s.email = ?", [$email]);
            $this->db->execute('DELETE FROM staff_users WHERE email = ?', [$email]);
            $limiter->clear('staff-login:' . $email);
        }
        $limiter->clear('staff-login-ip:' . $this->testIp());
    }

    public function testGuestCartToPaidAndDispatchedOrder(): void
    {
        // A guest's cart: sets, never more than in stock.
        $added = $this->send('POST', '/v1/stock/cart/lines', ['color_id' => $this->colorId, 'sets' => 2]);
        self::assertSame(200, $added->status(), (string) $added->body());
        $guestToken = $added->decoded()['meta']['cart_token'];
        self::assertSame([2, 12, 2 * self::SET_PRICE], [$added->decoded()['data']['total_sets'], $added->decoded()['data']['total_pieces'], $added->decoded()['data']['total_cents']]);
        $tooMany = $this->send('POST', '/v1/stock/cart/lines', ['color_id' => $this->colorId, 'sets' => 4], null, $guestToken);
        self::assertSame(422, $tooMany->status());
        self::assertSame('Only 5 sets are in stock. You already have 2 in your cart.', $tooMany->decoded()['error']['fields']['sets']);

        // A stock account: shop, city and phone; the guest's sets move into it. Its profile keeps that shape.
        $signup = $this->send('POST', '/v1/account/signup', [
            'area' => 'stock', 'email' => self::BUYER, 'password' => self::PASSWORD, 'company_name' => 'Test Jeans Shop', 'contact_name' => 'Test Owner',
            'business_type' => 'retailer', 'city' => 'Multan', 'phone' => '+92 300 1234567', 'accept_terms' => true, 'cart_token' => $guestToken,
        ]);
        self::assertSame(201, $signup->status(), (string) $signup->body());
        $token = $signup->decoded()['data']['token'];
        self::assertSame(['stock', 'Multan'], [$signup->decoded()['data']['customer']['channel'], $signup->decoded()['data']['customer']['city']]);
        self::assertSame(2, $this->send('GET', '/v1/stock/cart', null, $token)->decoded()['data']['total_sets'], 'The guest cart was merged');
        $profile = $this->send('PUT', '/v1/account', [
            'email' => self::BUYER, 'company_name' => 'Test Jeans Shop', 'contact_name' => 'Test Owner', 'business_type' => 'wholesaler', 'city' => 'Lahore', 'phone' => '+92 300 1234567',
        ], $token);
        self::assertSame(200, $profile->status(), (string) $profile->body());
        self::assertSame(['Lahore', 'wholesaler', 'PK'], [$profile->decoded()['data']['city'], $profile->decoded()['data']['business_type'], $profile->decoded()['data']['country_code']]);
        // The confirmation link leads to the stock pages.
        $verified = $this->send('POST', '/v1/account/verify-email', ['token' => $this->lastMailedToken(self::BUYER, '/stock/verify-email')]);
        self::assertSame(200, $verified->status());

        $checkout = $this->send('GET', '/v1/account/stock-checkout', null, $token)->decoded()['data'];
        self::assertTrue($checkout['cart']['can_checkout']);
        self::assertSame(['Bank transfer', 'JazzCash'], $checkout['payment_methods']);
        self::assertSame(422, $this->send('POST', '/v1/account/stock-orders', ['delivery' => 'cargo', 'accept_terms' => true], $token)->status(), 'Cargo needs an address');

        $address = $this->send('POST', '/v1/account/addresses', ['company' => 'Test Jeans Shop', 'contact_name' => 'Test Owner', 'phone' => '+92 300 1234567', 'line1' => 'Shop 4, Test Market', 'city' => 'Multan'], $token);
        self::assertSame(201, $address->status(), (string) $address->body());
        self::assertSame('PK', $address->decoded()['data']['country_code'], 'Stock addresses are in the stock country');
        $placed = $this->send('POST', '/v1/account/stock-orders', [
            'delivery' => 'cargo', 'address_id' => $address->decoded()['data']['id'], 'cargo_company' => 'Test Cargo', 'accept_terms' => true, 'expected_total_cents' => 2 * self::SET_PRICE,
        ], $token);
        self::assertSame(201, $placed->status(), (string) $placed->body());
        $order = $placed->decoded()['data'];
        self::assertSame(['stock', 'PKR', 'awaiting_deposit', 'Awaiting payment', 'cargo'], [$order['kind'], $order['currency'], $order['status'], $order['status_label'], $order['shipping_mode']]);
        self::assertSame([2 * self::SET_PRICE, 2 * self::SET_PRICE, 'none'], [$order['amounts']['total_cents'], $order['amounts']['due_now_cents'], $order['amounts']['freight_status']]);
        self::assertSame(['0101', '0300 0000000'], [$order['bank']['account_number'], $order['bank']['jazzcash_number']], 'The local payment details');
        self::assertSame([2, 12, ['28' => 2, '30' => 4, '32' => 4, '34' => 2]], [$order['lines'][0]['sets'], $order['lines'][0]['qty'], $order['lines'][0]['size_qty']]);
        self::assertSame([], $order['documents'], 'No proforma invoice');
        self::assertSame(0, $this->send('GET', '/v1/stock/cart', null, $token)->decoded()['data']['line_count'], 'The stock cart is empty');
        self::assertSame(5, $this->sets(), 'Placing an order does not take stock');
        self::assertSame(404, $this->send('GET', "/v1/account/orders/{$order['order_no']}/proforma", null, $token)->status());

        // The payment: reported, then confirmed by an admin; the sets leave the stock.
        $paymentId = $this->reportPayment($token, $order['order_no'], 2 * self::SET_PRICE);
        $confirmed = $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 2 * self::SET_PRICE, 'received_on' => gmdate('Y-m-d')], $this->staff('admin'));
        self::assertSame(200, $confirmed->status(), (string) $confirmed->body());
        self::assertSame(['confirmed', 'Paid, being packed'], [$confirmed->decoded()['data']['status'], $confirmed->decoded()['data']['status_label']]);
        self::assertSame(3, $this->sets());
        self::assertSame(['sold', -2], array_values((array) $this->db->fetch(
            "SELECT m.reason, m.change_sets FROM stock_movements m JOIN orders o ON o.id = m.order_id WHERE o.order_no = ? AND m.reason = 'sold'",
            [$order['order_no']],
        )));

        // Dispatch by cargo, then delivered.
        $sales = $this->staff('sales');
        $orderId = (int) $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$order['order_no']]);
        self::assertSame(409, $this->send('POST', "/v1/admin/orders/{$orderId}/collected", [], $sales)->status(), 'A cargo order is not collected');
        $shipped = $this->send('POST', "/v1/admin/orders/{$orderId}/ship", ['carrier' => 'Test Cargo', 'tracking_no' => 'BILTY-1', 'shipped_on' => gmdate('Y-m-d')], $sales);
        self::assertSame(['shipped', 'Sent by cargo'], [$shipped->decoded()['data']['status'], $shipped->decoded()['data']['status_label']], (string) $shipped->body());
        self::assertSame('delivered', $this->send('POST', "/v1/admin/orders/{$orderId}/deliver", [], $sales)->decoded()['data']['status']);

        // The buyer's emails link to the stock pages, in rupees, without the country.
        $emails = array_filter(explode('==== ', $this->mailLog()), static fn (string $entry): bool => str_contains($entry, $order['order_no']) && str_contains($entry, 'To: ' . self::BUYER));
        self::assertGreaterThanOrEqual(4, count($emails), 'Placed, payment reported, confirmed, shipped, delivered');
        foreach ($emails as $email) {
            self::assertStringNotContainsString('Pakistan', $email);
        }
        self::assertStringContainsString("/stock/account/orders/{$order['order_no']}", implode('', $emails));
        self::assertStringContainsString('Rs 13,800', implode('', $emails));
    }

    public function testConfirmingNeedsTheStockAndCancellingPutsItBack(): void
    {
        $token = $this->stockBuyer();
        $orderNo = $this->placePickup($token, 3);
        $paymentId = $this->reportPayment($token, $orderNo, 3 * self::SET_PRICE);
        $admin = $this->staff('admin');

        // Sold elsewhere meanwhile: only 1 set left, so the payment cannot be confirmed.
        $this->app->container->get(StockLevels::class)->change($this->colorId, 'correction', 1, 'Test count', null);
        $short = $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 3 * self::SET_PRICE, 'received_on' => gmdate('Y-m-d')], $admin);
        self::assertSame(409, $short->status());
        self::assertStringContainsString('has 1 set in stock, but this order needs 3', $short->decoded()['error']['message']);
        self::assertSame('submitted', $this->db->fetchValue('SELECT status FROM order_payments WHERE id = ?', [$paymentId]), 'Nothing was confirmed');
        self::assertSame(1, $this->sets());

        // New stock arrives: confirmed, then cancelled: the sets go back.
        $this->app->container->get(StockLevels::class)->change($this->colorId, 'received', 4, 'Test delivery', null);
        self::assertSame(200, $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 3 * self::SET_PRICE, 'received_on' => gmdate('Y-m-d')], $admin)->status());
        self::assertSame(2, $this->sets());
        $orderId = (int) $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$orderNo]);
        $cancelled = $this->send('POST', "/v1/admin/orders/{$orderId}/cancel", ['reason' => 'Test: buyer changed their mind', 'notify' => false], $this->staff('sales'));
        self::assertSame('cancelled', $cancelled->decoded()['data']['status'], (string) $cancelled->body());
        self::assertSame(5, $this->sets());
    }

    public function testPickupOrderIsCollectedAndUnpaidOrdersAreCancelled(): void
    {
        $token = $this->stockBuyer();
        $orderNo = $this->placePickup($token, 1);
        $sales = $this->staff('sales');
        $orderId = (int) $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$orderNo]);
        self::assertSame(409, $this->send('POST', "/v1/admin/orders/{$orderId}/collected", [], $sales)->status(), 'Collected once paid');

        $paymentId = $this->reportPayment($token, $orderNo, self::SET_PRICE);
        $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => self::SET_PRICE, 'received_on' => gmdate('Y-m-d')], $this->staff('admin'));
        self::assertSame(409, $this->send('POST', "/v1/admin/orders/{$orderId}/ship", ['carrier' => 'Test Cargo', 'shipped_on' => gmdate('Y-m-d')], $sales)->status(), 'A collected order is not shipped');
        self::assertSame('delivered', $this->send('POST', "/v1/admin/orders/{$orderId}/collected", [], $sales)->decoded()['data']['status']);

        // An unpaid stock order: one reminder after 2 days, cancelled after the payment days (7).
        $unpaid = $this->placePickup($token, 1);
        $orders = $this->app->container->get(Order::class);
        $job = $this->app->container->get(DailyJob::class);
        $unpaidId = (int) $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$unpaid]);
        $placed = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Karachi'));
        self::assertNull($job->handleUnpaid((array) $orders->find($unpaidId), $placed->modify('+1 day')));
        self::assertSame('reminded', $job->handleUnpaid((array) $orders->find($unpaidId), $placed->modify('+2 days')));
        self::assertNull($job->handleUnpaid((array) $orders->find($unpaidId), $placed->modify('+5 days')), 'One reminder');
        self::assertSame('cancelled', $job->handleUnpaid((array) $orders->find($unpaidId), $placed->modify('+7 days')));
        self::assertSame(4, $this->sets(), 'The cancelled order never held stock');
    }

    private function sets(): int
    {
        return (int) $this->db->fetchValue('SELECT sets_in_stock FROM stock_colors WHERE id = ?', [$this->colorId]);
    }

    /** A verified stock buyer's token. */
    private function stockBuyer(): string
    {
        $signup = $this->send('POST', '/v1/account/signup', [
            'area' => 'stock', 'email' => self::BUYER, 'password' => self::PASSWORD, 'company_name' => 'Test Jeans Shop', 'contact_name' => 'Test Owner',
            'business_type' => 'wholesaler', 'city' => 'Lahore', 'phone' => '+92 300 1234567', 'accept_terms' => true,
        ]);
        self::assertSame(201, $signup->status(), (string) $signup->body());
        $this->send('POST', '/v1/account/verify-email', ['token' => $this->lastMailedToken(self::BUYER, '/stock/verify-email')]);

        return $signup->decoded()['data']['token'];
    }

    /** Places an order the buyer collects, for this many sets. */
    private function placePickup(string $token, int $sets): string
    {
        self::assertSame(200, $this->send('POST', '/v1/stock/cart/lines', ['color_id' => $this->colorId, 'sets' => $sets], $token)->status());
        $placed = $this->send('POST', '/v1/account/stock-orders', ['delivery' => 'pickup', 'accept_terms' => true], $token);
        self::assertSame(201, $placed->status(), (string) $placed->body());
        self::assertSame(['pickup', true], [$placed->decoded()['data']['shipping_mode'], $placed->decoded()['data']['address']['pickup']]);

        return $placed->decoded()['data']['order_no'];
    }

    private function reportPayment(string $token, string $orderNo, int $cents): int
    {
        $response = $this->sendForm("/v1/account/orders/{$orderNo}/payments", [
            'transaction_id' => 'TX-' . bin2hex(random_bytes(4)),
            'amount_cents' => (string) $cents,
            'transferred_on' => gmdate('Y-m-d'),
            'payer_name' => 'Test Jeans Shop',
            'payer_bank' => 'JazzCash',
        ], [], $token);
        self::assertSame(201, $response->status(), (string) $response->body());

        return (int) $this->db->fetchValue('SELECT p.id FROM order_payments p JOIN orders o ON o.id = p.order_id WHERE o.order_no = ? ORDER BY p.id DESC LIMIT 1', [$orderNo]);
    }

    private function staff(string $role): string
    {
        $response = $this->send('POST', '/v1/admin/auth/login', ['email' => self::STAFF[$role], 'password' => self::PASSWORD]);
        self::assertSame(200, $response->status());

        return $response->decoded()['data']['token'];
    }

    /** The text of today's mail log. */
    private function mailLog(): string
    {
        return (string) @file_get_contents($this->app->config()->string('paths.storage') . '/logs/mail-' . gmdate('Y-m-d') . '.log');
    }
}
