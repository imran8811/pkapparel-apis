<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Format;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Settings;
use App\Models\StaffUser;
use App\Services\Invoices;

/** Admin → Invoices: invoices made by staff for buyers outside the website (real routes, real database). */
final class InvoiceApiTest extends IntegrationTestCase
{
    private const TEST_IP = '192.0.2.91'; // TEST-NET address

    private const PASSWORD = 'correct horse battery';

    /** @var array<string, string> role => email */
    private const ACCOUNTS = [
        'sales' => 'invoice-sales@example.test',
        'editor' => 'invoice-editor@example.test',
    ];

    /** @var array<string, mixed> */
    private array $savedBank = [];

    /** @var list<array<string, mixed>> */
    private array $savedSequences = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        $staff = $this->app->container->get(StaffUser::class);
        foreach (self::ACCOUNTS as $role => $email) {
            $staff->create('Invoice ' . ucfirst($role), $email, self::PASSWORD, $role);
        }
        $settings = $this->app->container->get(Settings::class);
        $this->savedBank = (array) $settings->get('bank', []);
        $settings->set('bank', ['beneficiary' => 'PK Apparel', 'bank_name' => 'Test Bank', 'account_number' => '0001234567', 'iban' => 'PK36TEST0000001234567890', 'swift' => 'TESTPKKA'], false);
        $this->savedSequences = $this->db->fetchAll('SELECT * FROM document_sequences');
    }

    protected function tearDown(): void
    {
        $this->app->container->get(Settings::class)->set('bank', $this->savedBank, false);
        $this->cleanUp();
        // Invoice numbers used by the test are given back.
        $this->db->execute('DELETE FROM document_sequences');
        foreach ($this->savedSequences as $row) {
            $this->db->insert('document_sequences', $row);
        }
    }

    public function testSampleInvoiceFromCreationToPaid(): void
    {
        $token = $this->login('sales');

        $defaults = $this->send('GET', '/v1/admin/invoices/defaults', null, $token)->decoded()['data'];
        self::assertTrue($defaults['bank_ready']);
        self::assertStringContainsString('express courier', $defaults['terms']['sample']);

        $created = $this->send('POST', '/v1/admin/invoices', $this->sampleInput(), $token);
        self::assertSame(201, $created->status(), $created->body());
        $invoice = $created->decoded()['data'];
        $year = Format::local()->format('Y');
        self::assertMatchesRegularExpression("/^SI-{$year}-\\d{5}$/", $invoice['invoice_no']);
        self::assertSame('Sample invoice', $invoice['type_label']);
        self::assertSame('not_sent', $invoice['stage']);
        // 3 × $100 + 1 × $150 = $450, courier $85, discount $10 → $525, paid in full.
        self::assertSame(['subtotal_cents' => 45000, 'shipping_cents' => 8500, 'discount_cents' => 1000, 'total_cents' => 52500, 'received_cents' => 0, 'deposit_percent' => 100, 'due_now_cents' => 52500, 'balance_after_cents' => 0], $invoice['amounts']);
        self::assertSame([30000, 15000], array_column($invoice['lines'], 'amount_cents'));
        self::assertSame('erik@nordic.example', $invoice['buyer']['email'], 'Emails are stored in lower case');
        self::assertNull($invoice['consignee'], 'An empty consignee means "same as the buyer"');
        $id = $invoice['id'];

        $pdf = $this->send('GET', "/v1/admin/invoices/{$id}/pdf", null, $token);
        self::assertSame(200, $pdf->status());
        self::assertStringStartsWith('%PDF', $pdf->body());
        self::assertStringContainsString($invoice['invoice_no'] . '.pdf', (string) ($pdf->headers()['Content-Disposition'] ?? ''));

        $input = $this->sampleInput();
        $input['lines'][1]['qty'] = 2;
        $input['consignee'] = ['company' => 'TEST Nordic Warehouse', 'address' => "Lagergatan 7\nGöteborg", 'country_code' => 'se'];
        $updated = $this->send('PUT', "/v1/admin/invoices/{$id}", $input, $token)->decoded()['data'];
        self::assertSame(67500, $updated['amounts']['total_cents']);
        self::assertSame('SE', $updated['consignee']['country_code']);
        self::assertSame($invoice['invoice_no'], $updated['invoice_no'], 'Editing keeps the number');

        $sent = $this->send('POST', "/v1/admin/invoices/{$id}/send", ['to' => 'Erik@Nordic.example, accounts@nordic.example', 'message' => 'As discussed on WhatsApp.'], $token);
        self::assertSame(200, $sent->status(), $sent->body());
        self::assertSame('sent', $sent->decoded()['data']['stage']);
        self::assertSame('erik@nordic.example, accounts@nordic.example', $sent->decoded()['data']['sent_to']);
        $mail = $this->mailLog();
        self::assertStringContainsString("Subject: Sample invoice {$invoice['invoice_no']} | PK Apparel", $mail);
        self::assertStringContainsString("Attachments: {$invoice['invoice_no']}.pdf (application/pdf", $mail);
        self::assertStringContainsString('As discussed on WhatsApp.', $mail);
        self::assertStringContainsString('PK36TEST0000001234567890', $mail, 'The bank details are in the email');

        $badSend = $this->send('POST', "/v1/admin/invoices/{$id}/send", ['to' => 'not-an-email'], $token);
        self::assertSame(422, $badSend->status());
        self::assertArrayHasKey('to', $badSend->decoded()['error']['fields']);

        $future = Format::local()->modify('+3 days')->format('Y-m-d');
        self::assertSame(422, $this->send('POST', "/v1/admin/invoices/{$id}/paid", ['paid_on' => $future], $token)->status());
        $paid = $this->send('POST', "/v1/admin/invoices/{$id}/paid", ['paid_on' => Format::local()->format('Y-m-d'), 'payment_reference' => 'FT123'], $token)->decoded()['data'];
        self::assertSame('paid', $paid['stage']);
        self::assertSame(409, $this->send('PUT', "/v1/admin/invoices/{$id}", $input, $token)->status(), 'A paid invoice cannot be edited');
        self::assertSame(409, $this->send('POST', "/v1/admin/invoices/{$id}/cancel", null, $token)->status(), 'Nor cancelled');
        self::assertStringStartsWith('%PDF', $this->send('GET', "/v1/admin/invoices/{$id}/pdf", null, $token)->body());

        $reopened = $this->send('POST', "/v1/admin/invoices/{$id}/unpaid", null, $token)->decoded()['data'];
        self::assertSame('open', $reopened['status']);
        self::assertNull($reopened['paid_on']);

        $cancelled = $this->send('POST', "/v1/admin/invoices/{$id}/cancel", null, $token)->decoded()['data'];
        self::assertSame('cancelled', $cancelled['stage']);
        self::assertSame(409, $this->send('POST', "/v1/admin/invoices/{$id}/send", ['to' => 'erik@nordic.example'], $token)->status());
        self::assertSame(409, $this->send('POST', "/v1/admin/invoices/{$id}/paid", ['paid_on' => Format::local()->format('Y-m-d')], $token)->status());

        $list = $this->send('GET', '/v1/admin/invoices', ['q' => 'nordic test', 'status' => 'cancelled'], $token);
        self::assertSame(200, $list->status());
        $listed = $this->send('GET', '/v1/admin/invoices', ['q' => 'test nordic'], $token)->decoded();
        self::assertSame([$invoice['invoice_no']], array_column($listed['data'], 'invoice_no'), 'Search is case-insensitive on the buyer');
        self::assertSame(5, $listed['data'][0]['total_qty'], '3 + 2 pieces after the edit');
        self::assertSame(0, $this->send('GET', '/v1/admin/invoices', ['q' => 'test nordic', 'status' => 'open'], $token)->decoded()['meta']['pagination']['total']);
        self::assertGreaterThanOrEqual(1, $listed['meta']['counts']['cancelled']);
    }

    public function testProformaDepositReceivedAndDuplicate(): void
    {
        $token = $this->login('sales');
        $input = $this->sampleInput();
        $input['type'] = 'proforma';
        $input['deposit_percent'] = 30;
        $input['received_cents'] = 5000; // e.g. the samples' cost, deducted
        $created = $this->send('POST', '/v1/admin/invoices', $input, $token);
        self::assertSame(201, $created->status(), $created->body());
        $invoice = $created->decoded()['data'];
        self::assertStringStartsWith('PI-', $invoice['invoice_no']);
        // Total $525: 30% is $157.50, less $50 received = $107.50 now; $367.50 stays for later.
        self::assertSame(10750, $invoice['amounts']['due_now_cents']);
        self::assertSame(36750, $invoice['amounts']['balance_after_cents']);

        $final = $this->send('POST', "/v1/admin/invoices/{$invoice['id']}/duplicate", ['type' => 'commercial'], $token);
        self::assertSame(201, $final->status(), $final->body());
        $copy = $final->decoded()['data'];
        self::assertStringStartsWith('INV-', $copy['invoice_no']);
        self::assertSame(100, $copy['amounts']['deposit_percent']);
        self::assertSame(52500, $copy['amounts']['due_now_cents'], 'A copy starts with nothing received');
        self::assertSame($invoice['lines'], $copy['lines']);
        self::assertSame(Format::local()->format('Y-m-d'), $copy['issue_date']);
        self::assertStringContainsString('Goods ship once the full amount is received.', (string) $copy['terms']);
    }

    public function testValidationAndAccess(): void
    {
        $token = $this->login('sales');
        $input = $this->sampleInput();
        $input['buyer'] = ['company' => '', 'contact_name' => '', 'email' => 'nope', 'country_code' => 'Sweden'];
        $input['consignee'] = ['company' => 'TEST Warehouse', 'country_code' => 'SE'];
        $input['lines'][0]['qty'] = 0;
        $input['lines'][1]['description'] = '';
        $input['lines'][1]['hs_code'] = 'abc';
        $input['issue_date'] = '2026-11-14';
        $input['due_date'] = '2026-11-01';
        $input['currency'] = 'JPY';
        $response = $this->send('POST', '/v1/admin/invoices', $input, $token);
        self::assertSame(422, $response->status());
        $fields = $response->decoded()['error']['fields'];
        foreach (['buyer.company', 'buyer.email', 'buyer.country_code', 'consignee.address', 'lines.0.qty', 'lines.1.description', 'lines.1.hs_code', 'currency'] as $field) {
            self::assertArrayHasKey($field, $fields, $field . ' in ' . json_encode($fields));
        }

        $noLines = $this->sampleInput();
        $noLines['lines'] = [];
        self::assertArrayHasKey('lines', $this->send('POST', '/v1/admin/invoices', $noLines, $token)->decoded()['error']['fields']);

        $tooMuchOff = $this->sampleInput();
        $tooMuchOff['discount_cents'] = 999999;
        self::assertArrayHasKey('discount_cents', $this->send('POST', '/v1/admin/invoices', $tooMuchOff, $token)->decoded()['error']['fields']);

        $noType = $this->sampleInput();
        unset($noType['type']);
        self::assertArrayHasKey('type', $this->send('POST', '/v1/admin/invoices', $noType, $token)->decoded()['error']['fields']);

        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM invoices WHERE JSON_UNQUOTE(JSON_EXTRACT(buyer, '$.company')) LIKE 'TEST%'"));

        $editor = $this->login('editor');
        self::assertSame(403, $this->send('GET', '/v1/admin/invoices', null, $editor)->status());
        self::assertSame(403, $this->send('POST', '/v1/admin/invoices', $this->sampleInput(), $editor)->status());
        self::assertSame(404, $this->send('GET', '/v1/admin/invoices/999999999', null, $token)->status());
    }

    public function testTotals(): void
    {
        $lines = [['qty' => 3, 'unit_price_cents' => 333], ['qty' => 1, 'unit_price_cents' => 1]];
        self::assertSame(['subtotal_cents' => 1000, 'total_cents' => 1100, 'due_now_cents' => 1100, 'lines' => [999, 1]], Invoices::totals('sample', $lines, 100, 0, 0, 100));
        // A proforma's deposit already covered by what was received leaves nothing due now.
        self::assertSame(0, Invoices::totals('proforma', $lines, 0, 0, 600, 50)['due_now_cents']);
        self::assertSame(400, Invoices::totals('commercial', $lines, 0, 0, 600, 100)['due_now_cents']);
    }

    /** @return array<string, mixed> */
    private function sampleInput(): array
    {
        return [
            'type' => 'sample',
            'buyer' => ['company' => 'TEST Nordic Denim AB', 'contact_name' => 'Erik Lindqvist', 'email' => 'Erik@Nordic.example', 'phone' => '+46 8 123 456', 'address' => "Sveavägen 44\nStockholm", 'country_code' => 'SE', 'tax_id' => 'SE5566778899'],
            'consignee' => ['company' => '', 'address' => '', 'country_code' => ''],
            'currency' => 'USD',
            'issue_date' => Format::local()->format('Y-m-d'),
            'due_date' => Format::local()->modify('+7 days')->format('Y-m-d'),
            'incoterm' => 'DAP',
            'shipping_method' => 'DHL Express',
            'delivery_time' => 'About 7 days after payment',
            'lines' => [
                ['description' => 'Slim fit jeans', 'details' => 'Indigo · 30, 32, 34', 'hs_code' => '6203.42', 'qty' => 3, 'unit' => 'pcs', 'unit_price_cents' => 10000],
                ['description' => 'Custom-design sample', 'details' => '', 'hs_code' => '', 'qty' => 1, 'unit' => '', 'unit_price_cents' => 15000],
            ],
            'shipping_cents' => 8500,
            'discount_cents' => 1000,
            'notes' => 'Samples for approval.',
            'terms' => 'Paid in full before dispatch.',
        ];
    }

    private function cleanUp(): void
    {
        $this->db->execute("DELETE FROM invoices WHERE JSON_UNQUOTE(JSON_EXTRACT(buyer, '$.company')) LIKE 'TEST%'");
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
