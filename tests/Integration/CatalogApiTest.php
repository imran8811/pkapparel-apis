<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Category;
use App\Models\StaffUser;
use App\Services\ImageStore;

/** The shop endpoints and catalog admin, on the imported catalog (bin/import-legacy-products.php). */
final class CatalogApiTest extends IntegrationTestCase
{
    private const TEST_IP = '192.0.2.79';

    private const EDITOR = 'catalog-editor@example.test';

    private const PASSWORD = 'correct horse battery';

    private ?string $token = null;

    /** @var list<string> uploaded files to remove */
    private array $files = [];

    private ?array $savedCategory = null;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->fetchValue("SELECT COUNT(*) FROM products WHERE slug = 'mens-slim-stretch-jeans'") === 0) {
            self::markTestSkipped('Run bin/import-legacy-products.php first.');
        }
        $this->cleanUp();
        $this->app->container->get(StaffUser::class)->create('Catalog Editor', self::EDITOR, self::PASSWORD, 'editor');
    }

    protected function tearDown(): void
    {
        if ($this->savedCategory !== null) {
            $this->app->container->get(Category::class)->update($this->savedCategory['id'], $this->savedCategory);
        }
        $this->cleanUp();
        $public = dirname($this->app->config()->string('paths.uploads'));
        foreach ($this->files as $file) {
            @unlink(str_starts_with($file, 'uploads/') ? "{$public}/{$file}" : $file);
        }
    }

    private function cleanUp(): void
    {
        $this->db->execute("DELETE FROM products WHERE slug LIKE 'test-style%'");
        $this->db->execute("DELETE FROM size_charts WHERE name LIKE 'TEST %'");
        $this->db->execute("DELETE t FROM auth_tokens t JOIN staff_users s ON s.id = t.user_id AND t.user_type = 'staff' WHERE s.email = ?", [self::EDITOR]);
        $this->db->execute('DELETE FROM staff_users WHERE email = ?', [self::EDITOR]);
        (new RateLimiter($this->db))->clear('staff-login:' . self::EDITOR);
        (new RateLimiter($this->db))->clear('staff-login-ip:' . self::TEST_IP);
    }

    public function testListingAndFilters(): void
    {
        $all = $this->send('GET', '/v1/products')->decoded();
        self::assertSame(10, $all['meta']['pagination']['total']);
        $jeans = $this->card($all['data'], 'mens-slim-stretch-jeans');
        self::assertSame(500, $jeans['price_from_cents']);
        self::assertSame(550, $jeans['price_to_cents']);
        self::assertCount(8, $jeans['colors']);
        self::assertStringStartsWith('uploads/products/', $jeans['colors'][0]['image']['path']);
        self::assertNotNull($jeans['colors'][0]['hover_image'], 'Cards get a second photo for hover');

        self::assertSame(3, $this->send('GET', '/v1/products', ['segment' => 'men'])->decoded()['meta']['pagination']['total']);
        self::assertSame(['mens-slim-stretch-chinos'], array_column($this->send('GET', '/v1/products', ['type' => 'chino-pant'])->decoded()['data'], 'slug'));
        self::assertSame(['mens-slim-stretch-jeans'], array_column($this->send('GET', '/v1/products', ['q' => '1055'])->decoded()['data'], 'slug'), 'Search by old article number');
        self::assertSame(['womens-denim-maxi-skirt'], array_column($this->send('GET', '/v1/products', ['q' => 'skirt'])->decoded()['data'], 'slug'));

        $cheapest = $this->send('GET', '/v1/products', ['sort' => 'price-asc'])->decoded()['data'][0];
        self::assertSame(320, $cheapest['price_from_cents']);
        self::assertSame(422, $this->send('GET', '/v1/products', ['segment' => 'pets'])->status());
    }

    public function testProductPage(): void
    {
        $product = $this->send('GET', '/v1/products/mens-slim-stretch-jeans')->decoded()['data'];
        self::assertSame(['30', '32', '34', '36', '38', '40'], $product['sizes']);
        self::assertSame(100, $product['rules']['moq_per_color'], 'MOQ comes from the site settings');
        self::assertSame("Men's pants", $product['size_chart']['name']);
        self::assertSame([], $product['tiers']);

        $darkGray = array_values(array_filter($product['colors'], static fn (array $c): bool => $c['code'] === '1055'))[0];
        self::assertSame('Button fly', $darkGray['specs']['closure'], 'Color details override the style');
        self::assertSame('Zip fly', $product['specs']['closure']);
        self::assertSame(550, $darkGray['price_cents']);

        self::assertNotContains('mens-slim-stretch-jeans', array_column($product['related'], 'slug'));
        self::assertLessThanOrEqual(4, count($product['related']));
        self::assertSame(404, $this->send('GET', '/v1/products/does-not-exist')->status());
    }

    public function testOldArticleNumbersFindTheirStyleAndColor(): void
    {
        self::assertSame(
            ['segment' => 'men', 'product_type' => 'jeans-pant', 'slug' => 'mens-slim-stretch-jeans', 'color' => 'dark-gray'],
            $this->send('GET', '/v1/products/legacy/1055')->decoded()['data'],
        );
        self::assertSame('denim-skirt', $this->send('GET', '/v1/products/legacy/2232')->decoded()['data']['product_type']);
        self::assertSame(404, $this->send('GET', '/v1/products/legacy/99999')->status());
        // The old sitemap's numbers (10051–10076) stand for the articles in order: 10055 is 1055, 10069 is 2232.
        self::assertSame($this->send('GET', '/v1/products/legacy/1055')->decoded()['data'], $this->send('GET', '/v1/products/legacy/10055')->decoded()['data']);
        self::assertSame('denim-skirt', $this->send('GET', '/v1/products/legacy/10069')->decoded()['data']['product_type']);
    }

    public function testCategoriesCountTheirStyles(): void
    {
        $categories = $this->send('GET', '/v1/shop/categories')->decoded()['data'];
        $count = static function (string $segment, string $type) use ($categories): ?int {
            foreach ($categories as $category) {
                if ($category['segment'] === $segment && $category['product_type'] === $type) {
                    return $category['style_count'];
                }
            }

            return null;
        };
        self::assertSame(3, $count('men', ''));
        self::assertSame(2, $count('women', 'jeans-pant'));
        self::assertSame(1, $count('women', 'denim-skirt'));
        self::assertSame(0, $count('boys', ''));
        self::assertNull($count('men', 'denim-skirt'), 'No skirt page for men');
    }

    public function testStyleNeedsAColorWithAPhotoBeforeItIsShown(): void
    {
        $style = $this->admin('POST', '/v1/admin/products', $this->styleInput());
        self::assertSame(201, $style->status());
        $style = $style->decoded()['data'];
        self::assertSame('draft', $style['status'], 'New styles start as drafts');
        self::assertSame('test-style-denim-jacket', $style['slug']);

        $activate = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['status' => 'active'] + $this->styleInput());
        self::assertSame(422, $activate->status());
        self::assertArrayHasKey('status', $activate->decoded()['error']['fields']);

        $taken = $this->admin('POST', "/v1/admin/products/{$style['id']}/colors", ['code' => '1051', 'name' => 'Indigo']);
        self::assertArrayHasKey('code', $taken->decoded()['error']['fields'], 'Color codes are unique');

        $photo = $this->upload();
        $color = $this->admin('POST', "/v1/admin/products/{$style['id']}/colors", [
            'code' => 'TEST-901',
            'name' => 'Indigo rinse',
            'hex' => '#1F2A44',
            'spec_overrides' => ['wash' => 'Rinse wash', 'unknown' => 'dropped'],
            'images' => [['path' => $photo, 'alt' => 'Test jacket, front']],
        ]);
        self::assertSame(201, $color->status(), $color->body());
        $color = $color->decoded()['data'];
        self::assertSame('indigo-rinse', $color['slug']);
        self::assertSame('#1f2a44', $color['hex']);
        self::assertSame(['wash' => 'Rinse wash'], $color['spec_overrides']);
        self::assertSame(1200, $color['images'][0]['width'], 'Photo size is read from the file');

        $active = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['status' => 'active'] + $this->styleInput());
        self::assertSame(200, $active->status(), $active->body());
        $public = $this->send('GET', '/v1/products/test-style-denim-jacket')->decoded()['data'];
        self::assertSame('Rinse wash', $public['colors'][0]['specs']['wash']);

        $lastColor = $this->admin('DELETE', "/v1/admin/product-colors/{$color['id']}");
        self::assertSame(422, $lastColor->status(), 'An active style keeps at least one color with a photo');

        self::assertSame(204, $this->admin('DELETE', "/v1/admin/products/{$style['id']}")->status());
        self::assertSame(404, $this->send('GET', '/v1/products/test-style-denim-jacket')->status());
    }

    public function testStyleValidation(): void
    {
        $invalid = $this->admin('POST', '/v1/admin/products', ['name' => 'x', 'segment' => 'pets', 'product_type' => 'hats', 'price_cents' => 0, 'sizes' => []]);
        self::assertEqualsCanonicalizing(['name', 'segment', 'product_type', 'price_cents', 'sizes'], array_keys($invalid->decoded()['error']['fields']));

        $duplicateSlug = $this->admin('POST', '/v1/admin/products', ['slug' => 'mens-slim-stretch-jeans'] + $this->styleInput());
        self::assertArrayHasKey('slug', $duplicateSlug->decoded()['error']['fields']);

        $tiers = $this->admin('POST', '/v1/admin/products', ['tiers' => [['min_qty' => 500, 'price_cents' => 1300]]] + $this->styleInput());
        self::assertSame('Each volume price must be lower than the one before it.', $tiers->decoded()['error']['fields']['tiers']);

        $sizes = $this->admin('POST', '/v1/admin/products', ['sizes' => ['S', 's']] + $this->styleInput());
        self::assertArrayHasKey('sizes', $sizes->decoded()['error']['fields']);

        // Cargo trousers and chino pants are men only; denim skirts are for women and girls (owner rules).
        $cargo = $this->admin('POST', '/v1/admin/products', ['segment' => 'women', 'product_type' => 'cargo-trouser'] + $this->styleInput());
        self::assertSame('This type is only for men.', $cargo->decoded()['error']['fields']['product_type']);
        $skirt = $this->admin('POST', '/v1/admin/products', ['segment' => 'boys', 'product_type' => 'denim-skirt'] + $this->styleInput());
        self::assertSame('This type is only for women and girls.', $skirt->decoded()['error']['fields']['product_type']);
    }

    public function testSizeChartsAndCategoryTexts(): void
    {
        $bad = $this->admin('POST', '/v1/admin/size-charts', [
            'name' => 'TEST kids', 'unit' => 'cm', 'measurements' => ['Waist', 'Length'], 'size_rows' => [['size' => '4Y', 'values' => ['52']]],
        ]);
        self::assertArrayHasKey('size_rows.0.values', $bad->decoded()['error']['fields']);

        $chart = $this->admin('POST', '/v1/admin/size-charts', [
            'name' => 'TEST kids', 'unit' => 'cm', 'measurements' => ['Waist', 'Length'],
            'size_rows' => [['size' => '4Y', 'values' => ['52', '58']], ['size' => '6Y', 'values' => ['55', '65']]],
        ]);
        self::assertSame(201, $chart->status());
        self::assertSame(['size' => '6Y', 'values' => ['55', '65']], $chart->decoded()['data']['size_rows'][1]);

        $categories = $this->admin('GET', '/v1/admin/categories')->decoded()['data'];
        $boysJeans = array_values(array_filter($categories, static fn (array $c): bool => $c['segment'] === 'boys' && $c['product_type'] === 'jeans-pant'))[0];
        $this->savedCategory = $boysJeans;
        $updated = $this->admin('PUT', "/v1/admin/categories/{$boysJeans['id']}", [
            'title' => "Boys' jeans pants",
            'intro_html' => '<p onclick="x()">Made to order.</p><script>alert(1)</script>',
            'faq' => [['question' => 'Do you make boys jeans?', 'answer' => 'Yes.']],
        ])->decoded()['data'];
        self::assertSame('<p>Made to order.</p>', $updated['intro_html']);
        self::assertSame([['question' => 'Do you make boys jeans?', 'answer' => 'Yes.']], $updated['faq']);
    }

    /** @return array<string, mixed> */
    private function styleInput(): array
    {
        return [
            'name' => 'Test style denim jacket',
            'slug' => 'test-style-denim-jacket',
            'segment' => 'men',
            'product_type' => 'denim-jacket',
            'fit' => 'Regular',
            'description_html' => '<p>Classic trucker jacket.</p>',
            'features' => ['Chest pockets', ''],
            'sizes' => ['S', 'M', 'L'],
            'price_cents' => 1250,
        ];
    }

    private function upload(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pk') . '.jpg';
        $image = imagecreatetruecolor(1200, 1600);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 42, 68));
        imagejpeg($image, $file);
        $this->files[] = $file;
        $stored = $this->app->container->get(ImageStore::class)->store($file, 'test-jacket.jpg', 'products');
        $this->files[] = $stored['path'];

        return $stored['path'];
    }

    /** @param list<array<string, mixed>> $cards */
    private function card(array $cards, string $slug): array
    {
        foreach ($cards as $card) {
            if ($card['slug'] === $slug) {
                return $card;
            }
        }
        self::fail("No card for {$slug}");
    }

    /** @param array<string, mixed>|null $body JSON body; for GET, the query string */
    private function admin(string $method, string $path, ?array $body = null): Response
    {
        if ($this->token === null) {
            $login = $this->send('POST', '/v1/admin/auth/login', ['email' => self::EDITOR, 'password' => self::PASSWORD]);
            $this->token = $login->decoded()['data']['token'];
        }

        return $this->send($method, $path, $body, $this->token);
    }

    /** @param array<string, mixed>|null $body */
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
