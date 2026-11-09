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

/** Stock: ready jeans sold by set, the stock pages' endpoints and the stock admin. */
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
        $this->db->execute("DELETE FROM products WHERE slug LIKE 'test-stock%'");
        $this->db->execute("DELETE FROM redirects WHERE from_path LIKE '/stock/%/test-stock%'");
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
        self::assertSame('draft', $style['status'], 'New styles start as drafts');
        self::assertSame([['size' => '28', 'pcs' => 1], ['size' => '30', 'pcs' => 2], ['size' => '32', 'pcs' => 2], ['size' => '34', 'pcs' => 1]], $style['set_sizes'], 'Sizes are sorted');
        self::assertSame(6, $style['set_pieces']);
        self::assertSame('stretch', $style['specs']['stretch']);
        self::assertNull($style['specs']['rise']);

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
        self::assertSame(['28', '30', '32', '34'], $card['sizes']);
        self::assertSame(1250000, $card['set_price_cents']);
        self::assertSame(12, $card['sets_in_stock']);
        self::assertStringStartsWith('uploads/stock/', $card['colors'][0]['image']['path']);
        self::assertSame([], array_filter(
            $this->send('GET', '/v1/stock/styles', ['segment' => 'boys'])->decoded()['data'],
            static fn (array $c): bool => $c['slug'] === 'test-stock-slim-jeans',
        ), 'Men\'s styles are not on the boys\' page');
        self::assertSame(422, $this->send('GET', '/v1/stock/styles', ['segment' => 'women'])->status(), 'Stock is men and boys only');

        $page = $this->send('GET', '/v1/stock/styles/test-stock-slim-jeans')->decoded()['data'];
        self::assertSame(6, $page['set']['pieces']);
        self::assertSame('Inseam', array_column($page['spec_fields'], 'label', 'key')['length']);
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
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['segment' => 'women'] + $this->styleInput())->status(), 'Men and boys only');

        $boys = $this->admin('POST', '/v1/admin/stock/styles', [
            'name' => 'Test stock boys jeans', 'slug' => 'test-stock-boys-jeans', 'segment' => 'boys', 'set_price_cents' => 900000,
            'set_sizes' => [['size' => '26', 'pcs' => 1], ['size' => '16', 'pcs' => 1], ['size' => '18', 'pcs' => 1]],
        ]);
        self::assertSame(201, $boys->status(), (string) $boys->body());
        self::assertSame(['16', '18', '26'], array_column($boys->decoded()['data']['set_sizes'], 'size'));
        self::assertSame(422, $this->admin('POST', '/v1/admin/stock/styles', ['slug' => 'test-stock-boys-jeans'] + $this->styleInput())->status(), 'Web addresses are unique');

        self::assertSame(403, $this->admin('GET', '/v1/admin/stock/styles', null, self::SALES)->status(), 'Editors and admins manage stock');
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
        self::assertStringStartsWith("\xEF\xBB\xBFStyle no.,Style,For,Color code,Color,Sets in stock", $export->body());
        self::assertStringContainsString('TEST-ST-A,"Test indigo",10,6,12500,No', $export->body(), 'A draft style is not shown');

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
        self::assertSame([2, 2, 2 * 1250000, 4], [$row['sets_in_stock'], $row['sold_sets'], $row['value_cents'], $row['received_sets']]);
        self::assertSame(403, $this->admin('GET', '/v1/admin/stock/export', null, self::SALES)->status(), 'Sales staff read the report only');
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
        self::assertSame('test-stock-straight-jeans-black', $this->wholesaleRow($blackId)['slug']);
        self::assertSame(['30', '32', '34', '36'], array_column($row['set_sizes'], 'size'));
        self::assertSame([2, 2, 2, 2], array_column($row['set_sizes'], 'pcs'), 'The wholesale set: 2 pieces per size');
        self::assertSame(['Made to order in our factory, from 100 pieces per color.'], $row['left_out']);

        $copied = $this->admin('POST', '/v1/admin/stock/from-wholesale', ['color_ids' => [$rinseId, $blackId]])->decoded()['data']['copied'];
        self::assertSame(
            [[$rinseId, 'Rinse', 1, true, true], [$blackId, 'Black', 1, true, true]],
            array_map(static fn (array $c): array => [$c['color_id'], $c['color'], $c['photos'], $c['new'], $c['shown']], $copied),
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
            ['test-stock-slim-jeans-black', 'Test stock slim jeans', 'draft', 1250000, ['TEST-ST-S2']],
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

    /** @return array<string, mixed> */
    private function styleInput(): array
    {
        return [
            'name' => 'Test stock slim jeans',
            'slug' => 'test-stock-slim-jeans',
            'segment' => 'men',
            'specs' => ['fit' => 'Slim', 'stretch' => 'stretch', 'wash' => 'Stone wash', 'neckline' => 'ignored'],
            'set_sizes' => [['size' => '32', 'pcs' => 2], ['size' => '28', 'pcs' => 1], ['size' => '34', 'pcs' => 1], ['size' => '30', 'pcs' => 2]],
            'set_price_cents' => 1250000,
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
