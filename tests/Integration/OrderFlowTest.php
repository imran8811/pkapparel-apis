<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Cart → checkout → order → proforma invoice → payment report, plus the admin views.
 * Uses its own test style, freight zone (Antarctica, AQ) and add-on (OrderTestCase), so it does not depend on the catalog.
 */
final class OrderFlowTest extends OrderTestCase
{
    private const BUYER = 'order-buyer@example.test';

    private const OTHER = 'order-other@example.test';

    protected function testIp(): string
    {
        return '192.0.2.82';
    }

    protected function testEmails(): array
    {
        return [self::BUYER, self::OTHER];
    }

    protected function staffEmails(): array
    {
        return ['sales' => 'order-sales@example.test', 'admin' => 'order-admin@example.test'];
    }

    protected function fixtureSlug(): string
    {
        return 'test-style-order-flow';
    }

    public function testGuestCartFollowsTheBuyerIntoTheirAccount(): void
    {
        $added = $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 10, 'M' => 10]]);
        self::assertSame(200, $added->status(), (string) json_encode($added->decoded()));
        $cartToken = $added->decoded()['meta']['cart_token'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cartToken);

        $cart = $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 5]], null, $cartToken)->decoded();
        self::assertArrayNotHasKey('meta', $cart, 'The cart token is only handed out once');
        $line = $cart['data']['styles'][0]['colors'][0];
        self::assertEquals(['S' => 15, 'M' => 10], (array) $line['size_qty'], 'Adding again adds to the quantities');
        self::assertTrue($line['below_moq']);
        self::assertFalse($cart['data']['can_checkout']);
        self::assertStringContainsString('needs 35 more pieces', implode(' ', $cart['data']['issues']));

        $set = $this->send('PUT', "/v1/cart/lines/{$this->colorA}", ['size_qty' => ['S' => 20, 'M' => 20, 'L' => 20]], null, $cartToken)->decoded()['data'];
        self::assertSame(60, $set['total_pcs']);
        self::assertTrue($set['can_checkout']);
        self::assertSame(24000, $set['goods_cents']);

        self::assertSame(422, $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['XXL' => 5]], null, $cartToken)->status());
        self::assertSame(422, $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => -1]], null, $cartToken)->status());

        $session = $this->signup(self::BUYER, ['cart_token' => $cartToken]);
        $mine = $this->send('GET', '/v1/cart', null, $session['token'])->decoded()['data'];
        self::assertSame(60, $mine['total_pcs'], 'The guest cart moved into the account');
        self::assertSame(0, $this->send('GET', '/v1/cart', null, null, $cartToken)->decoded()['data']['line_count'], 'The guest cart is gone');
    }

    public function testVolumeTiersCountAllColorsOfAStyle(): void
    {
        $token = $this->signup(self::BUYER)['token'];
        $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 40, 'M' => 40, 'L' => 40]], $token);
        $cart = $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorB, 'size_qty' => ['S' => 30, 'M' => 30, 'L' => 20]], $token)->decoded()['data'];

        // 200 pcs reach the $3.50 tier: $0.50 off every color, so black costs $4.00.
        $prices = array_column($cart['styles'][0]['colors'], 'unit_price_cents', 'code');
        self::assertSame(['TST-A' => 350, 'TST-B' => 400], $prices);
        self::assertSame(120 * 350 + 80 * 400, $cart['goods_cents']);
    }

    public function testCheckoutPlacesAnOrderAndEmptiesTheCart(): void
    {
        [$token, $addressId] = $this->readyBuyer(self::BUYER);

        $checkout = $this->send('GET', '/v1/account/checkout', ['address_id' => $addressId], $token)->decoded()['data'];
        self::assertSame('AQ', $checkout['country_code']);
        self::assertSame(['cartons' => 3, 'gross_g' => 63000, 'volume_cm3' => 216000, 'cbm' => 0.216], $checkout['cart']['packing']);
        self::assertSame(63 * 600 + 2500, $checkout['shipping']['air']['cost_cents'], '63 kg at the first weight band');
        self::assertSame(9000 + 10000, $checkout['shipping']['sea']['cost_cents'], 'Sea is charged for at least 1 CBM');
        self::assertContains($this->addonId, array_column($checkout['addons'], 'id'));

        $goods = 60 * 400 + 60 * 450;
        $addons = 120 * 12;
        $total = $goods + $addons + 63 * 600 + 2500;
        $order = ['address_id' => $addressId, 'shipping_mode' => 'air', 'addons' => [$this->addonId], 'buyer_reference' => 'PO-77', 'accept_terms' => true];

        $stale = $this->send('POST', '/v1/account/orders', $order + ['expected_total_cents' => $total - 1], $token);
        self::assertSame(409, $stale->status());
        self::assertSame('totals_changed', $stale->decoded()['error']['code']);

        $placed = $this->send('POST', '/v1/account/orders', $order + ['expected_total_cents' => $total], $token);
        self::assertSame(201, $placed->status(), (string) json_encode($placed->decoded()));
        $data = $placed->decoded()['data'];
        self::assertMatchesRegularExpression('/^PK-\d{6}-\d{3,4}$/', $data['order_no']);
        self::assertSame('awaiting_deposit', $data['status']);
        self::assertSame('DAP', $data['incoterm']);
        self::assertSame(['goods_cents' => $goods, 'addons_cents' => $addons, 'total_cents' => $total, 'deposit_cents' => (int) round(($goods + $addons) / 2), 'due_now_cents' => (int) round(($goods + $addons) / 2)], array_intersect_key($data['amounts'], array_flip(['goods_cents', 'addons_cents', 'total_cents', 'deposit_cents', 'due_now_cents'])));
        self::assertSame('estimate', $data['amounts']['freight_status']);
        self::assertSame('0001234567', $data['bank']['account_number'], 'Bank details are shown once an order needs paying');
        self::assertSame(['S' => 20, 'M' => 20, 'L' => 20], $data['lines'][0]['size_qty']);
        self::assertTrue($data['can_cancel']);

        self::assertSame(0, $this->send('GET', '/v1/cart', null, $token)->decoded()['data']['line_count'], 'The cart is empty after ordering');
        $again = $this->send('POST', '/v1/account/orders', $order, $token);
        self::assertSame('cart_empty', $again->decoded()['error']['code']);

        $list = $this->send('GET', '/v1/account/orders', null, $token)->decoded();
        self::assertSame([$data['order_no']], array_column($list['data'], 'order_no'));
        self::assertSame(['TEST Order Jeans'], $list['data'][0]['styles']);

        $pdf = $this->send('GET', "/v1/account/orders/{$data['order_no']}/proforma", null, $token);
        self::assertSame(200, $pdf->status());
        self::assertSame('application/pdf', $pdf->headers()['Content-Type']);
        self::assertStringStartsWith('%PDF-', $pdf->body());

        $log = (string) file_get_contents($this->app->config()->string('paths.storage') . '/logs/mail-' . gmdate('Y-m-d') . '.log');
        self::assertStringContainsString("Attachments: PI-{$data['order_no']}.pdf (application/pdf", $log, 'The PI is emailed to the buyer');
        self::assertStringContainsString("[{$data['order_no']}] New order from Test Denim Co", $log, 'Staff are told about the order');

        $stranger = $this->signup(self::OTHER)['token'];
        self::assertSame(404, $this->send('GET', "/v1/account/orders/{$data['order_no']}", null, $stranger)->status(), "Another buyer's order is out of reach");
        self::assertSame(404, $this->send('GET', "/v1/account/orders/{$data['order_no']}/proforma", null, $stranger)->status());
    }

    public function testOrdersNeedAConfirmedEmailAndAValidCart(): void
    {
        $token = $this->signup(self::BUYER)['token'];
        $addressId = $this->address($token);
        $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 10]], $token);
        $order = ['address_id' => $addressId, 'shipping_mode' => 'sea', 'accept_terms' => true];

        $unverified = $this->send('POST', '/v1/account/orders', $order, $token);
        self::assertSame(403, $unverified->status());
        self::assertSame('email_unverified', $unverified->decoded()['error']['code']);

        $this->verify(self::BUYER);
        $belowMinimum = $this->send('POST', '/v1/account/orders', $order, $token);
        self::assertSame(409, $belowMinimum->status());
        self::assertSame('cart_invalid', $belowMinimum->decoded()['error']['code']);

        $this->send('PUT', "/v1/cart/lines/{$this->colorA}", ['size_qty' => ['S' => 60]], $token);
        $this->db->execute('UPDATE product_colors SET is_active = 0 WHERE id = ?', [$this->colorA]);
        $cart = $this->send('GET', '/v1/cart', null, $token)->decoded()['data'];
        self::assertFalse($cart['styles'][0]['colors'][0]['available']);
        self::assertFalse($cart['can_checkout']);
        self::assertSame(422, $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 1]], $token)->status(), 'No new pieces for colors no longer sold');
        self::assertSame(200, $this->send('DELETE', "/v1/cart/lines/{$this->colorA}", null, $token)->status(), 'Removing still works');

        self::assertSame(422, $this->send('POST', '/v1/account/orders', ['shipping_mode' => 'rocket', 'accept_terms' => false], $token)->status());
    }

    public function testPaymentReportMovesTheOrderToReview(): void
    {
        [$token, $orderNo] = $this->placedOrder();
        $due = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $token)->decoded()['data']['amounts']['due_now_cents'];
        $payment = [
            'transaction_id' => 'FT 2610 123456', 'amount_cents' => (string) $due, 'transferred_on' => gmdate('Y-m-d'),
            'payer_name' => 'Test Denim Co', 'payer_bank' => 'Test Bank London', 'notes' => 'Deposit',
        ];

        $future = $this->sendForm("/v1/account/orders/{$orderNo}/payments", ['transferred_on' => gmdate('Y-m-d', strtotime('+5 days'))] + $payment, [], $token);
        self::assertSame(['transferred_on'], array_keys($future->decoded()['error']['fields']));

        $slip = $this->makeSlip();
        $sent = $this->sendForm("/v1/account/orders/{$orderNo}/payments", $payment, ['slip' => $slip], $token);
        self::assertSame(201, $sent->status(), (string) json_encode($sent->decoded()));
        $data = $sent->decoded()['data'];
        self::assertSame('submitted', $data['payment']['status']);
        self::assertTrue($data['payment']['has_slip']);
        self::assertSame('payment_review', $data['order']['status']);
        self::assertFalse($data['order']['can_cancel'], 'No cancelling online once a payment is reported');

        $path = (string) $this->db->fetchValue('SELECT p.slip_path FROM order_payments p JOIN orders o ON o.id = p.order_id WHERE o.order_no = ?', [$orderNo]);
        self::assertStringStartsWith('payment-slips/', $path);
        self::assertFileExists($this->app->config()->string('paths.storage') . '/private/' . $path, 'Slips are private files');

        $reused = $this->sendForm("/v1/account/orders/{$orderNo}/payments", ['transaction_id' => 'ft-2610-123456'] + $payment, [], $token);
        self::assertSame(['transaction_id'], array_keys($reused->decoded()['error']['fields']), 'A transaction ID can be used only once');

        self::assertSame(409, $this->send('POST', "/v1/account/orders/{$orderNo}/cancel", [], $token)->status());
        $log = (string) file_get_contents($this->app->config()->string('paths.storage') . '/logs/mail-' . gmdate('Y-m-d') . '.log');
        self::assertStringContainsString("[{$orderNo}] Payment to check", $log);
    }

    public function testBuyerCanCancelBeforePaying(): void
    {
        [$token, $orderNo] = $this->placedOrder();

        $cancelled = $this->send('POST', "/v1/account/orders/{$orderNo}/cancel", ['reason' => 'Ordered the wrong color'], $token);
        self::assertSame(200, $cancelled->status());
        self::assertSame('cancelled', $cancelled->decoded()['data']['status']);
        self::assertNull($cancelled->decoded()['data']['bank'], 'No bank details without an amount due');
        self::assertSame(409, $this->send('GET', "/v1/account/orders/{$orderNo}/proforma", null, $token)->status());
        self::assertSame(409, $this->sendForm("/v1/account/orders/{$orderNo}/payments", [
            'transaction_id' => 'TX-1', 'amount_cents' => '100', 'transferred_on' => gmdate('Y-m-d'), 'payer_name' => 'Tess', 'payer_bank' => 'Bank',
        ], [], $token)->status());
    }

    public function testStaffSeeOrdersCustomersAndSlips(): void
    {
        [$buyerToken, $orderNo] = $this->placedOrder();
        $this->sendForm("/v1/account/orders/{$orderNo}/payments", [
            'transaction_id' => 'SLIP-42', 'amount_cents' => '1000', 'transferred_on' => gmdate('Y-m-d'), 'payer_name' => 'Tess', 'payer_bank' => 'Bank',
        ], ['slip' => $this->makeSlip()], $buyerToken);
        $staff = $this->staffLogin();

        $list = $this->send('GET', '/v1/admin/orders', ['q' => $orderNo], $staff)->decoded();
        self::assertSame([$orderNo], array_column($list['data'], 'order_no'));
        self::assertSame('Test Denim Co', $list['data'][0]['company_name']);
        self::assertGreaterThanOrEqual(1, $list['meta']['payments_to_check']);

        $order = $this->send('GET', '/v1/admin/orders/' . $list['data'][0]['id'], null, $staff)->decoded()['data'];
        self::assertSame(self::BUYER, $order['account']['email']);
        self::assertSame('SLIP-42', $order['payments'][0]['transaction_id']);
        self::assertSame('placed', $order['events'][0]['type']);

        $slip = $this->send('GET', '/v1/admin/payments/' . $order['payments'][0]['id'] . '/slip', null, $staff);
        self::assertSame(200, $slip->status());
        self::assertSame('image/jpeg', $slip->headers()['Content-Type'], 'Photos of slips are re-encoded as JPEG');
        self::assertStringStartsWith('%PDF-', $this->send('GET', '/v1/admin/orders/' . $order['id'] . '/proforma', null, $staff)->body());

        $customers = $this->send('GET', '/v1/admin/customers', ['q' => self::BUYER], $staff)->decoded();
        self::assertSame(1, $customers['data'][0]['order_count']);
        $customerId = $customers['data'][0]['id'];
        $blocked = $this->send('PATCH', "/v1/admin/customers/{$customerId}", ['status' => 'blocked', 'staff_notes' => 'Test block'], $staff)->decoded()['data'];
        self::assertSame('blocked', $blocked['status']);
        self::assertCount(1, $blocked['orders']);
        self::assertSame(401, $this->send('GET', '/v1/account', null, $buyerToken)->status(), 'Blocking ends the buyer\'s sessions');
    }

    public function testAdminManagesZonesAddonsAndBankDetails(): void
    {
        $staff = $this->staffLogin();

        $zone = ['name' => 'TEST Europe', 'countries' => ['de', 'FR'], 'rates' => [
            ['mode' => 'air', 'from_qty' => 0, 'rate_cents' => 520, 'fixed_cents' => 3000, 'transit_min_days' => 5, 'transit_max_days' => 8],
            ['mode' => 'sea', 'from_qty' => '1.5', 'rate_cents' => 8000],
        ]];
        $created = $this->send('POST', '/v1/admin/shipping/zones', $zone, $staff);
        self::assertSame(201, $created->status(), (string) json_encode($created->decoded()));
        self::assertSame(['DE', 'FR'], $created->decoded()['data']['countries']);
        self::assertSame(1.5, $created->decoded()['data']['rates'][1]['from_qty']);

        $clash = $this->send('POST', '/v1/admin/shipping/zones', ['name' => 'TEST Clash', 'countries' => ['AQ']], $staff);
        self::assertStringContainsString('AQ (TEST zone)', $clash->decoded()['error']['fields']['countries']);
        $duplicate = $this->send('PUT', '/v1/admin/shipping/zones/' . $created->decoded()['data']['id'], ['rates' => [$zone['rates'][0], $zone['rates'][0]]] + $zone, $staff);
        self::assertSame(422, $duplicate->status());
        self::assertSame(204, $this->send('DELETE', '/v1/admin/shipping/zones/' . $created->decoded()['data']['id'], null, $staff)->status());

        $addon = $this->send('POST', '/v1/admin/addons', ['name' => 'TEST hang tags', 'pricing' => 'per_order', 'price_cents' => 2500], $staff);
        self::assertSame(201, $addon->status());
        self::assertSame(422, $this->send('POST', '/v1/admin/addons', ['name' => 'TEST no price', 'pricing' => 'per_piece'], $staff)->status());
        $onRequest = $this->send('PUT', '/v1/admin/addons/' . $addon->decoded()['data']['id'], ['name' => 'TEST hang tags', 'pricing' => 'on_request', 'price_cents' => 99], $staff)->decoded()['data'];
        self::assertNull($onRequest['price_cents'], 'On-request add-ons have no price');

        // Bank details are admin-only and never public.
        self::assertSame(403, $this->send('PUT', '/v1/admin/settings/bank', ['beneficiary' => 'X'], $staff)->status());
        $admin = $this->staffLogin('admin');
        $badIban = $this->send('PUT', '/v1/admin/settings/bank', ['beneficiary' => 'PK Apparel', 'iban' => 'not an iban'], $admin);
        self::assertSame(['iban'], array_keys($badIban->decoded()['error']['fields']));
        $saved = $this->send('PUT', '/v1/admin/settings/bank', ['beneficiary' => 'PK Apparel', 'iban' => 'pk36 test 0000 0012 3456 7890', 'swift' => 'testpkka'], $admin)->decoded()['data'];
        self::assertSame('PK36 TEST 0000 0012 3456 7890', $saved['iban']);
        self::assertSame('TESTPKKA', $saved['swift']);
        self::assertArrayNotHasKey('bank', $this->send('GET', '/v1/site')->decoded()['data']);
    }

}
