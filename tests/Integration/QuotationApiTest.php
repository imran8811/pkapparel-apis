<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Format;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Inquiry;
use App\Models\StaffUser;

/** Admin → Quotations: styles costed per piece, the buyer's PDF and the costing sheet (real routes, real database). */
final class QuotationApiTest extends IntegrationTestCase
{
    private const TEST_IP = '192.0.2.92'; // TEST-NET address

    private const PASSWORD = 'correct horse battery';

    /** @var array<string, string> role => email */
    private const ACCOUNTS = [
        'sales' => 'quotation-sales@example.test',
        'editor' => 'quotation-editor@example.test',
    ];

    private const INQUIRY_EMAIL = 'test-quotation-inquiry@example.test';

    /** @var list<array<string, mixed>> */
    private array $savedSequences = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        $staff = $this->app->container->get(StaffUser::class);
        foreach (self::ACCOUNTS as $role => $email) {
            $staff->create('Quotation ' . ucfirst($role), $email, self::PASSWORD, $role);
        }
        $this->savedSequences = $this->db->fetchAll('SELECT * FROM document_sequences');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        // Quotation numbers used by the test are given back.
        $this->db->execute('DELETE FROM document_sequences');
        foreach ($this->savedSequences as $row) {
            $this->db->insert('document_sequences', $row);
        }
    }

    public function testQuotationFromCostsToAcceptance(): void
    {
        $token = $this->login('sales');
        $inquiryId = $this->app->container->get(Inquiry::class)->create([
            'type' => 'quote', 'name' => 'Lena Berg', 'company' => 'TEST Berg Denim', 'email' => self::INQUIRY_EMAIL, 'message' => 'Prices for two jeans, artwork attached.',
        ]);

        $defaults = $this->send('GET', '/v1/admin/quotations/defaults', null, $token)->decoded()['data'];
        self::assertContains('PKR', $defaults['currencies']);
        self::assertSame(['cutting', 'stitching', 'washing', 'packing', 'printing', 'embroidery', 'accessories', 'overhead', 'extra'], array_column($defaults['costs'], 'key'));
        self::assertStringContainsString('pre-production sample', $defaults['terms']);

        $input = $this->input();
        $input['inquiry_id'] = $inquiryId;
        $created = $this->send('POST', '/v1/admin/quotations', $input, $token);
        self::assertSame(201, $created->status(), $created->body());
        $quotation = $created->decoded()['data'];
        $year = Format::local()->format('Y');
        self::assertMatchesRegularExpression("/^QT-{$year}-\\d{5}$/", $quotation['quotation_no']);
        self::assertSame('draft', $quotation['stage']);
        self::assertSame(['id' => $inquiryId, 'reference' => Inquiry::reference($inquiryId)], $quotation['inquiry']);

        [$jeans, $chino] = $quotation['styles'];
        // Fabric Rs 850 × 1.35 = 1,147.50; cost 1,497.50; 15% profit → Rs 1,722.13 → $6.14 at 280.5; × 1,200 pcs.
        self::assertSame(114750, $jeans['fabric_cost_cents']);
        self::assertSame(149750, $jeans['cost_cents']);
        self::assertSame(22463, $jeans['profit_cents']);
        self::assertSame(172213, $jeans['price_cents']);
        self::assertSame(614, $jeans['unit_price_cents']);
        self::assertSame(1200 * 614, $jeans['amount_cents']);
        self::assertSame('1.35', $jeans['fabric_consumption']);
        self::assertSame('15', $jeans['profit_percent']);
        // A CMT price (the buyer's own fabric) with Rs 120 profit per piece, and no quantity yet.
        self::assertSame(0, $chino['fabric_cost_cents']);
        self::assertSame(43000, $chino['cost_cents']);
        self::assertSame(55000, $chino['price_cents']);
        self::assertSame(196, $chino['unit_price_cents'], 'Rs 550 ÷ 280.5 = $1.96');
        self::assertNull($chino['amount_cents']);
        self::assertSame(1200, $quotation['total_qty'], 'Only the styles with a quantity count');
        self::assertSame(1200 * 614, $quotation['total_cents']);
        // $850 of sea freight for the whole order: its own line, on top of the styles.
        self::assertSame(85000, $quotation['shipping_cents']);
        self::assertSame(1200 * 614 + 85000, $quotation['grand_total_cents']);
        self::assertSame('280.5', $quotation['exchange_rate']);
        $id = $quotation['id'];

        $pdf = $this->send('GET', "/v1/admin/quotations/{$id}/pdf", null, $token);
        self::assertSame(200, $pdf->status());
        self::assertStringStartsWith('%PDF', $pdf->body());
        self::assertStringContainsString($quotation['quotation_no'] . '.pdf', (string) ($pdf->headers()['Content-Disposition'] ?? ''));
        $costing = $this->send('GET', "/v1/admin/quotations/{$id}/costing", null, $token);
        self::assertSame(200, $costing->status());
        self::assertStringStartsWith('%PDF', $costing->body());
        self::assertStringContainsString($quotation['quotation_no'] . '-costing.pdf', (string) ($costing->headers()['Content-Disposition'] ?? ''));

        // In rupees: the exchange rate is not needed and prices round to the rupee.
        $rupees = $this->input();
        $rupees['currency'] = 'PKR';
        $rupees['exchange_rate'] = '';
        $rupees['shipping_cents'] = 2500000;
        $updated = $this->send('PUT', "/v1/admin/quotations/{$id}", $rupees, $token)->decoded()['data'];
        self::assertSame(172200, $updated['styles'][0]['unit_price_cents']);
        self::assertNull($updated['exchange_rate']);
        self::assertSame(1200 * 172200 + 2500000, $updated['grand_total_cents'], 'Rs 25,000 of cargo on top');
        self::assertSame($quotation['quotation_no'], $updated['quotation_no'], 'Editing keeps the number');
        self::assertNull($updated['inquiry'], 'Saved without the inquiry, the link goes');

        $input['currency'] = 'USD';
        $this->send('PUT', "/v1/admin/quotations/{$id}", $input, $token);
        $sent = $this->send('POST', "/v1/admin/quotations/{$id}/send", ['to' => 'Lena@Berg.example', 'message' => 'As discussed on the phone.'], $token);
        self::assertSame(200, $sent->status(), $sent->body());
        self::assertSame('sent', $sent->decoded()['data']['stage']);
        self::assertSame('lena@berg.example', $sent->decoded()['data']['sent_to']);
        $mail = $this->mailLog();
        self::assertStringContainsString("Subject: Quotation {$quotation['quotation_no']} | PK Apparel", $mail);
        self::assertStringContainsString("Attachments: {$quotation['quotation_no']}.pdf (application/pdf", $mail);
        self::assertStringContainsString('As discussed on the phone.', $mail);
        self::assertStringNotContainsString('1,147.50', $mail, 'The buyer never sees the costs');
        self::assertStringContainsString('Shipping for the whole order', $mail);
        self::assertStringContainsString('$8,218.00', $mail, 'The total with the shipping: $7,368 + $850');
        self::assertSame('quoted', $this->db->fetchValue('SELECT status FROM inquiries WHERE id = ?', [$inquiryId]), 'The inquiry is now "Quote sent"');
        self::assertSame(422, $this->send('POST', "/v1/admin/quotations/{$id}/send", ['to' => 'nope'], $token)->status());

        $accepted = $this->send('POST', "/v1/admin/quotations/{$id}/status", ['status' => 'accepted'], $token)->decoded()['data'];
        self::assertSame('accepted', $accepted['stage']);
        self::assertNotNull($accepted['decided_at']);
        self::assertSame(409, $this->send('PUT', "/v1/admin/quotations/{$id}", $input, $token)->status(), 'An accepted quotation is reopened before editing');
        self::assertStringStartsWith('%PDF', $this->send('GET', "/v1/admin/quotations/{$id}/pdf", null, $token)->body());
        self::assertSame(422, $this->send('POST', "/v1/admin/quotations/{$id}/status", ['status' => 'won'], $token)->status());
        $reopened = $this->send('POST', "/v1/admin/quotations/{$id}/status", ['status' => 'open'], $token)->decoded()['data'];
        self::assertSame('sent', $reopened['stage']);
        self::assertNull($reopened['decided_at']);

        $copy = $this->send('POST', "/v1/admin/quotations/{$id}/duplicate", null, $token);
        self::assertSame(201, $copy->status(), $copy->body());
        $revised = $copy->decoded()['data'];
        self::assertNotSame($quotation['quotation_no'], $revised['quotation_no']);
        self::assertSame('draft', $revised['stage']);
        self::assertSame(array_column($quotation['styles'], 'unit_price_cents'), array_column($revised['styles'], 'unit_price_cents'));

        $listed = $this->send('GET', '/v1/admin/quotations', ['q' => 'ber-5021'], $token)->decoded();
        self::assertEqualsCanonicalizing([$quotation['quotation_no'], $revised['quotation_no']], array_column($listed['data'], 'quotation_no'), 'Found by the buyer\'s article no.');
        self::assertSame(2, $listed['data'][0]['styles_count']);
        self::assertSame('BER-5021, BER-7710', $listed['data'][0]['article_nos']);
        self::assertSame(2, $this->send('GET', '/v1/admin/quotations', ['q' => 'test berg'], $token)->decoded()['meta']['pagination']['total'], 'And by the buyer, in any case');
        $this->send('POST', "/v1/admin/quotations/{$revised['id']}/status", ['status' => 'declined'], $token);
        self::assertSame([$revised['quotation_no']], array_column($this->send('GET', '/v1/admin/quotations', ['q' => 'test berg', 'status' => 'declined'], $token)->decoded()['data'], 'quotation_no'));
    }

    public function testExpiredAndValidation(): void
    {
        $token = $this->login('sales');
        $old = $this->input();
        $old['issue_date'] = Format::local()->modify('-40 days')->format('Y-m-d');
        $old['valid_until'] = Format::local()->modify('-10 days')->format('Y-m-d');
        $expired = $this->send('POST', '/v1/admin/quotations', $old, $token)->decoded()['data'];
        self::assertSame('expired', $expired['stage']);
        self::assertContains($expired['quotation_no'], array_column($this->send('GET', '/v1/admin/quotations', ['status' => 'expired', 'q' => 'test berg'], $token)->decoded()['data'], 'quotation_no'));

        $input = $this->input();
        $input['buyer'] = ['company' => '', 'contact_name' => '', 'country_code' => ''];
        $input['exchange_rate'] = '0';
        $input['valid_until'] = Format::local()->modify('-1 day')->format('Y-m-d');
        $input['shipping_cents'] = -100;
        $input['styles'][0]['fabric_consumption'] = '1.3456';
        $input['styles'][0]['profit_percent'] = 'lots';
        $input['styles'][1]['article_no'] = '';
        $input['styles'][1]['description'] = '';
        $input['styles'][1]['fabric_rate_cents'] = 50000;
        $response = $this->send('POST', '/v1/admin/quotations', $input, $token);
        self::assertSame(422, $response->status());
        $fields = $response->decoded()['error']['fields'];
        foreach (['buyer.company', 'buyer.country_code', 'exchange_rate', 'valid_until', 'shipping_cents', 'styles.0.fabric_consumption', 'styles.0.profit_percent', 'styles.1.article_no', 'styles.1.fabric_consumption'] as $field) {
            self::assertArrayHasKey($field, $fields, $field . ' in ' . json_encode($fields));
        }
        self::assertStringContainsString('1.35', $fields['styles.0.fabric_consumption']);

        $noStyles = $this->input();
        $noStyles['styles'] = [];
        self::assertArrayHasKey('styles', $this->send('POST', '/v1/admin/quotations', $noStyles, $token)->decoded()['error']['fields']);

        $huge = $this->input();
        $huge['styles'][0]['profit_percent'] = '999';
        $huge['styles'][0]['fabric_rate_cents'] = 100000000;
        $huge['styles'][0]['fabric_consumption'] = '9999';
        self::assertArrayHasKey('styles.0.price', $this->send('POST', '/v1/admin/quotations', $huge, $token)->decoded()['error']['fields']);

        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM quotations WHERE JSON_UNQUOTE(JSON_EXTRACT(buyer, '$.company')) LIKE 'TEST%'"), 'Only the valid one was saved');

        $editor = $this->login('editor');
        self::assertSame(403, $this->send('GET', '/v1/admin/quotations', null, $editor)->status());
        self::assertSame(403, $this->send('GET', "/v1/admin/quotations/{$expired['id']}/costing", null, $editor)->status());
        self::assertSame(404, $this->send('GET', '/v1/admin/quotations/999999999', null, $token)->status());
    }

    /** @return array<string, mixed> two styles: jeans with 1,200 pieces, and a CMT chino without a quantity */
    private function input(): array
    {
        $none = ['printing_cents' => 0, 'embroidery_cents' => 0, 'extra_cents' => 0];

        return [
            'buyer' => ['company' => 'TEST Berg Denim', 'contact_name' => 'Lena Berg', 'email' => 'lena@berg.example', 'phone' => '', 'address' => '', 'country_code' => 'NO', 'tax_id' => ''],
            'currency' => 'USD',
            'exchange_rate' => '280.50',
            'issue_date' => Format::local()->format('Y-m-d'),
            'valid_until' => Format::local()->modify('+14 days')->format('Y-m-d'),
            'buyer_reference' => 'RFQ-88',
            'incoterm' => 'FOB',
            'delivery_time' => 'About 45 days after the sample approval',
            'shipping_cents' => 85000,
            'styles' => [
                [
                    'article_no' => 'BER-5021', 'description' => "Men's slim fit 5-pocket jeans", 'fabric' => '12 oz 98/2 stretch denim', 'colors' => 'Indigo rinse', 'sizes' => '28-38', 'details' => '', 'qty' => 1200,
                    'fabric_rate_cents' => 85000, 'fabric_unit' => 'm', 'fabric_consumption' => '1.35',
                    'cutting_cents' => 2500, 'stitching_cents' => 12000, 'washing_cents' => 8000, 'packing_cents' => 1500, 'accessories_cents' => 6000, 'overhead_cents' => 5000,
                    'profit_type' => 'percent', 'profit_percent' => '15',
                ] + $none,
                [
                    'article_no' => 'BER-7710', 'description' => 'Chino pant, CMT (buyer\'s fabric)', 'fabric' => '', 'colors' => 'Khaki, navy', 'sizes' => 'S-XL', 'details' => 'Fabric supplied by the buyer.', 'qty' => null,
                    'fabric_rate_cents' => 0, 'fabric_unit' => 'm', 'fabric_consumption' => '',
                    'cutting_cents' => 3000, 'stitching_cents' => 25000, 'washing_cents' => 0, 'packing_cents' => 2000, 'accessories_cents' => 8000, 'overhead_cents' => 5000,
                    'profit_type' => 'amount', 'profit_cents' => 12000, 'extra_label' => '',
                ] + $none,
            ],
            'notes' => 'Prices for the first order.',
            'terms' => "Prices are per piece.\nValid for 14 days.",
            'staff_notes' => 'Fabric rate from Kohinoor, 15 Nov.',
        ];
    }

    private function cleanUp(): void
    {
        $this->db->execute("DELETE FROM quotations WHERE JSON_UNQUOTE(JSON_EXTRACT(buyer, '$.company')) LIKE 'TEST%'");
        $this->db->execute('DELETE FROM inquiries WHERE email = ?', [self::INQUIRY_EMAIL]);
        $emails = array_values(self::ACCOUNTS);
        $in = implode(', ', array_fill(0, count($emails), '?'));
        $this->db->execute("DELETE t FROM auth_tokens t JOIN staff_users s ON s.id = t.user_id AND t.user_type = 'staff' WHERE s.email IN ({$in})", $emails);
        $this->db->execute("DELETE FROM staff_users WHERE email IN ({$in})", $emails);
        $limiter = new RateLimiter($this->db);
        foreach ($emails as $email) {
            $limiter->clear('staff-login:' . $email);
        }
        $limiter->clear('staff-login-ip:' . self::TEST_IP);
    }

    private function mailLog(): string
    {
        return (string) @file_get_contents($this->app->config()->string('paths.storage') . '/logs/mail-' . gmdate('Y-m-d') . '.log');
    }

    private function login(string $role): string
    {
        $response = $this->send('POST', '/v1/admin/auth/login', ['email' => self::ACCOUNTS[$role], 'password' => self::PASSWORD]);
        self::assertSame(200, $response->status(), $response->body());

        return $response->decoded()['data']['token'];
    }

    /** @param array<string, mixed>|null $body JSON body; for GET requests, the query string */
    private function send(string $method, string $path, ?array $body = null, ?string $token = null): Response
    {
        $headers = ['x-client-key' => $this->app->config()->string('client_key'), 'x-client-ip' => self::TEST_IP];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        if ($method === 'GET') {
            return $this->app->handle(new Request('GET', $path, $body ?? [], $headers));
        }
        $headers['content-type'] = 'application/json';

        return $this->app->handle(new Request($method, $path, [], $headers, $body === null ? '' : (string) json_encode($body)));
    }
}
