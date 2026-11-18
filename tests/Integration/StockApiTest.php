<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\StaffUser;
use App\Models\StockColor;
use App\Services\ImageStore;

/** Stock: ready clothes sold by set, by department and category; the stock pages' endpoints and the stock admin. */
final class StockApiTest extends IntegrationTestCase
{
    private const TEST_IP = '192.0.2.81';

    private const EDITOR = 'stock-editor@example.test';

    private const SALES = 'stock-sales@example.test';

    private const PASSWORD = 'correct horse battery';

    /** @var array<string, string> email => login token */
    private array $tokens = [];

    /** @var list<string> uploaded files to remove */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        $staff = $this->app->container->get(StaffUser::class);
        $staff->create('Stock Editor', self::EDITOR, self::PASSWORD, 'editor');
        $staff->create('Stock Sales', self::SALES, self::PASSWORD, 'sales');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $public = dirname($this->app->config()->string('paths.uploads'));
        foreach ($this->files as $file) {
            @unlink(str_starts_with($file, 'uploads/') ? "{$public}/{$file}" : $file);
        }
    }

    private function cleanUp(): void
    {
        $this->db->execute("DELETE FROM stock_styles WHERE slug LIKE 'test-stock%'");
        $this->db->execute("DELETE FROM stock_departments WHERE slug LIKE 'test-%'");
        $this->db->execute("DELETE FROM stock_categories WHERE slug LIKE 'test-%'");
        $this->db->execute("DELETE FROM products WHERE slug LIKE 'test-stock%'");
        $this->db->execute("DELETE FROM redirects WHERE from_path LIKE '/stock/%/test-stock%' OR from_path LIKE '/wholesale-shop/%/test-stock%'");
        foreach ([self::EDITOR, self::SALES] as $email) {
            $this->db->execute("DELETE t FROM auth_tokens t JOIN staff_users s ON s.id = t.user_id AND t.user_type = 'staff' WHERE s.email = ?", [$email]);
            $this->db->execute('DELETE FROM staff_users WHERE email = ?', [$email]);
            (new RateLimiter($this->db))->clear('staff-login:' . $email);
        }
        (new RateLimiter($this->db))->clear('staff-login-ip:' . self::TEST_IP);
    }

    public function testStyleIsShownOnceItHasAColorWithAPhoto(): void
    {
        $created = $this->admin('POST', '/v1/admin/stock/styles', $this->styleInput());
        self::assertSame(201, $created->status(), (string) $created->body());
        $style = $created->decoded()['data'];
        self::assertSame('draft', $style['status'], 'Created without a photo: a draft');
        self::assertSame([['size' => '28', 'pcs' => 1], ['size' => '30', 'pcs' => 2], ['size' => '32', 'pcs' => 2], ['size' => '34', 'pcs' => 1]], $style['set_sizes'], 'Sizes are sorted');
        self::assertSame(6, $style['set_pieces']);
        self::assertSame([200000, 1200000], [$style['piece_price_cents'], $style['set_price_cents']], 'A set costs its pieces times the price per piece');
        self::assertSame('stretch', $style['specs']['stretch']);
        self::assertNull($style['specs']['rise']);
        self::assertSame('Test label', $style['specs']['label'], 'The label is a stock-only detail, kept trimmed');

        $activate = $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", ['status' => 'active'] + $this->styleInput());
        self::assertSame(422, $activate->status(), 'A shown style needs a color with a photo');

        $color = $this->admin('POST', "/v1/admin/stock/styles/{$style['id']}/colors", [
            'code' => 'TEST-ST-1', 'name' => 'Mid blue', 'hex' => '#4A6FA5', 'sets_in_stock' => 12,
            'images' => [['path' => $this->upload(), 'alt' => 'Mid blue jeans, front']],
        ]);
        self::assertSame(201, $color->status(), (string) $color->body());
        self::assertSame(12, $color->decoded()['data']['sets_in_stock'], 'Opening stock is recorded');
        self::assertSame('#4a6fa5', $color->decoded()['data']['hex']);
        $second = $this->admin('POST', "/v1/admin/stock/styles/{$style['id']}/colors", ['code' => 'TEST-ST-1B', 'name' => 'Black']);
        self::assertSame(422, $second->status(), 'Each listing is one color');
        self::assertStringStartsWith('This listing has its color already.', $second->decoded()['error']['fields']['code']);

        self::assertSame(200, $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", ['status' => 'active'] + $this->styleInput())->status());

        $cards = $this->send('GET', '/v1/stock/styles', ['segment' => 'men'])->decoded()['data'];
        $card = $this->card($cards, 'test-stock-slim-jeans');
        self::assertSame(['men', 'jeans', 'Men', 'Jeans'], [$card['segment'], $card['category'], $card['department_name'], $card['category_name']]);
        self::assertSame(['28', '30', '32', '34'], $card['sizes']);
        self::assertSame([200000, 1200000], [$card['piece_price_cents'], $card['set_price_cents']]);
        self::assertSame(12, $card['sets_in_stock']);
        self::assertStringStartsWith('uploads/stock/', $card['colors'][0]['image']['path']);
        self::assertSame([], array_filter(
            $this->send('GET', '/v1/stock/styles', ['segment' => 'boys'])->decoded()['data'],
            static fn (array $c): bool => $c['slug'] === 'test-stock-slim-jeans',
        ), 'Men\'s styles are not on the boys\' page');
        self::assertSame(422, $this->send('GET', '/v1/stock/styles', ['segment' => 'girls'])->status(), 'Only the stock departments');
        self::assertSame(200, $this->send('GET', '/v1/stock/styles', ['segment' => 'women', 'category' => 'cotton-pants'])->status());

        $page = $this->send('GET', '/v1/stock/styles/test-stock-slim-jeans')->decoded()['data'];
        self::assertSame(6, $page['set']['pieces']);
        self::assertSame('Inseam', array_column($page['spec_fields'], 'label', 'key')['length']);
        self::assertSame(['key' => 'label', 'label' => 'Label'], $page['spec_fields'][array_key_last($page['spec_fields'])], 'The label comes after the jeans specs');
        self::assertSame('Test label', $page['specs']['label']);
        self::assertSame(12, $page['colors'][0]['sets_in_stock']);
        self::assertSame(404, $this->send('GET', '/v1/stock/styles/test-stock-does-not-exist')->status());
    }

    public function testStockChangesAreLoggedAndNeverGoBelowZero(): void
    {
        $style = $this->admin('POST', '/v1/admin/stock/styles', $this->styleInput())->decoded()['data'];
        $color = $this->admin('POST', "/v1/admin/stock/styles/{$style['id']}/colors", ['code' => 'TEST-ST-2', 'name' => 'Black', 'sets_in_stock' => 10])->decoded()['data'];
        $change = fn (string $reason, int $sets): Response => $this->admin('POST', "/v1/admin/stock/colors/{$color['id']}/stock", ['reason' => $reason, 'sets' => $sets, 'note' => "test {$reason}"]);

        self::assertSame(15, $change('received', 5)->decoded()['data']['sets_in_stock']);
        self::assertSame(11, $change('sold', 4)->decoded()['data']['sets_in_stock']);
        self::assertSame(10, $change('damaged', 1)->decoded()['data']['sets_in_stock']);
        self::assertSame(12, $change('returned', 2)->decoded()['data']['sets_in_stock']);
        self::assertSame(9, $change('correction', 9)->decoded()['data']['sets_in_stock'], 'A correction sets the counted total');

        $tooMany = $change('sold', 10);
        self::assertSame(422, $tooMany->status());
        self::assertSame('Only 9 sets are in stock.', $tooMany->decoded()['error']['fields']['sets']);
        self::assertSame(422, $change('correction', 9)->status(), 'No change, no movement');
        self::assertSame(422, $change('received', 0)->status());
        self::assertSame(422, $change('stolen', 1)->status());

        $history = $this->admin('GET', '/v1/admin/stock/movements', ['style_id' => $style['id']])->decoded();
        self::assertSame(6, $history['meta']['pagination']['total']);
        self::assertSame(['correction', -3, 9], [$history['data'][0]['reason'], $history['data'][0]['change_sets'], $history['data'][0]['sets_after']]);
        self::assertSame(['received', 10, 10], [$history['data'][5]['reason'], $history['data'][5]['change_sets'], $history['data'][5]['sets_after']], 'The opening stock comes first');
        self::assertSame('Stock Editor', $history['data'][0]['staff_name']);
    }

    public function testValidationAndRoles(): void
    {
        $sizes = fn (array $setSizes): int => $this->admin('POST', '/v1/admin/stock/styles', ['set_sizes' => $setSizes] + $this->styleInput())->status();
        self::assertSame(422, $sizes([]), 'A set needs sizes');
        self::assertSame(422, $sizes([['size' => '14', 'pcs' => 1]]), 'Waist sizes start at 16');
        self::assertSame(422, $sizes([['size' => 'XL', 'pcs' => 1]]));
        self::assertSame(422, $sizes([['size' => '30', 'pcs' => 0]]));
        self::assertSame(422, $sizes([['size' => '30', 'pcs' => 1], ['size' => '30', 'pcs' => 1]]), 'Each size once');
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['segment' => 'girls'] + $this->styleInput())->status(), 'Only the stock departments');
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['category' => 'shirts'] + $this->styleInput())->status(), 'Only the stock categories');

        $boys = $this->admin('POST', '/v1/admin/stock/styles', [
            'name' => 'Test stock boys jeans', 'slug' => 'test-stock-boys-jeans', 'segment' => 'boys', 'piece_price_cents' => 150000,
            'set_sizes' => [['size' => '26', 'pcs' => 1], ['size' => '16', 'pcs' => 1], ['size' => '18', 'pcs' => 1]],
        ]);
        self::assertSame(201, $boys->status(), (string) $boys->body());
        self::assertSame(['16', '18', '26'], array_column($boys->decoded()['data']['set_sizes'], 'size'));
        self::assertSame(450000, $boys->decoded()['data']['set_price_cents'], '3 pieces × Rs 1,500');
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['piece_price_cents' => 50] + $this->styleInput())->status(), 'A price per piece of at least Rs 1');
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-boys-jeans'] + $this->styleInput())->status(), 'Web addresses are unique');
        // Without a web address, it is made from the name, numbered when another listing (another color) has it.
        $sameName = fn (): string => $this->admin('POST', '/v1/admin/stock/styles', ['name' => 'Test stock boys jeans', 'slug' => null] + $this->styleInput())->decoded()['data']['slug'];
        self::assertSame(['test-stock-boys-jeans-2', 'test-stock-boys-jeans-3'], [$sameName(), $sameName()]);
        // The style no. ends it (a duplicate has the same, so the number comes after it).
        $withNumber = fn (): string => $this->admin('POST', '/v1/admin/stock/styles', ['name' => 'Test stock wide jeans', 'style_no' => 'PK-1051', 'slug' => null] + $this->styleInput())->decoded()['data']['slug'];
        self::assertSame(['test-stock-wide-jeans-pk-1051', 'test-stock-wide-jeans-pk-1051-2'], [$withNumber(), $withNumber()]);

        self::assertSame(403, $this->admin('GET', '/v1/admin/stock/styles', null, self::SALES)->status(), 'Editors and admins manage stock');
    }

    public function testNewStylesAndListingsAreSavedWithTheirFirstColor(): void
    {
        // A stock listing with its color, photo and the sets received so far.
        $photo = $this->upload();
        $listing = $this->admin('POST', '/v1/admin/stock/styles', $this->styleInput() + [
            'color' => ['code' => 'TEST-ST-F1', 'name' => 'Test sky blue', 'hex' => '#8FB3D9', 'sets_in_stock' => 7, 'images' => [['path' => $photo, 'alt' => 'Test slim jeans, front']]],
        ]);
        self::assertSame(201, $listing->status(), (string) $listing->body());
        $color = $listing->decoded()['data']['colors'][0];
        self::assertSame(['TEST-ST-F1', '#8fb3d9', [$photo], 7], [$color['code'], $color['hex'], array_column($color['images'], 'path'), $this->sets('TEST-ST-F1')]);
        self::assertSame('active', $listing->decoded()['data']['status'], 'Created with a photo: shown at once');

        // A taken code or a missing photo: nothing is saved.
        $taken = $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-taken-code'] + $this->styleInput() + ['color' => ['code' => 'TEST-ST-F1', 'name' => 'Black']]);
        self::assertSame(422, $taken->status());
        self::assertArrayHasKey('color.code', $taken->decoded()['error']['fields']);
        $gone = $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-taken-code'] + $this->styleInput() + [
            'color' => ['code' => 'TEST-ST-F2', 'name' => 'Black', 'images' => [['path' => 'uploads/stock/gone.jpg', 'alt' => 'Gone']]],
        ]);
        self::assertArrayHasKey('color.images.0.path', $gone->decoded()['error']['fields']);
        self::assertNull($this->db->fetchValue("SELECT id FROM stock_styles WHERE slug = 'test-stock-taken-code'"));

        // A wholesale style with its first color and photo.
        $wholesalePhoto = $this->upload('products');
        $style = $this->admin('POST', '/v1/admin/products', [
            'name' => 'Test stock first color jeans', 'slug' => 'test-stock-first-color', 'segment' => 'men', 'product_type' => 'jeans-pant',
            'sizes' => ['30', '32'], 'price_cents' => 650,
            'color' => ['code' => 'TEST-SW-F1', 'name' => 'Rinse', 'images' => [['path' => $wholesalePhoto, 'alt' => 'Test jeans in rinse, front']]],
        ]);
        self::assertSame(201, $style->status(), (string) $style->body());
        $colors = $style->decoded()['data']['colors'];
        self::assertSame([['TEST-SW-F1', 'rinse', [$wholesalePhoto]]], array_map(static fn (array $c): array => [$c['code'], $c['slug'], array_column($c['images'], 'path')], $colors));
        self::assertSame('active', $style->decoded()['data']['status'], 'Created with a photo: shown in the shop at once');
        $again = $this->admin('POST', '/v1/admin/products', ['slug' => 'test-stock-first-color-2', 'name' => 'Test stock first color jeans 2', 'segment' => 'men', 'product_type' => 'jeans-pant',
            'sizes' => ['30'], 'price_cents' => 650, 'color' => ['code' => 'TEST-SW-F1', 'name' => 'Rinse']]);
        self::assertArrayHasKey('color.code', $again->decoded()['error']['fields']);
        self::assertNull($this->db->fetchValue("SELECT id FROM products WHERE slug = 'test-stock-first-color-2'"));

        // The add forms send no code: the next free one is used.
        $autoListing = $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-auto-code'] + $this->styleInput() + ['color' => ['name' => 'Test black']]);
        self::assertSame(201, $autoListing->status(), (string) $autoListing->body());
        self::assertMatchesRegularExpression('/^ST-\d{4,}$/', $autoListing->decoded()['data']['colors'][0]['code']);
        self::assertSame('draft', $autoListing->decoded()['data']['status'], 'Without a photo it cannot be shown, so it is a draft');
        $autoStyle = $this->admin('POST', '/v1/admin/products', ['slug' => 'test-stock-auto-code', 'name' => 'Test stock auto code jeans', 'segment' => 'men', 'product_type' => 'jeans-pant',
            'sizes' => ['30'], 'price_cents' => 650, 'color' => ['name' => 'Black']]);
        self::assertSame(201, $autoStyle->status(), (string) $autoStyle->body());
        self::assertMatchesRegularExpression('/^\d{4,}$/', $autoStyle->decoded()['data']['colors'][0]['code']);
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-no-name'] + $this->styleInput() + ['color' => ['images' => []]])->status(), 'A color needs its name');
    }

    public function testCsvExportImportAndReport(): void
    {
        // Two listings, one color each.
        $style = $this->admin('POST', '/v1/admin/stock/styles', $this->styleInput())->decoded()['data'];
        $this->admin('POST', "/v1/admin/stock/styles/{$style['id']}/colors", ['code' => 'TEST-ST-A', 'name' => 'Test indigo', 'sets_in_stock' => 10]);
        $blackStyle = $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-slim-jeans-black'] + $this->styleInput())->decoded()['data'];
        $black = $this->admin('POST', "/v1/admin/stock/styles/{$blackStyle['id']}/colors", ['code' => 'TEST-ST-B', 'name' => 'Test black', 'sets_in_stock' => 4])->decoded()['data'];

        $export = $this->admin('GET', '/v1/admin/stock/export');
        self::assertSame(200, $export->status());
        self::assertStringStartsWith("\xEF\xBB\xBF\"Style no.\",Style,For,Category,\"Color code\",Color,\"Sets in stock\"", $export->body());
        self::assertStringContainsString('Men,Jeans,TEST-ST-A,"Test indigo",10,6,2000,12000,No', $export->body(), 'A draft style is not shown');

        // Excel in some languages saves with semicolons; problems are listed and nothing changes.
        $bad = $this->admin('POST', '/v1/admin/stock/import', ['csv' => "Color code;Sets in stock\nTEST-ST-A;7\nTEST-NOPE;1\nTEST-ST-B;lots\n", 'apply' => true])->decoded()['data'];
        self::assertFalse($bad['applied']);
        self::assertSame([3, 4], array_column($bad['errors'], 'line'));
        self::assertSame(10, $this->sets('TEST-ST-A'));

        $preview = $this->admin('POST', '/v1/admin/stock/import', ['csv' => "Color code;Sets in stock\nTEST-ST-A;7\nTEST-ST-B;4\n"])->decoded()['data'];
        self::assertSame([[2, 'TEST-ST-A', 10, 7], 1, false], [[$preview['changes'][0]['line'], $preview['changes'][0]['code'], $preview['changes'][0]['from'], $preview['changes'][0]['to']], $preview['unchanged'], $preview['applied']]);
        self::assertSame(10, $this->sets('TEST-ST-A'), 'A preview changes nothing');
        $applied = $this->admin('POST', '/v1/admin/stock/import', ['csv' => "Color code;Sets in stock\nTEST-ST-A;7\nTEST-ST-B;4\n", 'apply' => true])->decoded()['data'];
        self::assertTrue($applied['applied']);
        self::assertSame(7, $this->sets('TEST-ST-A'));
        self::assertSame('Stock count imported', $this->admin('GET', '/v1/admin/stock/movements', ['style_id' => $style['id']])->decoded()['data'][0]['note']);

        // Sold sets count in the report (less a cancelled order's), at today's set price.
        $this->admin('POST', "/v1/admin/stock/colors/{$black['id']}/stock", ['reason' => 'sold', 'sets' => 2]);
        $report = $this->admin('GET', '/v1/admin/stock/report', ['days' => 7], self::SALES)->decoded()['data'];
        $row = array_values(array_filter($report['rows'], static fn (array $r): bool => $r['code'] === 'TEST-ST-B'))[0];
        self::assertSame([2, 2, 2 * 1200000, 4], [$row['sets_in_stock'], $row['sold_sets'], $row['value_cents'], $row['received_sets']]);
        self::assertSame(['Men', 'Jeans'], [$row['department_name'], $row['category_name']]);
        self::assertSame(403, $this->admin('GET', '/v1/admin/stock/export', null, self::SALES)->status(), 'Sales staff read the report only');
    }

    public function testNewListingsAreImportedFromACsvFile(): void
    {
        $template = $this->admin('GET', '/v1/admin/stock/import-listings/template');
        self::assertSame(200, $template->status());
        self::assertStringStartsWith("\xEF\xBB\xBFName,\"Style no.\",For,Category,Color,\"Color code\"", $template->body());

        $csv = "Name;For;Category;Color;Color code;Set;Price per piece (Rs);Sets in stock;Fit;Finish;Label\n"
            . "Test stock import slim;Men;Jeans;Black;TEST-IMP-1;28x1, 30x2;980;5;Slim;;Own label\n"
            . "Test stock import straight;Boys;Cotton pant;Mid blue;TEST-IMP-2;16x2 18x2;650;;Straight;Peach finish;\n";

        // A file with a problem creates nothing, and names the line.
        $bad = $this->admin('POST', '/v1/admin/stock/import-listings', ['csv' => $csv . "Test stock import wide;Men;Jeans;Grey;TEST-IMP-1;30x1;700;;;;\n", 'apply' => true])->decoded()['data'];
        self::assertFalse($bad['applied']);
        self::assertSame([['line' => 4, 'message' => 'The code “TEST-IMP-1” is also on line 2.']], $bad['errors']);
        self::assertNull($this->db->fetchValue("SELECT id FROM stock_styles WHERE slug = 'test-stock-import-slim-black'"));

        $preview = $this->admin('POST', '/v1/admin/stock/import-listings', ['csv' => $csv])->decoded()['data'];
        self::assertSame([[], false, 2], [$preview['errors'], $preview['applied'], count($preview['listings'])]);
        $first = $preview['listings'][0];
        self::assertSame([3, 98000, 294000, 5, null], [$first['set_pieces'], $first['piece_price_cents'], $first['set_price_cents'], $first['sets_in_stock'], $first['id']]);
        self::assertNull($this->db->fetchValue("SELECT id FROM stock_styles WHERE slug = 'test-stock-import-slim-black'"), 'A preview creates nothing');

        $applied = $this->admin('POST', '/v1/admin/stock/import-listings', ['csv' => $csv, 'apply' => true])->decoded()['data'];
        self::assertTrue($applied['applied']);
        $style = $this->admin('GET', "/v1/admin/stock/styles/{$applied['listings'][0]['id']}")->decoded()['data'];
        self::assertSame(['test-stock-import-slim-black', 'draft', 'Slim', 'Own label', 5], [$style['slug'], $style['status'], $style['specs']['fit'], $style['specs']['label'], $style['sets_in_stock']]);
        self::assertSame('Stock when the listing was imported', $this->admin('GET', '/v1/admin/stock/movements', ['style_id' => $style['id']])->decoded()['data'][0]['note']);
        self::assertSame(
            ['test-stock-import-slim-black', 'test-stock-import-straight-mid-blue'],
            array_column($this->admin('GET', '/v1/admin/stock/styles', ['q' => 'Test stock import'])->decoded()['data'], 'slug'),
            'Listed newest first, in the order of the file',
        );
        $boys = $this->admin('GET', "/v1/admin/stock/styles/{$applied['listings'][1]['id']}")->decoded()['data'];
        self::assertSame(['boys', 'cotton-pants', 'Peach finish'], [$boys['segment'], $boys['category'], $boys['specs']['wash']], 'The Finish of cotton pants');
        self::assertSame(['Boys', 'Cotton pants'], [$applied['listings'][1]['department_name'], $applied['listings'][1]['category_name']]);

        // The same file again creates nothing twice: each line's code, and its name and color, are taken.
        $again = $this->admin('POST', '/v1/admin/stock/import-listings', ['csv' => $csv, 'apply' => true])->decoded()['data'];
        self::assertFalse($again['applied']);
        self::assertSame([2, 2, 3, 3], array_column($again['errors'], 'line'));
        self::assertSame(403, $this->admin('POST', '/v1/admin/stock/import-listings', ['csv' => $csv], self::SALES)->status(), 'Editors and admins only');
    }

    public function testWholesaleColorsAreCopiedAsListingsOfTheirOwn(): void
    {
        $input = [
            'name' => "Test stock men's straight jeans",
            'slug' => 'test-stock-straight-jeans',
            'segment' => 'men',
            'product_type' => 'jeans-pant',
            'specs' => ['fit' => 'Straight', 'rise' => 'Mid rise', 'stretch' => 'non-stretch'],
            'description_html' => '<p>Five-pocket straight jeans in rigid denim.</p><p>Made to order in our factory, from 100 pieces per color.</p>',
            'features' => ['Five-pocket styling'],
            'sizes' => ['30', '32', '34', '36'],
            'set_pcs_per_size' => 2,
            'price_cents' => 650,
        ];
        $product = $this->admin('POST', '/v1/admin/products', $input);
        self::assertSame(201, $product->status(), (string) $product->body());
        $productId = $product->decoded()['data']['id'];
        $rinse = $this->admin('POST', "/v1/admin/products/{$productId}/colors", [
            'code' => 'TEST-SW-1', 'name' => 'Rinse', 'images' => [['path' => $this->upload('products'), 'alt' => 'Test straight jeans in rinse, front']],
        ]);
        self::assertSame(201, $rinse->status(), (string) $rinse->body());
        $black = $this->admin('POST', "/v1/admin/products/{$productId}/colors", [
            'code' => 'TEST-SW-2', 'name' => 'Black', 'spec_overrides' => ['wash' => 'Black wash'],
            'images' => [['path' => $this->upload('products'), 'alt' => 'Test straight jeans in black, front']],
        ]);
        self::assertSame(201, $black->status(), (string) $black->body());
        [$rinseId, $blackId] = [$rinse->decoded()['data']['id'], $black->decoded()['data']['id']];

        // A wholesale draft would be copied as hidden drafts; once shown in the wholesale shop, its colors are shown too.
        $draft = $this->wholesaleRow($rinseId);
        self::assertSame([false, 'It is a draft in the wholesale shop.'], [$draft['shown'], $draft['draft_reason']]);
        self::assertSame(200, $this->admin('PUT', "/v1/admin/products/{$productId}", ['status' => 'active'] + $input)->status());

        // Each color is a listing of its own.
        $row = $this->wholesaleRow($rinseId);
        self::assertSame(
            [null, null, true, 'men', 'Rinse', 'TEST-SW-1', 'test-stock-straight-jeans-rinse', 1],
            [$row['stock_style_id'], $row['problem'], $row['shown'], $row['segment'], $row['color'], $row['code'], $row['slug'], $row['photos']],
        );
        self::assertSame(['jeans', 'Men', 'Jeans'], [$row['category'], $row['department_name'], $row['category_name']], 'Copied into the stock jeans');
        self::assertSame('test-stock-straight-jeans-black', $this->wholesaleRow($blackId)['slug']);
        self::assertSame(['30', '32', '34', '36'], array_column($row['set_sizes'], 'size'));
        self::assertSame([2, 2, 2, 2], array_column($row['set_sizes'], 'pcs'), 'The wholesale set: 2 pieces per size');
        self::assertSame(['Made to order in our factory, from 100 pieces per color.'], $row['left_out']);

        $copied = $this->admin('POST', '/v1/admin/stock/from-wholesale', ['color_ids' => [$rinseId, $blackId]])->decoded()['data']['copied'];
        self::assertSame(
            [[$rinseId, 'Rinse', 1, true, true], [$blackId, 'Black', 1, true, true]],
            array_map(static fn (array $c): array => [$c['color_id'], $c['color'], $c['photos'], $c['new'], $c['shown']], $copied),
        );
        self::assertSame(
            ['test-stock-straight-jeans-rinse', 'test-stock-straight-jeans-black'],
            array_column($this->admin('GET', '/v1/admin/stock/styles', ['q' => 'Test stock men'])->decoded()['data'], 'slug'),
            'Listed newest first, in the wholesale order of the colors',
        );
        $listing = $this->admin('GET', "/v1/admin/stock/styles/{$copied[0]['stock_style_id']}")->decoded()['data'];
        $blackListing = $this->admin('GET', "/v1/admin/stock/styles/{$copied[1]['stock_style_id']}")->decoded()['data'];
        $photo = $listing['colors'][0]['images'][0]['path'];
        array_push($this->files, $photo, $blackListing['colors'][0]['images'][0]['path']);

        self::assertSame(
            ['active', 0, 'test-stock-straight-jeans-rinse', "Test stock men's straight jeans"],
            [$listing['status'], $listing['set_price_cents'], $listing['slug'], $listing['name']],
            'Shown, without a price',
        );
        self::assertSame([['TEST-SW-1', 'Rinse', 0]], array_map(static fn (array $c): array => [$c['code'], $c['name'], $c['sets_in_stock']], $listing['colors']), 'One color');
        self::assertSame('jeans', $listing['category']);
        self::assertSame(['fit' => 'Straight', 'rise' => 'Mid rise', 'stretch' => 'non-stretch'], array_filter($listing['specs']));
        self::assertSame('Black wash', $blackListing['specs']['wash'], 'A color keeps its own fabric details');
        self::assertSame('<p>Five-pocket straight jeans in rigid denim.</p><ul><li>Five-pocket styling</li></ul>', $listing['description_html']);
        self::assertStringStartsWith('uploads/stock/', $photo, 'The stock has its own copy of the photo');
        self::assertTrue($this->app->container->get(ImageStore::class)->exists($photo));

        // On the stock pages at once, but it cannot be ordered online until it has a price.
        self::assertSame(0, $this->card($this->send('GET', '/v1/stock/styles')->decoded()['data'], 'test-stock-straight-jeans-rinse')['set_price_cents']);
        $this->admin('POST', "/v1/admin/stock/colors/{$listing['colors'][0]['id']}/stock", ['reason' => 'received', 'sets' => 3]);
        $add = $this->send('POST', '/v1/stock/cart/lines', ['color_id' => $listing['colors'][0]['id'], 'sets' => 1]);
        self::assertSame(422, $add->status());
        self::assertSame('This style has no price yet. Ask us for it on WhatsApp.', $add->decoded()['error']['fields']['sets']);

        // Copied once: copying again changes nothing, and the list links to the listing.
        self::assertSame([], $this->admin('POST', '/v1/admin/stock/from-wholesale', ['color_ids' => [$rinseId, $blackId]])->decoded()['data']['copied']);
        self::assertSame($listing['id'], $this->wholesaleRow($rinseId)['stock_style_id']);

        // A copy made earlier as a hidden draft (still without a price) is shown by copying again.
        $this->db->execute("UPDATE stock_styles SET status = 'draft' WHERE id = ?", [$listing['id']]);
        self::assertTrue($this->wholesaleRow($rinseId)['hidden_copy']);
        $shown = $this->admin('POST', '/v1/admin/stock/from-wholesale', ['color_ids' => [$rinseId]])->decoded()['data']['copied'];
        self::assertSame([[$listing['id'], false, true]], array_map(static fn (array $c): array => [$c['stock_style_id'], $c['new'], $c['shown']], $shown));
        self::assertSame('active', $this->admin('GET', "/v1/admin/stock/styles/{$listing['id']}")->decoded()['data']['status']);
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/from-wholesale', ['color_ids' => ['all']])->status());
        self::assertSame(403, $this->admin('GET', '/v1/admin/stock/from-wholesale', null, self::SALES)->status());
    }

    public function testWholesaleChinosAreCopiedIntoTheCottonPants(): void
    {
        // Owner request 2026-11-18: the men's cotton pants (wholesale chino pants) are copied into the stock's Cotton
        // pants, as the jeans go into its Jeans. A type no stock category has the specs of is not offered.
        $style = function (string $key, string $type, string $code, array $specs): int {
            $input = [
                'name' => "Test stock men's {$key}", 'slug' => "test-stock-{$key}", 'segment' => 'men', 'product_type' => $type,
                'specs' => $specs, 'sizes' => ['30', '32', '34', '36'], 'set_pcs_per_size' => 2, 'price_cents' => 550,
            ];
            $product = $this->admin('POST', '/v1/admin/products', $input);
            self::assertSame(201, $product->status(), (string) $product->body());
            $productId = $product->decoded()['data']['id'];
            $color = $this->admin('POST', "/v1/admin/products/{$productId}/colors", [
                'code' => $code, 'name' => 'Khaki', 'images' => [['path' => $this->upload('products'), 'alt' => "Test {$key} in khaki, front"]],
            ]);
            self::assertSame(201, $color->status(), (string) $color->body());
            self::assertSame(200, $this->admin('PUT', "/v1/admin/products/{$productId}", ['status' => 'active'] + $input)->status());

            return $color->decoded()['data']['id'];
        };
        $jeansId = $style('straight-jeans', 'jeans-pant', 'TEST-SC-1', ['fit' => 'Straight']);
        $chinosId = $style('slim-chinos', 'chino-pant', 'TEST-SC-2', ['fit' => 'Slim', 'fabric' => 'Stretch cotton twill', 'stretch' => 'stretch', 'wash' => 'Silicone wash']);
        $cargoId = $style('cargo-trousers', 'cargo-trouser', 'TEST-SC-3', ['fit' => 'Relaxed']);

        $rows = array_values(array_filter(
            $this->admin('GET', '/v1/admin/stock/from-wholesale')->decoded()['data'],
            static fn (array $row): bool => str_starts_with($row['slug'], 'test-stock-'),
        ));
        self::assertSame([$jeansId, $chinosId], array_column($rows, 'color_id'), 'By the category they go in, in staff order (the chinos are newer); no cargo trousers');
        self::assertSame(
            ['chino-pant', 'men', 'cotton-pants', 'Men', 'Cotton pants', null, null, true],
            [$rows[1]['product_type'], $rows[1]['segment'], $rows[1]['category'], $rows[1]['department_name'], $rows[1]['category_name'], $rows[1]['stock_style_id'], $rows[1]['problem'], $rows[1]['shown']],
        );
        self::assertSame(['jeans-pant', 'jeans', 'Jeans'], [$rows[0]['product_type'], $rows[0]['category'], $rows[0]['category_name']]);

        $copied = $this->admin('POST', '/v1/admin/stock/from-wholesale', ['color_ids' => [$chinosId]])->decoded()['data']['copied'];
        self::assertSame([[$chinosId, true, true]], array_map(static fn (array $c): array => [$c['color_id'], $c['new'], $c['shown']], $copied));
        $listing = $this->admin('GET', "/v1/admin/stock/styles/{$copied[0]['stock_style_id']}")->decoded()['data'];
        $this->files[] = $listing['colors'][0]['images'][0]['path'];
        self::assertSame(
            ['test-stock-slim-chinos-khaki', 'cotton-pants', 'Cotton pants', 'chino-pant', 'men', [['30', 2], ['32', 2], ['34', 2], ['36', 2]]],
            [$listing['slug'], $listing['category'], $listing['category_name'], $listing['product_type'], $listing['segment'],
                array_map(static fn (array $size): array => [$size['size'], $size['pcs']], $listing['set_sizes'])],
        );
        self::assertSame(['fit' => 'Slim', 'fabric' => 'Stretch cotton twill', 'stretch' => 'stretch', 'wash' => 'Silicone wash'], array_filter($listing['specs']));
        $page = $this->send('GET', '/v1/stock/styles/test-stock-slim-chinos-khaki')->decoded()['data'];
        self::assertSame('Finish', array_column($page['spec_fields'], 'label', 'key')['wash'], 'Shown as cotton pants: a finish, not a wash');
        self::assertContains('test-stock-slim-chinos-khaki', array_column($this->send('GET', '/v1/stock/styles', ['segment' => 'men', 'category' => 'cotton-pants'])->decoded()['data'], 'slug'));
        self::assertNull($this->wholesaleRow($jeansId)['stock_style_id'], 'Only the chosen colors are copied');

        // Once a stock category has the specs of cargo trousers, they are offered too, into it.
        $cargo = $this->admin('POST', '/v1/admin/stock/categories', ['name' => 'Test cargo', 'product_type' => 'cargo-trouser']);
        self::assertSame(201, $cargo->status(), (string) $cargo->body());
        self::assertSame(['test-cargo', 'Test cargo', null], [$this->wholesaleRow($cargoId)['category'], $this->wholesaleRow($cargoId)['category_name'], $this->wholesaleRow($cargoId)['problem']]);
    }

    public function testStylesWithSeveralColorsAreSplitIntoListings(): void
    {
        // Made before every listing was one color: a shown style with two colors (the second put in without the check).
        $style = $this->admin('POST', '/v1/admin/stock/styles', $this->styleInput())->decoded()['data'];
        $this->admin('POST', "/v1/admin/stock/styles/{$style['id']}/colors", [
            'code' => 'TEST-ST-S1', 'name' => 'Mid blue', 'images' => [['path' => $this->upload(), 'alt' => 'Mid blue jeans, front']],
        ]);
        self::assertSame(200, $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", ['status' => 'active'] + $this->styleInput())->status());
        $second = $this->app->container->get(StockColor::class)->create(
            $style['id'],
            ['code' => 'TEST-ST-S2', 'name' => 'Black', 'slug' => 'black', 'is_active' => true, 'sort_order' => 20],
            [],
        );

        // Imported twice: the second time there is nothing left to split.
        $migration = Migrator::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0028_single_color_stock.sql'));
        foreach ([...$migration, ...$migration] as $statement) {
            $this->db->exec($statement);
        }

        self::assertSame(['TEST-ST-S1'], array_column($this->admin('GET', "/v1/admin/stock/styles/{$style['id']}")->decoded()['data']['colors'], 'code'), 'The first color stays');
        $splitId = (int) $this->db->fetchValue('SELECT style_id FROM stock_colors WHERE id = ?', [$second]);
        $split = $this->admin('GET', "/v1/admin/stock/styles/{$splitId}")->decoded()['data'];
        self::assertSame(
            ['test-stock-slim-jeans-black', 'Test stock slim jeans', 'draft', 1200000, ['TEST-ST-S2']],
            [$split['slug'], $split['name'], $split['status'], $split['set_price_cents'], array_column($split['colors'], 'code')],
            'A listing of its own, hidden until its color has a photo',
        );
        self::assertSame(0, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles' AND COLUMN_NAME = 'split_color_id'",
        ), 'The temporary column is gone');
    }

    private function sets(string $code): int
    {
        return (int) $this->db->fetchValue('SELECT sets_in_stock FROM stock_colors WHERE code = ?', [$code]);
    }

    /** @return array<string, mixed> A wholesale color's row in the "copy from wholesale" list. */
    private function wholesaleRow(int $colorId): array
    {
        foreach ($this->admin('GET', '/v1/admin/stock/from-wholesale')->decoded()['data'] as $row) {
            if ($row['color_id'] === $colorId) {
                return $row;
            }
        }
        self::fail("Wholesale color {$colorId} is not listed");
    }

    public function testStaffKeepTheDepartmentsAndCategories(): void
    {
        $departments = $this->admin('GET', '/v1/admin/stock/departments')->decoded()['data'];
        self::assertSame(['men', 'women', 'boys'], array_slice(array_column($departments, 'slug'), 0, 3), 'Men first: it is listed at /stock itself');
        self::assertIsInt($departments[0]['styles']);
        self::assertSame(['Jeans', 'Cotton pants'], array_slice(array_column($this->admin('GET', '/v1/admin/stock/categories')->decoded()['data'], 'name'), 0, 2));

        // A new department: its web address is made from the name, and never changes.
        $added = $this->admin('POST', '/v1/admin/stock/departments', ['name' => 'Test girls']);
        self::assertSame(201, $added->status(), (string) $added->body());
        $girls = $added->decoded()['data'];
        self::assertSame(['test-girls', 0], [$girls['slug'], $girls['styles']]);
        self::assertGreaterThan(30, $girls['sort_order'], 'Added at the end');
        $renamed = $this->admin('PUT', "/v1/admin/stock/departments/{$girls['id']}", ['name' => 'Test girls and kids', 'slug' => 'test-kids', 'sort_order' => 25]);
        self::assertSame(['Test girls and kids', 'test-girls', 25], [$renamed->decoded()['data']['name'], $renamed->decoded()['data']['slug'], $renamed->decoded()['data']['sort_order']]);

        $refused = fn (array $input): array => $this->admin('POST', '/v1/admin/stock/departments', $input)->decoded()['error']['fields'] ?? [];
        self::assertSame(['name' => 'Another department is called test GIRLS and kids.'], $refused(['name' => 'test GIRLS and kids']));
        self::assertSame(['slug' => '/stock/cart is one of the stock pages: choose another web address.'], $refused(['name' => 'Test cart', 'slug' => 'cart']));
        self::assertSame(['name' => '/stock/login is one of the stock pages: choose another web address.'], $refused(['name' => 'Login']));
        self::assertSame(['slug' => 'Another department has the web address “test-girls”.'], $refused(['name' => 'Test girls 2', 'slug' => 'test-girls']));

        // A category has the specs of a wholesale product type sold in waist sizes.
        $shorts = $this->admin('POST', '/v1/admin/stock/categories', ['name' => 'Test shorts', 'product_type' => 'jeans-short']);
        self::assertSame(201, $shorts->status(), (string) $shorts->body());
        $shorts = $shorts->decoded()['data'];
        self::assertSame(['test-shorts', 'jeans-short'], [$shorts['slug'], $shorts['product_type']]);
        self::assertArrayHasKey('product_type', $this->admin('POST', '/v1/admin/stock/categories', ['name' => 'Test tees', 'product_type' => 't-shirt'])->decoded()['error']['fields'], 'T-shirts have letter sizes');

        // On the stock pages' menus, in staff order.
        $sections = $this->send('GET', '/v1/stock/sections')->decoded()['data'];
        self::assertSame(['slug' => 'test-girls', 'name' => 'Test girls and kids'], $sections['departments'][array_search('test-girls', array_column($sections['departments'], 'slug'), true)]);
        self::assertSame(['slug' => 'men', 'name' => 'Men'], $sections['departments'][0]);
        self::assertContains(['slug' => 'test-shorts', 'name' => 'Test shorts'], $sections['categories']);

        // One with listings cannot be deleted.
        $listing = $this->admin('POST', '/v1/admin/stock/styles', ['segment' => 'test-girls', 'category' => 'test-shorts', 'specs' => ['length' => '5 in']] + $this->styleInput());
        self::assertSame(201, $listing->status(), (string) $listing->body());
        self::assertSame(['test-girls', 'test-shorts', '5 in'], [$listing->decoded()['data']['segment'], $listing->decoded()['data']['category'], $listing->decoded()['data']['specs']['length']]);
        $inUse = $this->admin('DELETE', "/v1/admin/stock/departments/{$girls['id']}");
        self::assertSame([409, 'Test girls and kids has 1 stock listing. Move it to another department first.'], [$inUse->status(), $inUse->decoded()['error']['message']]);
        self::assertSame(409, $this->admin('DELETE', "/v1/admin/stock/categories/{$shorts['id']}")->status());
        $counts = array_column($this->admin('GET', '/v1/admin/stock/departments')->decoded()['data'], 'styles', 'slug');
        self::assertSame(1, $counts['test-girls'], 'The list shows how many listings each one has');

        $this->admin('DELETE', "/v1/admin/stock/styles/{$listing->decoded()['data']['id']}");
        self::assertSame(204, $this->admin('DELETE', "/v1/admin/stock/categories/{$shorts['id']}")->status());
        self::assertSame(204, $this->admin('DELETE', "/v1/admin/stock/departments/{$girls['id']}")->status());
        self::assertSame(404, $this->admin('PUT', "/v1/admin/stock/departments/{$girls['id']}", ['name' => 'Gone'])->status());

        // The men's are the stock home page, so they stay.
        $menId = $departments[0]['id'];
        self::assertSame(409, $this->admin('DELETE', "/v1/admin/stock/departments/{$menId}")->status());
        self::assertSame(403, $this->admin('GET', '/v1/admin/stock/departments', null, self::SALES)->status(), 'Editors and admins only');
    }

    public function testCottonPantsHaveTheirOwnDetailsAndAreFilteredByCategory(): void
    {
        $input = ['name' => 'Test stock cotton pants', 'slug' => 'test-stock-cotton-pants', 'category' => 'cotton-pants',
            'specs' => ['fit' => 'Slim', 'wash' => 'Peach finish', 'pockets' => 'ignored']] + $this->styleInput();
        $style = $this->admin('POST', '/v1/admin/stock/styles', $input + ['color' => [
            'name' => 'Test khaki', 'images' => [['path' => $this->upload(), 'alt' => 'Khaki cotton pants, front']],
        ]])->decoded()['data'];
        self::assertSame(['active', 'cotton-pants', 'Cotton pants', 'chino-pant'], [$style['status'], $style['category'], $style['category_name'], $style['product_type']]);
        self::assertSame('Peach finish', $style['specs']['wash']);
        self::assertArrayNotHasKey('pockets', $style['specs'], 'Only the specs of chino pants, then the label');

        $page = $this->send('GET', '/v1/stock/styles/test-stock-cotton-pants')->decoded()['data'];
        self::assertSame('Finish', array_column($page['spec_fields'], 'label', 'key')['wash'], 'Cotton pants have a finish, not a wash');
        self::assertSame(['men', 'cotton-pants', 'Men', 'Cotton pants'], [$page['segment'], $page['category'], $page['department_name'], $page['category_name']]);

        $slugs = fn (array $query): array => array_column($this->send('GET', '/v1/stock/styles', $query)->decoded()['data'], 'slug');
        self::assertContains('test-stock-cotton-pants', $slugs(['segment' => 'men', 'category' => 'cotton-pants']));
        self::assertNotContains('test-stock-cotton-pants', $slugs(['segment' => 'men', 'category' => 'jeans']));
        self::assertSame(422, $this->send('GET', '/v1/stock/styles', ['category' => 'shirts'])->status());
        $admin = fn (array $query): array => array_column($this->admin('GET', '/v1/admin/stock/styles', $query)->decoded()['data'], 'slug');
        self::assertContains('test-stock-cotton-pants', $admin(['category' => 'cotton-pants', 'segment' => 'men']));
        self::assertNotContains('test-stock-cotton-pants', $admin(['category' => 'jeans']));

        // A form from before categories sends none: the listing keeps its own, and a new one is jeans.
        $kept = $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", array_diff_key(['status' => 'active'] + $input, ['category' => true]))->decoded()['data'];
        self::assertSame('cotton-pants', $kept['category']);
        $new = $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-no-category'] + array_diff_key($this->styleInput(), ['category' => true]));
        self::assertSame('jeans', $new->decoded()['data']['category']);

        // Moved to the boys' department, its old address leads to the new one.
        $moved = $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", ['status' => 'active', 'segment' => 'boys'] + $input);
        self::assertSame(200, $moved->status(), (string) $moved->body());
        self::assertSame('/stock/boys/test-stock-cotton-pants', $this->db->fetchValue('SELECT to_path FROM redirects WHERE from_path = ?', ['/stock/men/test-stock-cotton-pants']));
    }

    public function testTheDepartmentsAndCategoriesMigrationIsSafeToImportAgain(): void
    {
        $migration = Migrator::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0032_stock_departments_categories.sql'));
        foreach ([...$migration, ...$migration] as $statement) {
            $this->db->exec($statement);
        }

        self::assertSame(['men', 'women', 'boys'], array_column($this->db->fetchAll("SELECT slug FROM stock_departments WHERE slug IN ('men', 'women', 'boys') ORDER BY sort_order"), 'slug'));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM stock_categories WHERE slug = 'cotton-pants'"));
        self::assertSame(2, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_styles'
             AND CONSTRAINT_NAME IN ('stock_styles_department', 'stock_styles_category') AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        ));
    }

    public function testTheStyleNoMigrationEndsExistingAddressesWithIt(): void
    {
        $photo = fn (string $folder = 'stock'): array => ['images' => [['path' => $this->upload($folder), 'alt' => 'Test jeans, front']]];
        $listing = fn (array $input): array => $this->admin('POST', '/v1/admin/stock/styles', $input + $this->styleInput())->decoded()['data'];
        // Saved before addresses ended with the style no.: a shown listing and its duplicate (same name and style no.),
        // drafts with a typed address and with their color, one whose address another listing has with the style no.,
        // one that has it already, and one without a style no.
        $original = $listing(['style_no' => 'PK-1051', 'color' => ['name' => 'Test indigo'] + $photo()]);
        $duplicate = $listing(['slug' => 'test-stock-slim-jeans-2', 'style_no' => 'PK-1051', 'color' => ['name' => 'Test black'] + $photo()]);
        $listing(['slug' => 'test-stock-own-address', 'style_no' => 'PK-9']);
        $listing(['name' => 'Test stock wide jeans', 'slug' => 'test-stock-wide-jeans-mid-blue', 'style_no' => '77']);
        $listing(['name' => 'Test stock narrow jeans', 'slug' => 'test-stock-narrow-jeans', 'style_no' => '88']);
        $listing(['name' => 'Test stock narrow jeans', 'slug' => 'test-stock-narrow-jeans-88', 'style_no' => null]);
        $listing(['name' => 'Test stock baggy jeans', 'slug' => 'test-stock-baggy-jeans-pk-5', 'style_no' => 'PK-5']);
        $listing(['name' => 'Test stock loose jeans', 'slug' => 'test-stock-loose-jeans', 'style_no' => null]);
        $wholesale = $this->admin('POST', '/v1/admin/products', [
            'name' => 'Test stock wholesale jeans', 'slug' => 'test-stock-wholesale-jeans', 'style_no' => 'WS-1', 'segment' => 'men', 'product_type' => 'jeans-pant',
            'sizes' => ['30'], 'price_cents' => 650, 'color' => ['name' => 'Rinse'] + $photo('products'),
        ])->decoded()['data'];
        self::assertSame(['active', 'active', 'active'], [$original['status'], $duplicate['status'], $wholesale['status']]);

        // The preview in the migration's notes (as staff copy it into phpMyAdmin) lists the changes and changes nothing.
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/0033_style_no_in_web_addresses.sql');
        self::assertSame(1, preg_match('~preview: copy from the next line -+\R(.+?)\R-+ preview: copy up to the line above~s', $sql, $match));
        $preview = array_values(array_filter(
            $this->db->fetchAll($match[1]),
            static fn (array $row): bool => str_starts_with((string) $row['old_slug'], 'test-stock'),
        ));
        $expected = [
            ['wholesale', 'test-stock-wholesale-jeans', 'test-stock-wholesale-jeans-ws-1'],
            ['stock', 'test-stock-slim-jeans', 'test-stock-slim-jeans-pk-1051'],
            ['stock', 'test-stock-slim-jeans-2', 'test-stock-slim-jeans-pk-1051-2'],
            ['stock', 'test-stock-own-address', 'test-stock-own-address-pk-9'],
            ['stock', 'test-stock-wide-jeans-mid-blue', 'test-stock-wide-jeans-mid-blue-77'],
            ['stock', 'test-stock-narrow-jeans', 'test-stock-narrow-jeans-88-2'],
        ];
        self::assertSame($expected, array_map(static fn (array $row): array => [$row['shop'], $row['old_slug'], $row['new_slug']], $preview));
        self::assertSame('test-stock-slim-jeans', $this->db->fetchValue('SELECT slug FROM stock_styles WHERE id = ?', [$original['id']]));

        // Imported twice: the second time there is nothing left to change.
        $migration = Migrator::split($sql);
        foreach ([...$migration, ...$migration] as $statement) {
            $this->db->exec($statement);
        }
        $slugs = array_column($this->db->fetchAll("SELECT slug FROM stock_styles WHERE slug LIKE 'test-stock%' UNION ALL SELECT slug FROM products WHERE slug LIKE 'test-stock%'"), 'slug');
        foreach ($expected as [, $old, $new]) {
            self::assertContains($new, $slugs);
            self::assertNotContains($old, $slugs);
        }
        self::assertContains('test-stock-baggy-jeans-pk-5', $slugs, 'Has its style no. already');
        self::assertContains('test-stock-loose-jeans', $slugs, 'No style no.');

        // Shown ones keep their old address working; drafts were not on the website.
        $redirect = fn (string $from): mixed => $this->db->fetchValue('SELECT to_path FROM redirects WHERE from_path = ?', [$from]);
        self::assertSame('/stock/men/test-stock-slim-jeans-pk-1051', $redirect('/stock/men/test-stock-slim-jeans'));
        self::assertSame('/stock/men/test-stock-slim-jeans-pk-1051-2', $redirect('/stock/men/test-stock-slim-jeans-2'));
        self::assertSame('/wholesale-shop/men/jeans-pant/test-stock-wholesale-jeans-ws-1', $redirect('/wholesale-shop/men/jeans-pant/test-stock-wholesale-jeans'));
        self::assertNull($redirect('/stock/men/test-stock-own-address'));
    }

    public function testRenamingAShownStyleRedirectsItsOldAddress(): void
    {
        $style = $this->admin('POST', '/v1/admin/stock/styles', $this->styleInput())->decoded()['data'];
        $this->admin('POST', "/v1/admin/stock/styles/{$style['id']}/colors", ['code' => 'TEST-ST-3', 'name' => 'Ice blue', 'images' => [['path' => $this->upload(), 'alt' => 'Ice blue jeans']]]);
        $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", ['status' => 'active'] + $this->styleInput());

        $renamed = $this->admin('PUT', "/v1/admin/stock/styles/{$style['id']}", ['status' => 'active', 'slug' => 'test-stock-slim-jeans-2'] + $this->styleInput());
        self::assertSame(200, $renamed->status(), (string) $renamed->body());
        self::assertSame(
            '/stock/men/test-stock-slim-jeans-2',
            $this->db->fetchValue('SELECT to_path FROM redirects WHERE from_path = ?', ['/stock/men/test-stock-slim-jeans']),
        );
    }

    /**
     * Every list shows the newest first, by the date added (owner request 2026-11-18): the wholesale shop, its admin
     * list, the stock pages (those in stock first) and the stock admin list.
     */
    public function testListsShowTheNewestFirst(): void
    {
        // Added on these dates, in another order than their ids: the dates decide.
        $added = ['a' => '2026-11-10 09:00:00', 'b' => '2026-09-01 09:00:00', 'c' => '2026-10-05 09:00:00'];
        $prices = ['a' => 600, 'b' => 600, 'c' => 500];
        foreach ($added as $key => $date) {
            $input = [
                'name' => "Test stock order {$key} jeans", 'slug' => "test-stock-order-{$key}", 'segment' => 'men', 'product_type' => 'jeans-pant',
                'sizes' => ['30', '32'], 'price_cents' => $prices[$key], 'is_featured' => $key === 'b',
            ];
            $id = $this->admin('POST', '/v1/admin/products', $input)->decoded()['data']['id'];
            $color = $this->admin('POST', "/v1/admin/products/{$id}/colors", [
                'code' => "TEST-ORD-{$key}", 'name' => 'Rinse', 'images' => [['path' => $this->upload('products'), 'alt' => 'Test jeans, front']],
            ]);
            self::assertSame(201, $color->status(), (string) $color->body());
            self::assertSame(200, $this->admin('PUT', "/v1/admin/products/{$id}", ['status' => 'active'] + $input)->status());
            $this->db->execute('UPDATE products SET created_at = ? WHERE id = ?', [$date, $id]);
        }
        $shop = fn (array $query): array => array_column($this->send('GET', '/v1/products', ['q' => 'Test stock order'] + $query)->decoded()['data'], 'slug');
        self::assertSame(['test-stock-order-a', 'test-stock-order-c', 'test-stock-order-b'], $shop([]), 'The newest first');
        self::assertSame(['test-stock-order-b', 'test-stock-order-a', 'test-stock-order-c'], $shop(['sort' => 'featured']), 'Featured first, then the newest');
        self::assertSame(['test-stock-order-c', 'test-stock-order-a', 'test-stock-order-b'], $shop(['sort' => 'price-asc']), 'The same price: the newest first');
        $related = array_column($this->send('GET', '/v1/products/test-stock-order-b')->decoded()['data']['related'], 'slug');
        self::assertSame(['test-stock-order-a', 'test-stock-order-c'], array_values(array_filter($related, static fn (string $slug): bool => str_starts_with($slug, 'test-stock-order'))));
        $admin = $this->admin('GET', '/v1/admin/products', ['q' => 'Test stock order'])->decoded()['data'];
        self::assertSame(['test-stock-order-a', 'test-stock-order-c', 'test-stock-order-b'], array_column($admin, 'slug'));
        self::assertSame('2026-11-10T09:00:00+00:00', $admin[0]['created_at'], 'With the date added');

        // The stock: those in stock first, the newest first in each; the admin list by date only.
        $added = ['a' => '2026-11-12 09:00:00', 'b' => '2026-09-03 09:00:00', 'c' => '2026-10-07 09:00:00'];
        $sets = ['a' => 0, 'b' => 4, 'c' => 2];
        foreach ($added as $key => $date) {
            $style = $this->admin('POST', '/v1/admin/stock/styles', [
                'name' => "Test stock order {$key} jeans", 'slug' => "test-stock-order-{$key}", 'is_featured' => $key === 'b', 'sort_order' => 5,
                'color' => ['name' => 'Rinse', 'code' => "TEST-SORD-{$key}", 'sets_in_stock' => $sets[$key], 'images' => [['path' => $this->upload(), 'alt' => 'Test stock jeans, front']]],
            ] + $this->styleInput());
            self::assertSame([201, 'active'], [$style->status(), $style->decoded()['data']['status'] ?? null], (string) $style->body());
            $this->db->execute('UPDATE stock_styles SET created_at = ? WHERE id = ?', [$date, $style->decoded()['data']['id']]);
        }
        $cards = array_values(array_filter($this->send('GET', '/v1/stock/styles')->decoded()['data'], static fn (array $card): bool => str_starts_with($card['slug'], 'test-stock-order')));
        self::assertSame(['test-stock-order-c', 'test-stock-order-b', 'test-stock-order-a'], array_column($cards, 'slug'), 'In stock first, the newest first');
        self::assertSame([true, 5], [$cards[1]['is_featured'], $cards[1]['sort_order']], 'For the website\'s "Featured" order');
        $listings = $this->admin('GET', '/v1/admin/stock/styles', ['q' => 'Test stock order'])->decoded()['data'];
        self::assertSame(['test-stock-order-a', 'test-stock-order-c', 'test-stock-order-b'], array_column($listings, 'slug'));
        self::assertSame('2026-11-12T09:00:00+00:00', $listings[0]['created_at']);
    }

    /** @return array<string, mixed> */
    private function styleInput(): array
    {
        return [
            'name' => 'Test stock slim jeans',
            'slug' => 'test-stock-slim-jeans',
            'segment' => 'men',
            'category' => 'jeans',
            'specs' => ['fit' => 'Slim', 'stretch' => 'stretch', 'wash' => 'Stone wash', 'label' => ' Test label ', 'neckline' => 'ignored'],
            'set_sizes' => [['size' => '32', 'pcs' => 2], ['size' => '28', 'pcs' => 1], ['size' => '34', 'pcs' => 1], ['size' => '30', 'pcs' => 2]],
            'piece_price_cents' => 200000,
        ];
    }

    private function upload(string $folder = 'stock'): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pk') . '.jpg';
        $image = imagecreatetruecolor(1200, 1600);
        imagefill($image, 0, 0, imagecolorallocate($image, 52, 84, 130));
        imagejpeg($image, $file);
        $this->files[] = $file;
        $stored = $this->app->container->get(ImageStore::class)->store($file, 'test-stock-jeans.jpg', $folder);
        $this->files[] = $stored['path'];

        return $stored['path'];
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return array<string, mixed>
     */
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
    private function admin(string $method, string $path, ?array $body = null, string $email = self::EDITOR): Response
    {
        if (!isset($this->tokens[$email])) {
            $login = $this->send('POST', '/v1/admin/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
            $this->tokens[$email] = $login->decoded()['data']['token'];
        }

        return $this->send($method, $path, $body, $this->tokens[$email]);
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
