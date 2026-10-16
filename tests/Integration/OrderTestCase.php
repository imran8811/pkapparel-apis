<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use App\Models\CustomizationOption;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\Settings;
use App\Models\ShippingZone;
use App\Models\StaffUser;

/**
 * Fixtures for order tests: a test style with two colors (TST-A $4.00, TST-B $4.50; minimum 60, lead time 35 days),
 * a freight zone for Antarctica (AQ), a priced add-on, test bank details and staff accounts.
 * Document numbers and the daily job's state are restored afterwards.
 */
abstract class OrderTestCase extends BuyerApiTestCase
{
    protected int $productId = 0;

    protected int $colorA = 0; // style price $4.00

    protected int $colorB = 0; // own price $4.50

    protected int $addonId = 0; // $0.12 per piece

    /** @var array<string, mixed> */
    private array $savedBank = [];

    /** @var list<array<string, mixed>> */
    private array $savedSequences = [];

    private mixed $savedDailyJob = null;

    /** @return array<string, string> role => email of the staff accounts this test uses */
    abstract protected function staffEmails(): array;

    /** The slug of this test's style (unique per test class). */
    abstract protected function fixtureSlug(): string;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpFixtures();
        $container = $this->app->container;

        $this->productId = $container->get(Product::class)->create([
            'slug' => $this->fixtureSlug(), 'name' => 'TEST Order Jeans', 'style_no' => 'TST-1', 'segment' => 'men',
            'product_type' => 'jeans-pant', 'description_html' => '', 'features' => [], 'sizes' => ['S', 'M', 'L'], 'price_cents' => 400,
            'moq_per_color' => 60, 'lead_time_days' => 35, 'piece_weight_g' => 500, 'pcs_per_carton' => 40,
            'carton_length_mm' => 600, 'carton_width_mm' => 400, 'carton_height_mm' => 300, 'carton_weight_g' => 1000, 'status' => 'active',
        ]);
        $container->get(Product::class)->replaceTiers($this->productId, [['min_qty' => 200, 'price_cents' => 350]]);
        $colors = $container->get(ProductColor::class);
        $color = ['spec_overrides' => [], 'is_active' => true, 'price_cents' => null];
        $this->colorA = $colors->create($this->productId, ['code' => 'TST-A', 'name' => 'Test indigo', 'slug' => 'test-indigo', 'hex' => '#1f3a93', 'sort_order' => 10] + $color, []);
        $this->colorB = $colors->create($this->productId, ['code' => 'TST-B', 'name' => 'Test black', 'slug' => 'test-black', 'hex' => '#111111', 'sort_order' => 20, 'price_cents' => 450] + $color, []);

        $container->get(ShippingZone::class)->save(null, 'TEST zone', 0, ['AQ'], [
            ['mode' => 'air', 'from_qty' => 0, 'rate_cents' => 600, 'fixed_cents' => 2500, 'transit_min_days' => 6, 'transit_max_days' => 9],
            ['mode' => 'air', 'from_qty' => 100, 'rate_cents' => 450, 'fixed_cents' => 2500, 'transit_min_days' => 6, 'transit_max_days' => 9],
            ['mode' => 'sea', 'from_qty' => 0, 'rate_cents' => 9000, 'fixed_cents' => 10000, 'transit_min_days' => 30, 'transit_max_days' => 40],
        ]);
        $this->addonId = $container->get(CustomizationOption::class)->create(['name' => 'TEST labels', 'pricing' => 'per_piece', 'price_cents' => 12, 'is_active' => true]);

        $settings = $container->get(Settings::class);
        $this->savedBank = (array) $settings->get('bank', []);
        $this->savedDailyJob = $settings->get('daily_job');
        $settings->set('bank', ['beneficiary' => 'PK Apparel', 'bank_name' => 'Test Bank', 'account_number' => '0001234567', 'iban' => 'PK36TEST0000001234567890', 'swift' => 'TESTPKKA'], false);
        $this->savedSequences = $this->db->fetchAll('SELECT * FROM document_sequences');

        foreach ($this->staffEmails() as $role => $email) {
            $container->get(StaffUser::class)->create('Order ' . ucfirst($role), $email, self::PASSWORD, $role);
        }
    }

    protected function tearDown(): void
    {
        $settings = $this->app->container->get(Settings::class);
        $settings->set('bank', $this->savedBank, false);
        if ($this->savedDailyJob === null) {
            $this->db->execute("DELETE FROM settings WHERE name = 'daily_job'");
        } else {
            $settings->set('daily_job', $this->savedDailyJob, false);
        }
        parent::tearDown();
        // Receipt and invoice numbers used by the test are given back.
        $this->db->execute('DELETE FROM document_sequences');
        foreach ($this->savedSequences as $row) {
            $this->db->insert('document_sequences', $row);
        }
        $this->cleanUpFixtures();
    }

    private function cleanUpFixtures(): void
    {
        $this->db->execute('DELETE FROM products WHERE slug = ?', [$this->fixtureSlug()]);
        $this->db->execute("DELETE FROM shipping_zones WHERE name LIKE 'TEST %'");
        $this->db->execute("DELETE FROM customization_options WHERE name LIKE 'TEST %'");
        $limiter = new RateLimiter($this->db);
        foreach ($this->staffEmails() as $email) {
            $this->db->execute("DELETE t FROM auth_tokens t JOIN staff_users s ON s.id = t.user_id AND t.user_type = 'staff' WHERE s.email = ?", [$email]);
            $this->db->execute('DELETE FROM staff_users WHERE email = ?', [$email]);
            $limiter->clear('staff-login:' . $email);
        }
        $limiter->clear('staff-login-ip:' . $this->testIp());
    }

    /** @return array{0: string, 1: int} a verified buyer's token and their address in the test zone */
    protected function readyBuyer(string $email): array
    {
        $token = $this->signup($email)['token'];
        $this->verify($email);
        $addressId = $this->address($token);
        $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorA, 'size_qty' => ['S' => 20, 'M' => 20, 'L' => 20]], $token);
        $this->send('POST', '/v1/cart/lines', ['color_id' => $this->colorB, 'size_qty' => ['S' => 20, 'M' => 20, 'L' => 20]], $token);

        return [$token, $addressId];
    }

    /**
     * A placed order: 60 × $4.00 + 60 × $4.50 = $510.00 of goods, deposit $255.00 (plus half of any priced add-ons).
     *
     * @param list<int> $addons
     * @return array{0: string, 1: string} buyer token and order number
     */
    protected function placedOrder(?string $email = null, string $mode = 'forwarder', array $addons = []): array
    {
        [$token, $addressId] = $this->readyBuyer($email ?? $this->testEmails()[0]);
        $placed = $this->send('POST', '/v1/account/orders', ['address_id' => $addressId, 'shipping_mode' => $mode, 'addons' => $addons, 'accept_terms' => true], $token);
        self::assertSame(201, $placed->status(), (string) json_encode($placed->decoded()));

        return [$token, $placed->decoded()['data']['order_no']];
    }

    protected function address(string $token): int
    {
        $response = $this->send('POST', '/v1/account/addresses', [
            'company' => 'Test Denim Co', 'contact_name' => 'Warehouse Team', 'phone' => '+44 20 7946 0001',
            'line1' => '1 Research Station', 'city' => 'McMurdo', 'country_code' => 'AQ',
        ], $token);
        self::assertSame(201, $response->status());

        return (int) $response->decoded()['data']['id'];
    }

    protected function staffLogin(string $role = 'sales'): string
    {
        $response = $this->send('POST', '/v1/admin/auth/login', ['email' => $this->staffEmails()[$role], 'password' => self::PASSWORD]);
        self::assertSame(200, $response->status());

        return $response->decoded()['data']['token'];
    }

    /** @return array<string, mixed> an uploaded-file entry with a small PNG */
    protected function makeSlip(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'slip');
        $image = imagecreatetruecolor(120, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        imagepng($image, $file);

        return ['name' => 'transfer slip.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    /** @return array<string, mixed> an uploaded-file entry with a minimal PDF */
    protected function makePdf(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return ['name' => 'Commercial invoice.pdf', 'type' => 'application/pdf', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    /** The text of today's mail log. */
    protected function mailLog(): string
    {
        return (string) @file_get_contents($this->app->config()->string('paths.storage') . '/logs/mail-' . gmdate('Y-m-d') . '.log');
    }
}
