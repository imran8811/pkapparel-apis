<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Format;
use App\Services\DailyJob;

/**
 * Phase 4: payment review with receipts, production stages and photos, the balance invoice,
 * shipping, holds and cancelling, documents, notes, staff accounts and the daily job.
 */
final class OrderManagementTest extends OrderTestCase
{
    private const BUYER = 'manage-buyer@example.test';

    private const OTHER = 'manage-other@example.test';

    protected function testIp(): string
    {
        return '192.0.2.83';
    }

    protected function testEmails(): array
    {
        return [self::BUYER, self::OTHER];
    }

    protected function staffEmails(): array
    {
        return [
            'admin' => 'manage-admin@example.test',
            'sales' => 'manage-sales@example.test',
            'production' => 'manage-production@example.test',
        ];
    }

    protected function fixtureSlug(): string
    {
        return 'test-style-order-management';
    }

    protected function tearDown(): void
    {
        $this->db->execute("DELETE FROM staff_users WHERE email LIKE 'manage-new%@example.test'");
        parent::tearDown();
    }

    public function testOnlyAdminsConfirmPaymentsAndProductionStarts(): void
    {
        [$buyer, $orderNo] = $this->placedOrder();
        $paymentId = $this->reportPayment($buyer, $orderNo, 25500);

        $sales = $this->staffLogin('sales');
        $queue = $this->send('GET', '/v1/admin/payments', null, $sales)->decoded();
        self::assertContains($paymentId, array_column($queue['data'], 'id'));
        self::assertFalse($queue['meta']['can_review'], 'Sales staff see the queue but cannot act');
        self::assertSame(403, $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 25500, 'received_on' => gmdate('Y-m-d')], $sales)->status());

        $admin = $this->staffLogin('admin');
        $confirmed = $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 25500, 'received_on' => gmdate('Y-m-d')], $admin);
        self::assertSame(200, $confirmed->status(), (string) json_encode($confirmed->decoded()));
        $order = $confirmed->decoded()['data'];
        self::assertSame('confirmed', $order['status']);
        self::assertSame(25500, $order['amounts']['paid_cents']);
        self::assertSame(Format::local()->modify('+35 days')->format('Y-m-d'), $order['production_due_on'], 'Due 35 days (the style\'s lead time) after the confirmation');
        $receiptNo = $order['payments'][0]['receipt_no'];
        self::assertMatchesRegularExpression('/^RCPT-\d{4}-\d{5}$/', $receiptNo);

        self::assertStringContainsString("Payment received for {$orderNo} (receipt {$receiptNo})", $this->mailLog());
        self::assertStringContainsString("Attachments: {$receiptNo}.pdf (application/pdf", $this->mailLog());
        self::assertSame(409, $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 25500, 'received_on' => gmdate('Y-m-d')], $admin)->status(), 'A payment is reviewed once');

        $buyerView = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data'];
        self::assertContains($receiptNo, array_column($buyerView['documents'], 'number'));
        $pdf = $this->send('GET', "/v1/account/orders/{$orderNo}/receipts/{$receiptNo}", null, $buyer);
        self::assertStringStartsWith('%PDF-', $pdf->body());
        self::assertStringStartsWith('%PDF-', $this->send('GET', "/v1/admin/payments/{$paymentId}/receipt", null, $sales)->body());
    }

    public function testAShortDepositWaitsUnlessTheAdminStartsAnyway(): void
    {
        [$buyer, $orderNo] = $this->placedOrder();
        $paymentId = $this->reportPayment($buyer, $orderNo, 25500);
        $admin = $this->staffLogin('admin');

        // Bank fees: $25 less arrived.
        $order = $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 23000, 'received_on' => gmdate('Y-m-d')], $admin)->decoded()['data'];
        self::assertSame('awaiting_deposit', $order['status'], 'Short, and nothing else to review: back to waiting');
        self::assertSame(2500, $order['amounts']['due_now_cents']);

        $recorded = $this->send('POST', "/v1/admin/orders/{$order['id']}/payments", [
            'transaction_id' => 'BANK-TOPUP-1', 'amount_cents' => 2000, 'transferred_on' => gmdate('Y-m-d'),
            'payer_name' => 'Test Denim Co', 'payer_bank' => 'Test Bank', 'start_anyway' => true,
        ], $admin);
        self::assertSame(201, $recorded->status(), (string) json_encode($recorded->decoded()));
        $order = $recorded->decoded()['data'];
        self::assertSame('confirmed', $order['status'], 'The admin started production $5 short');
        self::assertSame(25000, $order['amounts']['paid_cents']);
        self::assertTrue($order['payments'][1]['recorded_by_staff']);
    }

    public function testRejectingFreesTheTransactionId(): void
    {
        [$buyer, $orderNo] = $this->placedOrder();
        $paymentId = $this->reportPayment($buyer, $orderNo, 25500, 'TX-WRONG-1');
        $admin = $this->staffLogin('admin');

        self::assertSame(422, $this->send('POST', "/v1/admin/payments/{$paymentId}/reject", ['reason' => 'no'], $admin)->status(), 'A reason is needed');
        $order = $this->send('POST', "/v1/admin/payments/{$paymentId}/reject", ['reason' => 'The amount is not on our statement'], $admin)->decoded()['data'];
        self::assertSame('awaiting_deposit', $order['status']);
        self::assertSame('rejected', $order['payments'][0]['status']);
        self::assertStringContainsString("We could not confirm your payment for {$orderNo}", $this->mailLog());

        $this->reportPayment($buyer, $orderNo, 25500, 'TX-WRONG-1');
        self::assertSame('payment_review', $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data']['status'], 'The same transaction ID can be reported again');
    }

    public function testProductionStagesAndPhotos(): void
    {
        [$buyer, $orderNo, $orderId] = $this->confirmedOrder();
        $production = $this->staffLogin('production');

        self::assertSame(403, $this->send('POST', "/v1/admin/orders/{$orderId}/ready-to-ship", null, $production)->status(), 'Production staff only update production');
        self::assertSame(204, $this->send('PUT', "/v1/admin/orders/{$orderId}/stages/cutting", ['action' => 'start'], $production)->status());
        $photo = $this->app->handle(new \App\Core\Request('POST', "/v1/admin/orders/{$orderId}/photos", [], $this->headers($production), '', ['stage' => 'cutting', 'caption' => 'First layers on the table'], ['photo' => $this->makeSlip()]));
        self::assertSame(201, $photo->status(), (string) json_encode($photo->decoded()));
        $photoId = $photo->decoded()['data']['id'];
        self::assertSame(204, $this->send('PUT', "/v1/admin/orders/{$orderId}/stages/cutting", ['action' => 'done', 'note' => 'All 120 pieces cut', 'notify' => true], $production)->status());

        $view = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data'];
        self::assertSame('in_production', $view['status']);
        $cutting = array_values(array_filter($view['stages'], static fn (array $s): bool => $s['key'] === 'cutting'))[0];
        self::assertSame('done', $cutting['status']);
        self::assertSame([$photoId], array_column($view['photos'], 'id'));
        self::assertStringContainsString("Cutting is done: order {$orderNo}", $this->mailLog());

        $image = $this->send('GET', "/v1/account/orders/{$orderNo}/photos/{$photoId}", ['size' => 'preview'], $buyer);
        self::assertSame('image/jpeg', $image->headers()['Content-Type']);
        self::assertStringStartsWith('inline', $image->headers()['Content-Disposition']);
        $stranger = $this->signup(self::OTHER)['token'];
        self::assertSame(404, $this->send('GET', "/v1/account/orders/{$orderNo}/photos/{$photoId}", null, $stranger)->status(), 'Photos are private to the order\'s buyer');

        self::assertSame(204, $this->send('DELETE', "/v1/admin/order-photos/{$photoId}", null, $production)->status());
        self::assertSame([], $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data']['photos']);
    }

    public function testReadyToShipIssuesTheBalanceInvoiceAndShippingNeedsFullPayment(): void
    {
        $onRequest = $this->app->container->get(\App\Models\CustomizationOption::class)->create(['name' => 'TEST hang tags', 'pricing' => 'on_request', 'is_active' => true]);
        [$buyer, $orderNo, $orderId] = $this->confirmedOrder('sea', [$onRequest]);
        $sales = $this->staffLogin('sales');

        $unpriced = $this->send('POST', "/v1/admin/orders/{$orderId}/ready-to-ship", null, $sales);
        self::assertSame('addons_unpriced', $unpriced->decoded()['error']['code']);
        $addonId = $this->send('GET', "/v1/admin/orders/{$orderId}", null, $sales)->decoded()['data']['addons'][0]['id'];
        // "On request" add-ons are priced as a total for the order.
        $charged = $this->send('PUT', "/v1/admin/orders/{$orderId}/charges", ['freight_cents' => 15000, 'addons' => [$addonId => 1200]], $sales)->decoded()['data'];
        self::assertSame(15000, $charged['amounts']['freight_cents']);
        self::assertSame('final', $charged['amounts']['freight_status']);
        self::assertSame(1200, $charged['amounts']['addons_cents']);

        $ready = $this->send('POST', "/v1/admin/orders/{$orderId}/ready-to-ship", null, $sales)->decoded()['data'];
        self::assertSame('ready_to_ship', $ready['status']);
        self::assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', (string) $ready['balance_invoice_no']);
        // Total $510 + $12 + $150 = $672; deposit $255 paid → balance $417.
        self::assertSame(41700, $ready['amounts']['due_now_cents']);
        self::assertStringContainsString("Your order {$orderNo} is ready to ship", $this->mailLog());
        self::assertStringContainsString("Attachments: {$ready['balance_invoice_no']}.pdf", $this->mailLog());
        self::assertStringStartsWith('%PDF-', $this->send('GET', "/v1/account/orders/{$orderNo}/invoice", null, $buyer)->body());

        $shipment = ['carrier' => 'Test Line', 'tracking_no' => 'TLU1234567', 'tracking_url' => 'https://track.example.test/TLU1234567', 'shipped_on' => gmdate('Y-m-d'), 'cartons' => 3, 'gross_kg' => 63.5];
        $unpaid = $this->send('POST', "/v1/admin/orders/{$orderId}/ship", $shipment, $sales);
        self::assertSame('not_paid', $unpaid->decoded()['error']['code']);

        $balanceId = $this->reportPayment($buyer, $orderNo, 41700, 'TX-BALANCE-1');
        $paid = $this->send('POST', "/v1/admin/payments/{$balanceId}/confirm", ['received_cents' => 41700, 'received_on' => gmdate('Y-m-d')], $this->staffLogin('admin'))->decoded()['data'];
        self::assertSame('Ready to ship, paid', $paid['status_label']);
        self::assertStringContainsString('Your order is now paid in full', $this->mailLog());

        $shipped = $this->send('POST', "/v1/admin/orders/{$orderId}/ship", $shipment, $sales)->decoded()['data'];
        self::assertSame('shipped', $shipped['status']);
        self::assertSame(63500, $shipped['shipment']['gross_g']);
        self::assertStringContainsString("Your order {$orderNo} has shipped", $this->mailLog());
        self::assertSame('TLU1234567', $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data']['shipment']['tracking_no']);

        self::assertSame('delivered', $this->send('POST', "/v1/admin/orders/{$orderId}/deliver", null, $sales)->decoded()['data']['status']);
    }

    public function testHoldResumeDueDateAndCancel(): void
    {
        [, $orderNo, $orderId] = $this->confirmedOrder();
        $sales = $this->staffLogin('sales');

        $held = $this->send('POST', "/v1/admin/orders/{$orderId}/hold", ['reason' => 'Waiting for label artwork'], $sales)->decoded()['data'];
        self::assertSame('on_hold', $held['status']);
        self::assertStringContainsString("Your order is on hold: order {$orderNo}", $this->mailLog());
        self::assertSame('confirmed', $this->send('POST', "/v1/admin/orders/{$orderId}/resume", null, $sales)->decoded()['data']['status']);

        $date = Format::local()->modify('+50 days')->format('Y-m-d');
        self::assertSame(422, $this->send('PUT', "/v1/admin/orders/{$orderId}/due-date", ['date' => '2020-01-01', 'reason' => 'Eid holidays'], $sales)->status());
        $moved = $this->send('PUT', "/v1/admin/orders/{$orderId}/due-date", ['date' => $date, 'reason' => 'Eid holidays'], $sales)->decoded()['data'];
        self::assertSame($date, $moved['production_due_on']);
        self::assertStringContainsString("New ready date for your order: order {$orderNo}", $this->mailLog());

        $cancelled = $this->send('POST', "/v1/admin/orders/{$orderId}/cancel", ['reason' => 'Buyer asked us to stop', 'notify' => true], $sales)->decoded()['data'];
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(409, $this->send('POST', "/v1/admin/orders/{$orderId}/hold", ['reason' => 'too late'], $sales)->status());
    }

    public function testDocumentsAndNotes(): void
    {
        [$buyer, $orderNo, $orderId] = $this->confirmedOrder();
        $sales = $this->staffLogin('sales');

        $public = $this->app->handle(new \App\Core\Request('POST', "/v1/admin/orders/{$orderId}/documents", [], $this->headers($sales), '', ['type' => 'commercial_invoice'], ['file' => $this->makePdf()]));
        self::assertSame(201, $public->status(), (string) json_encode($public->decoded()));
        $private = $this->app->handle(new \App\Core\Request('POST', "/v1/admin/orders/{$orderId}/documents", [], $this->headers($sales), '', ['type' => 'other', 'title' => 'Fabric invoice', 'is_public' => '0'], ['file' => $this->makePdf()]));
        self::assertSame(201, $private->status());

        $documents = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data']['documents'];
        $files = array_values(array_filter($documents, static fn (array $d): bool => $d['kind'] === 'file'));
        self::assertSame(['Commercial invoice'], array_column($files, 'title'), 'The buyer sees only the shared files');
        self::assertStringStartsWith('%PDF-', $this->send('GET', "/v1/account/orders/{$orderNo}/documents/{$files[0]['id']}", null, $buyer)->body());
        self::assertSame(404, $this->send('GET', "/v1/account/orders/{$orderNo}/documents/{$private->decoded()['data']['id']}", null, $buyer)->status());

        $this->send('POST', "/v1/admin/orders/{$orderId}/notes", ['note' => 'Buyer prefers DHL for samples'], $sales);
        $staffEvents = array_column($this->send('GET', "/v1/admin/orders/{$orderId}", null, $sales)->decoded()['data']['events'], 'note');
        self::assertContains('Buyer prefers DHL for samples', $staffEvents);
        $buyerEvents = array_column($this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data']['events'], 'note');
        self::assertNotContains('Buyer prefers DHL for samples', $buyerEvents, 'Notes are for staff only');

        self::assertSame(204, $this->send('DELETE', "/v1/admin/order-documents/{$files[0]['id']}", null, $sales)->status());
    }

    public function testStaffAccounts(): void
    {
        $admin = $this->staffLogin('admin');
        self::assertSame(403, $this->send('GET', '/v1/admin/staff', null, $this->staffLogin('sales'))->status());

        $created = $this->send('POST', '/v1/admin/staff', ['name' => 'New Packer', 'email' => 'manage-new-1@example.test', 'role' => 'production'], $admin);
        self::assertSame(201, $created->status(), (string) json_encode($created->decoded()));
        $password = $created->decoded()['data']['password'];
        $id = $created->decoded()['data']['staff']['id'];
        $login = fn (string $pw) => $this->send('POST', '/v1/admin/auth/login', ['email' => 'manage-new-1@example.test', 'password' => $pw]);
        $token = $login($password)->decoded()['data']['token'];
        self::assertSame(422, $this->send('POST', '/v1/admin/staff', ['name' => 'Again', 'email' => 'manage-new-1@example.test', 'role' => 'sales'], $admin)->status());

        $reset = $this->send('POST', "/v1/admin/staff/{$id}/reset-password", null, $admin)->decoded()['data']['password'];
        self::assertNotSame($password, $reset);
        self::assertSame(401, $this->send('GET', '/v1/admin/auth/me', null, $token)->status(), 'A reset logs the account out');
        $token = $login($reset)->decoded()['data']['token'];

        $updated = $this->send('PUT', "/v1/admin/staff/{$id}", ['name' => 'New Packer', 'role' => 'sales', 'is_active' => false], $admin)->decoded()['data'];
        self::assertFalse($updated['is_active']);
        self::assertSame(401, $this->send('GET', '/v1/admin/auth/me', null, $token)->status(), 'Deactivating ends the sessions');

        $me = $this->send('GET', '/v1/admin/auth/me', null, $admin)->decoded()['data'];
        $self = $this->send('PUT', "/v1/admin/staff/{$me['id']}", ['name' => $me['name'], 'role' => 'sales', 'is_active' => true], $admin);
        self::assertSame(422, $self->status(), 'Admins cannot remove their own access');
        self::assertContains('manage-new-1@example.test', array_column($this->send('GET', '/v1/admin/staff', null, $admin)->decoded()['data'], 'email'));
    }

    public function testDailyJobRemindsCancelsAndSummarises(): void
    {
        [$buyer, $orderNo] = $this->placedOrder();
        $orderId = (int) $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$orderNo]);
        $job = $this->app->container->get(DailyJob::class);
        $orders = $this->app->container->get(\App\Models\Order::class);
        $today = Format::local()->setTime(0, 0);
        $placedDaysAgo = fn (int $days) => $this->db->execute('UPDATE orders SET placed_at = UTC_TIMESTAMP() - INTERVAL ? DAY WHERE id = ?', [$days, $orderId]);

        self::assertNull($job->handleUnpaid((array) $orders->find($orderId), $today), 'Nothing on the first days');
        $placedDaysAgo(3);
        self::assertSame('reminded', $job->handleUnpaid((array) $orders->find($orderId), $today));
        self::assertNull($job->handleUnpaid((array) $orders->find($orderId), $today), 'One reminder per step');
        self::assertStringContainsString("Reminder: deposit for order {$orderNo}", $this->mailLog());

        $placedDaysAgo(8);
        self::assertSame('reminded', $job->handleUnpaid((array) $orders->find($orderId), $today));
        self::assertStringContainsString('the order is cancelled automatically', $this->mailLog());

        $placedDaysAgo(31);
        self::assertSame('cancelled', $job->handleUnpaid((array) $orders->find($orderId), $today));
        $view = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data'];
        self::assertSame('cancelled', $view['status']);
        self::assertStringContainsString("Order {$orderNo} was cancelled", $this->mailLog());

        $this->db->execute("DELETE FROM settings WHERE name = 'daily_job'");
        self::assertTrue($job->sendSummary($today->format('Y-m-d'), [['order_no' => $orderNo, 'company' => 'Test Denim Co']], 2));
        self::assertStringContainsString('PK Apparel today:', $this->mailLog());
        self::assertFalse($job->sendSummary($today->format('Y-m-d'), [['order_no' => $orderNo, 'company' => 'Test Denim Co']], 2), 'Once a day');
    }

    /** @return int the reported payment's id */
    private function reportPayment(string $buyer, string $orderNo, int $cents, ?string $transactionId = null): int
    {
        $response = $this->sendForm("/v1/account/orders/{$orderNo}/payments", [
            'transaction_id' => $transactionId ?? 'TX-' . bin2hex(random_bytes(4)),
            'amount_cents' => (string) $cents,
            'transferred_on' => gmdate('Y-m-d'),
            'payer_name' => 'Test Denim Co',
            'payer_bank' => 'Test Bank London',
        ], [], $buyer);
        self::assertSame(201, $response->status(), (string) json_encode($response->decoded()));

        return (int) $this->db->fetchValue(
            'SELECT p.id FROM order_payments p JOIN orders o ON o.id = p.order_id WHERE o.order_no = ? ORDER BY p.id DESC LIMIT 1',
            [$orderNo],
        );
    }

    /**
     * An order with its deposit confirmed by an admin.
     *
     * @param list<int> $addons
     * @return array{0: string, 1: string, 2: int} buyer token, order number, order id
     */
    private function confirmedOrder(string $mode = 'forwarder', array $addons = []): array
    {
        [$buyer, $orderNo] = $this->placedOrder(null, $mode, $addons);
        $due = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $buyer)->decoded()['data']['amounts']['due_now_cents'];
        $paymentId = $this->reportPayment($buyer, $orderNo, $due);
        $order = $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => $due, 'received_on' => gmdate('Y-m-d')], $this->staffLogin('admin'))->decoded()['data'];
        self::assertSame('confirmed', $order['status']);

        return [$buyer, $orderNo, (int) $order['id']];
    }

    /** @return array<string, string> request headers for a multipart request as staff */
    private function headers(string $token): array
    {
        return [
            'x-client-key' => $this->app->config()->string('client_key'),
            'x-client-ip' => $this->testIp(),
            'authorization' => 'Bearer ' . $token,
            'content-type' => 'multipart/form-data; boundary=test',
        ];
    }
}
