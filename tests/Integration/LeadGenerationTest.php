<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Format;
use App\Core\RateLimiter;
use App\Models\Order;
use App\Services\DailyJob;
use App\Services\InquiryAttachments;
use App\Core\Response;

/**
 * Phase 5: files attached to quote requests, and sample orders: priced by the courier rate at once,
 * or priced by staff first (custom-design samples).
 */
final class LeadGenerationTest extends OrderTestCase
{
    private const BUYER = 'lead-buyer@example.test';

    protected function testIp(): string
    {
        return '192.0.2.85';
    }

    protected function testEmails(): array
    {
        return [self::BUYER];
    }

    protected function staffEmails(): array
    {
        return ['admin' => 'lead-admin@example.test', 'sales' => 'lead-sales@example.test'];
    }

    protected function fixtureSlug(): string
    {
        return 'test-style-lead-generation';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpRequests();
        // Samples of the test style cost $100 per piece (owner decision), whatever the settings say.
        $this->db->execute('UPDATE products SET sample_price_cents = 10000 WHERE id = ?', [$this->productId]);
        // A courier rate for the test zone: $30 plus $5 per piece, 3 to 5 days.
        $this->db->execute(
            "INSERT INTO shipping_rates (zone_id, mode, from_qty, rate_cents, fixed_cents, transit_min_days, transit_max_days)
             SELECT id, 'courier', 0, 500, 3000, 3, 5 FROM shipping_zones WHERE name = 'TEST zone'",
        );
    }

    protected function tearDown(): void
    {
        $this->cleanUpRequests();
        parent::tearDown();
    }

    public function testQuoteRequestsCarryUploadedFiles(): void
    {
        $pdf = $this->upload($this->makePdf());
        self::assertSame(201, $pdf->status(), (string) json_encode($pdf->decoded()));
        self::assertSame('application/pdf', $pdf->decoded()['data']['mime']);
        $sheet = $this->upload($this->makeXlsx());
        self::assertSame(201, $sheet->status(), (string) json_encode($sheet->decoded()));
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $sheet->decoded()['data']['mime']);
        self::assertSame(422, $this->upload($this->makeXlsx(true))->status(), 'Office files with macros are refused');
        self::assertSame(422, $this->upload($this->makeText())->status(), 'Only PDF, Office files and photos');

        $request = [
            'type' => 'quote', 'name' => 'Lena Lead', 'email' => self::BUYER, 'country' => 'DE',
            'message' => 'Private label jeans: our tech pack is attached.',
            'details' => ['product' => 'Jeans pants', 'quantity' => '600', 'fit' => 'Slim'],
            'link' => 'https://example.test/big-files',
            'files' => [$pdf->decoded()['data']['token'], $sheet->decoded()['data']['token']],
        ];
        self::assertSame(422, $this->send('POST', '/v1/inquiries', ['link' => 'ftp://example.test/files'] + $request)->status(), 'Links start with http(s)');
        $sent = $this->send('POST', '/v1/inquiries', $request);
        self::assertSame(201, $sent->status(), (string) json_encode($sent->decoded()));
        $id = $sent->decoded()['data']['id'];
        self::assertStringContainsString('Attached files', $this->mailLog());
        self::assertStringContainsString('Tech pack.xlsx (1 KB)', $this->mailLog());
        self::assertStringContainsString('Files we received: Commercial invoice.pdf, Tech pack.xlsx', $this->mailLog());
        self::assertSame(422, $this->send('POST', '/v1/inquiries', $request)->status(), 'A file is attached only once');

        $staff = $this->staffLogin('sales');
        $inquiry = $this->send('GET', "/v1/admin/inquiries/{$id}", null, $staff)->decoded()['data'];
        self::assertSame(['Commercial invoice.pdf', 'Tech pack.xlsx'], array_column($inquiry['files'], 'name'));
        self::assertSame('https://example.test/big-files', $inquiry['details']['link']);
        $file = $this->send('GET', "/v1/admin/inquiry-files/{$inquiry['files'][0]['id']}", null, $staff);
        self::assertSame(200, $file->status());
        self::assertStringStartsWith('%PDF-', $file->body());

        // Uploads never sent with a form are deleted the next day.
        $stale = $this->upload($this->makePdf())->decoded()['data']['token'];
        $this->db->execute('UPDATE inquiry_files SET created_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE upload_token_hash = ?', [hash('sha256', $stale)]);
        self::assertGreaterThanOrEqual(1, $this->app->container->get(InquiryAttachments::class)->deleteStale());
        self::assertNull($this->db->fetch('SELECT id FROM inquiry_files WHERE upload_token_hash = ?', [hash('sha256', $stale)]));
    }

    public function testASampleOrderPricedByTheCourierRate(): void
    {
        [$token, $addressId] = $this->readyBuyer(self::BUYER);
        $items = [['color_id' => $this->colorA, 'size' => 'S', 'qty' => 1], ['color_id' => $this->colorB, 'size' => 'M', 'qty' => 2]];

        $quote = $this->send('POST', '/v1/account/sample-orders/quote', ['items' => $items, 'address_id' => $addressId], $token)->decoded()['data'];
        // 3 samples × $100, courier $30 + 3 × $5.
        self::assertSame(30000, $quote['goods_cents']);
        self::assertSame(4500, $quote['courier']['cost_cents']);
        self::assertSame(34500, $quote['total_cents']);
        self::assertFalse($quote['needs_price']);
        self::assertSame(7, $quote['lead_time_days']);

        $quoteFor = fn (array $items): int => $this->send('POST', '/v1/account/sample-orders/quote', ['items' => $items], $token)->status();
        self::assertSame(422, $quoteFor([['color_id' => $this->colorA, 'size' => 'S', 'qty' => 4]]), 'At most 3 per color and size');
        self::assertSame(422, $quoteFor([['color_id' => $this->colorA, 'size' => 'XXL', 'qty' => 1]]), 'Only the style\'s sizes');
        self::assertSame(422, $quoteFor([
            ['color_id' => $this->colorA, 'size' => 'S', 'qty' => 3], ['color_id' => $this->colorA, 'size' => 'M', 'qty' => 3],
            ['color_id' => $this->colorA, 'size' => 'L', 'qty' => 3], ['color_id' => $this->colorB, 'size' => 'S', 'qty' => 2],
        ]), 'At most 10 pieces');

        $placed = $this->send('POST', '/v1/account/sample-orders', ['items' => $items, 'address_id' => $addressId, 'accept_terms' => true, 'expected_total_cents' => 34500], $token);
        self::assertSame(201, $placed->status(), (string) json_encode($placed->decoded()));
        $order = $placed->decoded()['data'];
        $orderNo = $order['order_no'];
        self::assertSame(['sample', 'awaiting_deposit', 'Awaiting payment'], [$order['kind'], $order['status'], $order['status_label']]);
        self::assertSame(34500, $order['amounts']['due_now_cents']);
        self::assertSame('final', $order['amounts']['freight_status']);
        self::assertSame('Express courier', $order['shipping_mode_label']);
        self::assertSame(['M' => 2], (array) $order['lines'][1]['size_qty']);
        self::assertSame('Sample invoice', $order['documents'][0]['title']);
        self::assertStringContainsString("Your sample order {$orderNo} and invoice", $this->mailLog());
        self::assertStringContainsString("Attachments: Sample-invoice-{$orderNo}.pdf", $this->mailLog());
        self::assertStringContainsString('New sample order from Test Denim Co', $this->mailLog());
        self::assertStringStartsWith('%PDF-', $this->send('GET', "/v1/account/orders/{$orderNo}/proforma", null, $token)->body());
        self::assertSame(120, $this->send('GET', '/v1/cart', null, $token)->decoded()['data']['total_pcs'], 'The bulk cart stays as it was');

        // Paid in full and confirmed: the samples are prepared (7 days), then shipped without a balance invoice.
        $paymentId = $this->reportPayment($token, $orderNo, 34500);
        $confirmed = $this->send('POST', "/v1/admin/payments/{$paymentId}/confirm", ['received_cents' => 34500, 'received_on' => gmdate('Y-m-d')], $this->staffLogin('admin'))->decoded()['data'];
        self::assertSame(['confirmed', 'Preparing your samples'], [$confirmed['status'], $confirmed['status_label']]);
        self::assertSame(Format::local()->modify('+7 days')->format('Y-m-d'), $confirmed['production_due_on']);
        self::assertStringContainsString('We are preparing your samples', $this->mailLog());
        self::assertSame('Payment', $confirmed['payments'][0]['kind_label']);

        $sales = $this->staffLogin('sales');
        $orderId = $confirmed['id'];
        self::assertSame(409, $this->send('POST', "/v1/admin/orders/{$orderId}/ready-to-ship", null, $sales)->status(), 'Samples have no balance invoice');
        self::assertSame(409, $this->send('PUT', "/v1/admin/orders/{$orderId}/stages/cutting", ['action' => 'start'], $sales)->status(), 'Samples have no production stages');
        self::assertSame(409, $this->send('PUT', "/v1/admin/orders/{$orderId}/charges", ['freight_cents' => 100], $sales)->status(), 'The price is fixed');
        $shipped = $this->send('POST', "/v1/admin/orders/{$orderId}/ship", ['carrier' => 'DHL Express', 'tracking_no' => 'JD0001', 'shipped_on' => gmdate('Y-m-d')], $sales)->decoded()['data'];
        self::assertSame('shipped', $shipped['status']);
        self::assertStringContainsString("Your samples for order {$orderNo} are on their way", $this->mailLog());

        $samples = $this->send('GET', '/v1/admin/orders', ['kind' => 'sample'], $sales)->decoded();
        self::assertContains($orderNo, array_column($samples['data'], 'order_no'));
        self::assertSame(['sample'], array_values(array_unique(array_column($samples['data'], 'kind'))));
    }

    public function testACustomSampleWaitsForOurPrice(): void
    {
        [$token, $addressId] = $this->readyBuyer(self::BUYER);
        $file = $this->upload($this->makePdf())->decoded()['data']['token'];
        $request = [
            'items' => [['color_id' => $this->colorA, 'size' => 'S', 'qty' => 1]],
            'custom' => ['product' => 'Jeans pants', 'description' => 'Our own fit in 12 oz rigid denim, dark rinse, our labels.', 'qty' => 1, 'files' => [$file]],
            'address_id' => $addressId,
            'accept_terms' => true,
        ];
        $quote = $this->send('POST', '/v1/account/sample-orders/quote', $request, $token)->decoded()['data'];
        self::assertTrue($quote['needs_price']);
        self::assertNull($quote['total_cents']);
        self::assertSame(14, $quote['lead_time_days']);

        $order = $this->send('POST', '/v1/account/sample-orders', $request, $token)->decoded()['data'];
        $orderNo = $order['order_no'];
        self::assertSame(['awaiting_price', 'Waiting for our price'], [$order['status'], $order['status_label']]);
        self::assertFalse($order['can_submit_payment']);
        self::assertSame(['file'], array_column($order['documents'], 'kind'), 'The design file, and no invoice before the price');
        self::assertSame('Your design file', $order['documents'][0]['type_label']);
        self::assertStringStartsWith('Custom-design sample: Our own fit', (string) $order['notes']);
        self::assertSame(409, $this->send('GET', "/v1/account/orders/{$orderNo}/proforma", null, $token)->status());
        self::assertStringContainsString("We received your sample order {$orderNo}", $this->mailLog());
        self::assertStringContainsString('needs a price', $this->mailLog());

        // Staff price the custom sample, then send the price; it is fixed from then on.
        $sales = $this->staffLogin('sales');
        $staffView = $this->send('GET', '/v1/admin/orders', ['kind' => 'sample', 'q' => $orderNo], $sales)->decoded()['data'][0];
        $orderId = $staffView['id'];
        self::assertSame(409, $this->send('POST', "/v1/admin/orders/{$orderId}/send-price", null, $sales)->status(), 'The custom sample needs a price first');
        $addonId = $this->send('GET', "/v1/admin/orders/{$orderId}", null, $sales)->decoded()['data']['addons'][0]['id'];
        self::assertSame(200, $this->send('PUT', "/v1/admin/orders/{$orderId}/charges", ['addons' => [$addonId => 9000]], $sales)->status());
        $priced = $this->send('POST', "/v1/admin/orders/{$orderId}/send-price", null, $sales)->decoded()['data'];
        // $100 sample + $90 custom sample + courier $30 + 2 × $5.
        self::assertSame(['awaiting_deposit', 23000], [$priced['status'], $priced['amounts']['due_now_cents']]);
        self::assertStringContainsString("Your sample invoice for order {$orderNo}", $this->mailLog());
        self::assertSame(409, $this->send('PUT', "/v1/admin/orders/{$orderId}/charges", ['addons' => [$addonId => 100]], $sales)->status());

        // Payment reminders count from the day the price was sent.
        $this->db->execute('UPDATE orders SET placed_at = UTC_TIMESTAMP() - INTERVAL 10 DAY, priced_at = UTC_TIMESTAMP() - INTERVAL 4 DAY WHERE id = ?', [$orderId]);
        $job = $this->app->container->get(DailyJob::class);
        $orders = $this->app->container->get(Order::class);
        $today = Format::local()->setTime(0, 0);
        self::assertSame('reminded', $job->handleUnpaid((array) $orders->find($orderId), $today));
        self::assertNull($job->handleUnpaid((array) $orders->find($orderId), $today), 'The second reminder comes after 7 days');
        self::assertStringContainsString("Reminder: payment for sample order {$orderNo}", $this->mailLog());

        // A sample order waiting for its price can be cancelled by the buyer.
        $another = $this->send('POST', '/v1/account/sample-orders', ['custom' => ['files' => []] + $request['custom'], 'address_id' => $addressId, 'accept_terms' => true], $token)->decoded()['data'];
        self::assertTrue($another['can_cancel']);
        self::assertSame(200, $this->send('POST', "/v1/account/orders/{$another['order_no']}/cancel", [], $token)->status());
    }

    public function testReviewsOfDeliveredOrders(): void
    {
        [$token, $orderNo] = $this->placedOrder(self::BUYER);
        $orderId = (int) $this->db->fetchValue('SELECT id FROM orders WHERE order_no = ?', [$orderNo]);
        self::assertFalse($this->send('GET', "/v1/account/orders/{$orderNo}", null, $token)->decoded()['data']['can_review'], 'Not before delivery');
        self::assertSame(409, $this->send('GET', "/v1/account/orders/{$orderNo}/review", null, $token)->status());
        // Delivered 8 days ago (the workflow itself is tested in OrderManagementTest).
        $this->db->execute("UPDATE orders SET status = 'delivered', delivered_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE id = ?", [$orderId]);
        self::assertTrue($this->send('GET', "/v1/account/orders/{$orderNo}", null, $token)->decoded()['data']['can_review']);

        // The daily job asks once, with a private link.
        $reviews = $this->app->container->get(\App\Services\Reviews::class);
        self::assertGreaterThanOrEqual(1, $reviews->requestDue());
        self::assertSame(0, $this->db->fetchValue("SELECT COUNT(*) FROM reviews WHERE order_id = ? AND status <> 'requested'", [$orderId]));
        $reviews->requestDue();
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM reviews WHERE order_id = ?', [$orderId]), 'One request per order');
        preg_match_all('#/review\?token=([a-f0-9]{64})#', $this->mailLog(), $links);
        $link = (string) end($links[1]);
        self::assertStringContainsString("How did order {$orderNo} go?", $this->mailLog());

        $page = $this->send('GET', "/v1/reviews/{$link}")->decoded()['data'];
        self::assertSame([$orderNo, ['TEST Order Jeans'], false], [$page['order_no'], $page['styles'], $page['submitted']]);
        self::assertSame(404, $this->send('GET', '/v1/reviews/' . str_repeat('0', 64))->status());
        $review = ['rating' => 5, 'body' => 'Great fit, and the wash was exactly as approved. Delivered on time.', 'show_as' => 'business'];
        self::assertSame(422, $this->send('POST', "/v1/reviews/{$link}", ['rating' => 6] + $review)->status());
        self::assertSame(422, $this->send('POST', "/v1/reviews/{$link}", ['body' => 'Too short.'] + $review)->status());
        self::assertSame(201, $this->send('POST', "/v1/reviews/{$link}", $review)->status());
        self::assertSame(409, $this->send('POST', "/v1/reviews/{$link}", $review)->status(), 'One review per order');
        self::assertStringContainsString("[{$orderNo}] New review from Test Denim Co: 5 of 5", $this->mailLog());
        $view = $this->send('GET', "/v1/account/orders/{$orderNo}", null, $token)->decoded()['data'];
        self::assertSame([false, 'pending'], [$view['can_review'], $view['review_status']]);

        // Nothing is public before an admin or editor approves it.
        $product = fn (): array => $this->send('GET', '/v1/products/' . $this->fixtureSlug())->decoded()['data']['reviews'];
        self::assertSame(0, $product()['count']);
        self::assertSame(403, $this->send('GET', '/v1/admin/reviews', null, $this->staffLogin('sales'))->status());
        $admin = $this->staffLogin('admin');
        $pending = $this->send('GET', '/v1/admin/reviews', null, $admin)->decoded();
        $listed = array_values(array_filter($pending['data'], static fn (array $r): bool => $r['order_no'] === $orderNo))[0];
        self::assertSame(['Brand owner', 5, ['TEST Order Jeans']], [$listed['display_name'], $listed['rating'], $listed['styles']]);
        self::assertGreaterThanOrEqual(1, $pending['meta']['counts']['pending']);

        self::assertSame(204, $this->send('POST', "/v1/admin/reviews/{$listed['id']}/approve", null, $admin)->status());
        $shown = $product();
        self::assertSame([1, 5.0], [$shown['count'], $shown['average']]);
        self::assertSame(['Brand owner', 'AQ', $review['body']], [$shown['items'][0]['name'], $shown['items'][0]['country_code'], $shown['items'][0]['body']]);
        self::assertSame('approved', $this->send('GET', "/v1/account/orders/{$orderNo}", null, $token)->decoded()['data']['review_status']);

        $featured = $this->send('POST', "/v1/admin/reviews/{$listed['id']}/feature", null, $admin);
        self::assertSame(201, $featured->status());
        $testimonial = $this->db->fetch('SELECT * FROM testimonials WHERE id = ?', [$featured->decoded()['data']['testimonial_id']]);
        self::assertSame(['Brand owner', null, 'AQ', 1], [$testimonial['author_role'], $testimonial['company'], $testimonial['country_code'], (int) $testimonial['is_published']]);
        self::assertSame(409, $this->send('POST', "/v1/admin/reviews/{$listed['id']}/feature", null, $admin)->status(), 'Featured once');

        self::assertSame(204, $this->send('POST', "/v1/admin/reviews/{$listed['id']}/hide", null, $admin)->status());
        self::assertSame(0, $product()['count']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM testimonials WHERE id = ?', [$testimonial['id']]), 'Removed from the home page too');
        self::assertNull($this->db->fetchValue('SELECT testimonial_id FROM reviews WHERE id = ?', [$listed['id']]));

        // A buyer can also write the review from the order page, without the email.
        [, $secondNo] = $this->placedOrderFor($token);
        $this->db->execute("UPDATE orders SET status = 'delivered', delivered_at = UTC_TIMESTAMP() WHERE order_no = ?", [$secondNo]);
        $written = $this->send('POST', "/v1/account/orders/{$secondNo}/review", ['show_as' => 'company'] + $review, $token);
        self::assertSame(201, $written->status(), (string) json_encode($written->decoded()));
        self::assertTrue($written->decoded()['data']['submitted']);
    }

    public function testPaidSamplesAreDeductedFromTheFirstConfirmedOrder(): void
    {
        [$token, $addressId] = $this->readyBuyer(self::BUYER);
        $admin = $this->staffLogin('admin');

        // A paid sample order: 2 samples × $100 plus the courier ($40).
        $sample = $this->send('POST', '/v1/account/sample-orders', [
            'items' => [['color_id' => $this->colorA, 'size' => 'M', 'qty' => 2]], 'address_id' => $addressId, 'accept_terms' => true,
        ], $token)->decoded()['data'];
        $samplePayment = $this->reportPayment($token, $sample['order_no'], 24000);
        $sampleConfirmed = $this->send('POST', "/v1/admin/payments/{$samplePayment}/confirm", ['received_cents' => 24000, 'received_on' => gmdate('Y-m-d')], $admin);
        self::assertSame(200, $sampleConfirmed->status(), (string) json_encode($sampleConfirmed->decoded()));

        // The bulk order shows the credit to come; it is deducted when the deposit is confirmed.
        $bulk = $this->send('POST', '/v1/account/orders', ['address_id' => $addressId, 'shipping_mode' => 'forwarder', 'accept_terms' => true], $token)->decoded()['data'];
        self::assertSame(20000, $bulk['pending_credit_cents'], 'The samples, without the courier');
        self::assertSame(0, $bulk['amounts']['credit_cents']);
        self::assertSame(25500, $bulk['amounts']['due_now_cents'], 'The deposit stays 50% of the goods');
        $deposit = $this->reportPayment($token, $bulk['order_no'], 25500);
        $confirmed = $this->send('POST', "/v1/admin/payments/{$deposit}/confirm", ['received_cents' => 25500, 'received_on' => gmdate('Y-m-d')], $admin)->decoded()['data'];
        self::assertSame('confirmed', $confirmed['status']);
        self::assertSame(20000, $confirmed['amounts']['credit_cents']);
        self::assertSame([['order_no' => $sample['order_no'], 'cents' => 20000]], $confirmed['credits']);
        // $510 of goods − $200 of samples = $310; $255 paid → $55 left for the balance.
        self::assertSame([31000, 5500], [$confirmed['amounts']['total_cents'], $confirmed['amounts']['total_cents'] - $confirmed['amounts']['paid_cents']]);
        self::assertStringContainsString("The cost of your samples ({$sample['order_no']}) is deducted from the balance: $200.00.", $this->mailLog());
        self::assertSame($bulk['order_no'], $this->send('GET', "/v1/account/orders/{$sample['order_no']}", null, $token)->decoded()['data']['credited_to']);
        self::assertStringStartsWith('%PDF-', $this->send('GET', "/v1/account/orders/{$bulk['order_no']}/proforma", null, $token)->body());

        // Only the first confirmed order gets it.
        [, $secondNo] = $this->placedOrderFor($token);
        $second = $this->send('GET', "/v1/account/orders/{$secondNo}", null, $token)->decoded()['data'];
        self::assertSame([0, 0], [$second['pending_credit_cents'], $second['amounts']['credit_cents']]);

        // Staff cancel the first order: its samples are deducted from the next order instead.
        $cancelled = $this->send('POST', "/v1/admin/orders/{$confirmed['id']}/cancel", ['reason' => 'Buyer changed the plan', 'notify' => false], $admin)->decoded()['data'];
        self::assertSame(['cancelled', 0, []], [$cancelled['status'], $cancelled['amounts']['credit_cents'], $cancelled['credits']]);
        self::assertStringContainsString("Samples {$sample['order_no']} ($200.00) will be deducted from the buyer's next order instead.", implode("\n", array_column($cancelled['events'], 'note')));
        self::assertNull($this->send('GET', "/v1/account/orders/{$sample['order_no']}", null, $token)->decoded()['data']['credited_to']);
        self::assertSame(20000, $this->send('GET', "/v1/account/orders/{$secondNo}", null, $token)->decoded()['data']['pending_credit_cents']);
    }

    /** @return array{0: string, 1: string} a second order from the same buyer (the cart is filled again) */
    private function placedOrderFor(string $token): array
    {
        $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 20, 'M' => 20, 'L' => 20]], $token);
        $addressId = (int) $this->send('GET', '/v1/account/addresses', null, $token)->decoded()['data'][0]['id'];
        $placed = $this->send('POST', '/v1/account/orders', ['address_id' => $addressId, 'shipping_mode' => 'forwarder', 'accept_terms' => true], $token);
        self::assertSame(201, $placed->status(), (string) json_encode($placed->decoded()));

        return [$token, $placed->decoded()['data']['order_no']];
    }

    private function upload(array $file): Response
    {
        return $this->sendForm('/v1/inquiry-files', [], ['file' => $file], null);
    }

    private function reportPayment(string $token, string $orderNo, int $cents): int
    {
        $response = $this->sendForm("/v1/account/orders/{$orderNo}/payments", [
            'transaction_id' => 'TX-' . bin2hex(random_bytes(4)),
            'amount_cents' => (string) $cents,
            'transferred_on' => gmdate('Y-m-d'),
            'payer_name' => 'Test Denim Co',
            'payer_bank' => 'Test Bank London',
        ], [], $token);
        self::assertSame(201, $response->status(), (string) json_encode($response->decoded()));

        return (int) $this->db->fetchValue(
            'SELECT p.id FROM order_payments p JOIN orders o ON o.id = p.order_id WHERE o.order_no = ? ORDER BY p.id DESC LIMIT 1',
            [$orderNo],
        );
    }

    /** @return array<string, mixed> an uploaded-file entry with a minimal Excel workbook (optionally with macros) */
    private function makeXlsx(bool $macros = false): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
        if ($macros) {
            $zip->addFromString('xl/vbaProject.bin', 'macro');
        }
        $zip->close();

        return ['name' => 'Tech pack.xlsx', 'type' => 'application/octet-stream', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    /** @return array<string, mixed> an uploaded-file entry with plain text */
    private function makeText(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($file, 'Just some notes.');

        return ['name' => 'notes.txt', 'type' => 'text/plain', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    /** Removes this test's requests and uploads (with their files) and its rate limits. */
    private function cleanUpRequests(): void
    {
        $storage = rtrim($this->app->config()->string('paths.storage'), '/\\') . '/private/';
        $files = $this->db->fetchAll(
            'SELECT f.path FROM inquiry_files f LEFT JOIN inquiries i ON i.id = f.inquiry_id WHERE f.ip_address = ? OR i.email = ?',
            [$this->testIp(), self::BUYER],
        );
        foreach ($files as $file) {
            @unlink($storage . $file['path']);
        }
        $this->db->execute('DELETE FROM inquiry_files WHERE ip_address = ?', [$this->testIp()]);
        $this->db->execute('DELETE FROM inquiries WHERE email = ?', [self::BUYER]);
        // Home page reviews made from this test's order reviews.
        $this->db->execute(
            'DELETE t FROM testimonials t JOIN reviews r ON r.testimonial_id = t.id JOIN orders o ON o.id = r.order_id JOIN customers c ON c.id = o.customer_id WHERE c.email = ?',
            [self::BUYER],
        );
        $limiter = new RateLimiter($this->db);
        foreach (['inquiry:', 'inquiry-upload:', 'review:'] as $bucket) {
            $limiter->clear($bucket . $this->testIp());
        }
    }
}
