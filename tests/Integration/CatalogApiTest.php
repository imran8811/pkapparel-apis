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
        self::assertSame(7, $this->send('GET', '/v1/products', ['segment' => 'women'])->decoded()['meta']['pagination']['total'], 'The former girls styles are women\'s');
        self::assertSame(422, $this->send('GET', '/v1/products', ['segment' => 'girls'])->status(), 'Men and women only');
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
        self::assertSame('waist', $product['size_system']);
        self::assertSame(['30', '32', '34', '36', '38', '40'], $product['sizes']);
        self::assertSame(
            ['fit' => 'Fit', 'rise' => 'Rise', 'length' => 'Inseam', 'fabric' => 'Fabric', 'composition' => 'Composition', 'fabric_weight' => 'Fabric weight', 'stretch' => 'Stretch', 'wash' => 'Wash', 'closure' => 'Closure'],
            array_column($product['spec_fields'], 'label', 'key'),
            'Jeans show their own specs, labeled for jeans',
        );
        self::assertSame('stretch', $product['specs']['stretch']);
        self::assertNull($product['specs']['rise']);

        $flare = $this->send('GET', '/v1/products/womens-daisy-print-flare-jeans')->decoded()['data'];
        self::assertSame(['women', ['26', '28', '30', '32', '34', '36']], [$flare['segment'], $flare['sizes']], 'Former girls style, in women\'s sizes');
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
        self::assertSame(6, $count('women', 'jeans-pant'));
        self::assertSame(1, $count('women', 'denim-skirt'));
        self::assertSame(0, $count('men', 't-shirt'));
        self::assertSame(0, $count('women', 'polo-shirt'));
        self::assertNull($count('boys', ''), 'Men and women only');
        self::assertNull($count('girls', 'jeans-pant'));
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
        self::assertSame('Each size can only be listed once.', $sizes->decoded()['error']['fields']['sizes']);

        // Cargo trousers and chino pants are men only; denim skirts are women only (owner rules).
        $cargo = $this->admin('POST', '/v1/admin/products', ['segment' => 'women', 'product_type' => 'cargo-trouser'] + $this->styleInput());
        self::assertSame('This type is only for men.', $cargo->decoded()['error']['fields']['product_type']);
        $skirt = $this->admin('POST', '/v1/admin/products', ['segment' => 'men', 'product_type' => 'denim-skirt'] + $this->styleInput());
        self::assertSame('This type is only for women.', $skirt->decoded()['error']['fields']['product_type']);
        $girls = $this->admin('POST', '/v1/admin/products', ['segment' => 'girls'] + $this->styleInput());
        self::assertArrayHasKey('segment', $girls->decoded()['error']['fields']);
    }

    public function testSpecsAndSizesFollowTheProductType(): void
    {
        $types = $this->admin('GET', '/v1/admin/product-types')->decoded()['data'];
        self::assertSame(['men', 'women'], $types['segments']);
        $tshirt = array_values(array_filter($types['types'], static fn (array $t): bool => $t['slug'] === 't-shirt'))[0];
        self::assertSame(['letter', 'other'], $tshirt['size_systems']);
        self::assertSame(['fit', 'neckline', 'sleeve', 'fabric', 'composition', 'fabric_weight', 'wash'], array_column($tshirt['specs'], 'key'));
        self::assertSame('Finish', array_column($tshirt['specs'], 'label', 'key')['wash']);
        self::assertContains('Crew neck', $tshirt['specs'][1]['suggestions']);

        // A T-shirt uses letter sizes (in size order, "2XL" written as "XXL") and keeps only its own specs.
        $style = $this->admin('POST', '/v1/admin/products', [
            'name' => 'Test style crew neck T-shirt', 'slug' => 'test-style-tee', 'segment' => 'women', 'product_type' => 't-shirt',
            'specs' => ['fit' => 'Oversized', 'neckline' => ' Crew neck ', 'fabric_weight' => '220 GSM', 'stretch' => 'stretch', 'rise' => 'High rise', 'closure' => 'Zip fly'],
            'sizes' => ['xl', 'S', 'M', '2XL'], 'price_cents' => 380,
        ]);
        self::assertSame(201, $style->status(), $style->body());
        $style = $style->decoded()['data'];
        self::assertSame('letter', $style['size_system'], 'The usual system of the type');
        self::assertSame(['S', 'M', 'XL', 'XXL'], $style['sizes']);
        self::assertSame(['fit' => 'Oversized', 'neckline' => 'Crew neck', 'sleeve' => null, 'fabric' => null, 'composition' => null, 'fabric_weight' => '220 GSM', 'wash' => null], $style['specs']);
        self::assertSame(['neckline' => 'Crew neck'], $style['attributes']);
        self::assertNull($style['stretch'], 'T-shirts have no stretch setting');
        self::assertNull($style['closure']);

        $waist = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['size_system' => 'waist', 'sizes' => ['30', '32']] + $this->tee());
        self::assertSame('T-shirts use letter sizes or other sizes.', $waist->decoded()['error']['fields']['size_system']);
        $odd = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['sizes' => ['M', 'XLL']] + $this->tee());
        self::assertSame('“XLL” is not one of the letter sizes.', $odd->decoded()['error']['fields']['sizes']);
        $other = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['size_system' => 'other', 'sizes' => ['One size']] + $this->tee());
        self::assertSame(['One size'], $other->decoded()['data']['sizes'], 'Other sizes keep their own text');

        // The same style as jeans: waist sizes (odd ones too, "W33" written as "33"), and the T-shirt details are cleared.
        $jeans = $this->admin('PUT', "/v1/admin/products/{$style['id']}", [
            'product_type' => 'jeans-pant', 'size_system' => 'waist', 'sizes' => ['34', 'W33', '30'],
            'specs' => ['fit' => 'Straight', 'rise' => 'High rise', 'length' => '32 in', 'stretch' => 'non-stretch'],
        ] + $this->tee());
        self::assertSame(200, $jeans->status(), $jeans->body());
        $jeans = $jeans->decoded()['data'];
        self::assertSame(['30', '33', '34'], $jeans['sizes']);
        self::assertSame(['rise' => 'High rise', 'length' => '32 in'], $jeans['attributes']);
        self::assertSame('non-stretch', $jeans['stretch']);
        $letters = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['product_type' => 'jeans-pant', 'size_system' => 'waist', 'sizes' => ['S']] + $this->tee());
        self::assertSame('“S” is not one of the waist sizes.', $letters->decoded()['error']['fields']['sizes']);
        $stretch = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['product_type' => 'jeans-pant', 'size_system' => 'waist', 'sizes' => ['30'], 'specs' => ['stretch' => 'very']] + $this->tee());
        self::assertArrayHasKey('specs.stretch', $stretch->decoded()['error']['fields']);

        // A style can only use a size chart of its own size system.
        $chart = $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['size_chart_id' => (int) $this->db->fetchValue("SELECT id FROM size_charts WHERE name = 'Men''s pants'")] + $this->tee());
        self::assertSame('This chart is for waist sizes. Choose a chart for letter sizes.', $chart->decoded()['error']['fields']['size_chart_id']);

        // On the product page: labeled specs of the type, and colors change only the fabric details it shows.
        self::assertSame(200, $this->admin('PUT', "/v1/admin/products/{$style['id']}", $this->tee())->status(), 'A T-shirt again');
        $photo = $this->upload();
        $color = $this->admin('POST', "/v1/admin/products/{$style['id']}/colors", [
            'code' => 'TEST-902', 'name' => 'Heather gray', 'spec_overrides' => ['composition' => '60% cotton, 40% polyester', 'stretch' => 'stretch', 'neckline' => 'V-neck'],
            'images' => [['path' => $photo, 'alt' => 'Test T-shirt, front']],
        ]);
        self::assertSame(['composition' => '60% cotton, 40% polyester'], $color->decoded()['data']['spec_overrides']);
        self::assertSame(200, $this->admin('PUT', "/v1/admin/products/{$style['id']}", ['status' => 'active'] + $this->tee())->status());
        $page = $this->send('GET', '/v1/products/test-style-tee')->decoded()['data'];
        self::assertSame(['Fit', 'Neckline', 'Sleeve', 'Fabric', 'Composition', 'Fabric weight', 'Finish'], array_column($page['spec_fields'], 'label'));
        self::assertSame('60% cotton, 40% polyester', $page['colors'][0]['specs']['composition']);
        self::assertArrayNotHasKey('stretch', $page['colors'][0]['specs']);
        self::assertSame('letter', $page['size_system']);
    }

    public function testSizeChartsAndCategoryTexts(): void
    {
        $bad = $this->admin('POST', '/v1/admin/size-charts', [
            'name' => 'TEST kids', 'size_system' => 'other', 'unit' => 'cm', 'measurements' => ['Waist', 'Length'], 'size_rows' => [['size' => '4Y', 'values' => ['52']]],
        ]);
        self::assertArrayHasKey('size_rows.0.values', $bad->decoded()['error']['fields']);

        $chart = $this->admin('POST', '/v1/admin/size-charts', [
            'name' => 'TEST kids', 'size_system' => 'other', 'unit' => 'cm', 'measurements' => ['Waist', 'Length'],
            'size_rows' => [['size' => '6Y', 'values' => ['55', '65']], ['size' => '4Y', 'values' => ['52', '58']]],
        ]);
        self::assertSame(201, $chart->status());
        self::assertSame(['size' => '4Y', 'values' => ['52', '58']], $chart->decoded()['data']['size_rows'][1], 'Other sizes keep their order');

        // Letter and waist charts only take their own sizes, and are kept in size order.
        $tops = $this->admin('POST', '/v1/admin/size-charts', [
            'name' => 'TEST tops', 'size_system' => 'letter', 'unit' => 'cm', 'measurements' => ['Chest (½)', 'Body length'],
            'size_rows' => [['size' => 'xl', 'values' => ['60', '76']], ['size' => 'S', 'values' => ['51', '70']], ['size' => '2XL', 'values' => ['63', '78']]],
        ]);
        self::assertSame(201, $tops->status(), $tops->body());
        self::assertSame(['S', 'XL', 'XXL'], array_column($tops->decoded()['data']['size_rows'], 'size'));
        self::assertSame('letter', $tops->decoded()['data']['size_system']);
        $wrong = $this->admin('POST', '/v1/admin/size-charts', [
            'name' => 'TEST waist', 'size_system' => 'waist', 'unit' => 'in', 'measurements' => ['Waist'], 'size_rows' => [['size' => 'M', 'values' => ['32']]],
        ]);
        self::assertSame('“M” is not one of the waist sizes.', $wrong->decoded()['error']['fields']['size_rows.0.size']);

        // A chart cannot switch to another size system while styles of its current system use it.
        $pants = $this->db->fetch("SELECT * FROM size_charts WHERE name = 'Men''s pants'");
        $switch = $this->admin('PUT', "/v1/admin/size-charts/{$pants['id']}", [
            'name' => "Men's pants", 'size_system' => 'letter', 'unit' => 'in', 'measurements' => ['Waist'], 'size_rows' => [['size' => 'M', 'values' => ['32']]],
        ]);
        self::assertStringContainsString('style(s) with other sizes use this chart', $switch->decoded()['error']['fields']['size_system']);

        $categories = $this->admin('GET', '/v1/admin/categories')->decoded()['data'];
        $menTees = array_values(array_filter($categories, static fn (array $c): bool => $c['segment'] === 'men' && $c['product_type'] === 't-shirt'))[0];
        $this->savedCategory = $menTees;
        $updated = $this->admin('PUT', "/v1/admin/categories/{$menTees['id']}", [
            'title' => "Men's T-shirts",
            'intro_html' => '<p onclick="x()">Made to order.</p><script>alert(1)</script>',
            'faq' => [['question' => 'Do you print T-shirts?', 'answer' => 'Yes.']],
        ])->decoded()['data'];
        self::assertSame('<p>Made to order.</p>', $updated['intro_html']);
        self::assertSame([['question' => 'Do you print T-shirts?', 'answer' => 'Yes.']], $updated['faq']);
    }

    /** @return array<string, mixed> */
    private function styleInput(): array
    {
        return [
            'name' => 'Test style denim jacket',
            'slug' => 'test-style-denim-jacket',
            'segment' => 'men',
            'product_type' => 'denim-jacket',
            'specs' => ['fit' => 'Regular'],
            'description_html' => '<p>Classic trucker jacket.</p>',
            'features' => ['Chest pockets', ''],
            'sizes' => ['S', 'M', 'L'],
            'price_cents' => 1250,
        ];
    }

    /** @return array<string, mixed> The T-shirt of testSpecsAndSizesFollowTheProductType. */
    private function tee(): array
    {
        return ['name' => 'Test style crew neck T-shirt', 'slug' => 'test-style-tee', 'segment' => 'women', 'product_type' => 't-shirt', 'sizes' => ['S', 'M'], 'price_cents' => 380];
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
