<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\RateLimiter;
use App\Models\MissingPage;
use App\Models\Settings;
use App\Services\DailyJob;

/**
 * Phase 6: redirects managed in the admin, the missing addresses the website reports, automatic redirects when a
 * style or post changes its address, and the launch checklist.
 */
final class LaunchTest extends OrderTestCase
{
    private const POST_SLUGS = ['test-launch-post', 'test-launch-post-renamed'];

    protected function testIp(): string
    {
        return '192.0.2.86';
    }

    protected function testEmails(): array
    {
        return ['launch-buyer@example.test'];
    }

    protected function staffEmails(): array
    {
        return ['admin' => 'launch-admin@example.test', 'editor' => 'launch-editor@example.test', 'sales' => 'launch-sales@example.test'];
    }

    protected function fixtureSlug(): string
    {
        return 'test-style-launch';
    }

    private mixed $savedChecklist = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpLaunch();
        $this->savedChecklist = $this->app->container->get(Settings::class)->get('launch_checklist');
    }

    protected function tearDown(): void
    {
        $this->cleanUpLaunch();
        if ($this->savedChecklist === null) {
            $this->db->execute("DELETE FROM settings WHERE name = 'launch_checklist'");
        } else {
            $this->app->container->get(Settings::class)->set('launch_checklist', $this->savedChecklist, false);
        }
        parent::tearDown();
    }

    public function testStaffManageRedirects(): void
    {
        $editor = $this->staffLogin('editor');
        $add = fn (string $from, string $to) => $this->send('POST', '/v1/admin/redirects', ['from_path' => $from, 'to_path' => $to], $editor);

        // A pasted address keeps only its decoded path.
        $created = $add('https://www.pkapparel.com/TEST-Old%20Page/?utm=x', '/wholesale-shop/men');
        self::assertSame(201, $created->status(), (string) json_encode($created->decoded()));
        self::assertSame(['/TEST-Old Page', '/wholesale-shop/men', 'staff', 'Added by staff'], array_values(array_intersect_key(
            $created->decoded()['data'],
            array_flip(['from_path', 'to_path', 'source', 'source_label']),
        )));
        self::assertContains(['from' => '/TEST-Old Page', 'to' => '/wholesale-shop/men'], $this->send('GET', '/v1/redirects')->decoded()['data']);

        // Refused: the same address (any case), the website's own areas, the home page, a live style, bad targets.
        self::assertArrayHasKey('from_path', $add('/test-old page', '/wholesale-shop/women')->decoded()['error']['fields']);
        self::assertArrayHasKey('from_path', $add('/admin/orders', '/')->decoded()['error']['fields']);
        self::assertArrayHasKey('from_path', $add('/', '/blog')->decoded()['error']['fields']);
        self::assertArrayHasKey('from_path', $add('/wholesale-shop/men/jeans-pant/' . $this->fixtureSlug(), '/blog')->decoded()['error']['fields']);
        self::assertArrayHasKey('to_path', $add('/TEST-a', 'javascript:alert(1)')->decoded()['error']['fields']);
        self::assertArrayHasKey('to_path', $add('/TEST-a', '/TEST-a/')->decoded()['error']['fields']);
        self::assertSame(201, $add('/TEST-external', 'https://example.com/catalog')->status());
        // An address on this website (www or not) becomes a path.
        $site = (string) parse_url($this->app->container->get(Config::class)->string('web.url'), PHP_URL_HOST);
        self::assertSame('/blog?page=2', $add('/TEST-own-domain', "https://www.{$site}/blog?page=2")->decoded()['data']['to_path']);

        // A redirect may lead to an address that redirects again (the website follows the chain), and keeps doing so when that one changes.
        self::assertSame('/TEST-Old%20Page', $add('/TEST-older', '/TEST-Old Page')->decoded()['data']['to_path']);
        $page = $this->find('/TEST-Old Page');
        self::assertSame(200, $this->send('PUT', "/v1/admin/redirects/{$page['id']}", ['from_path' => '/TEST-Old Page', 'to_path' => '/wholesale-shop/women'], $editor)->status());
        self::assertSame('/TEST-Old%20Page', $this->find('/TEST-older')['to_path']);
        // …and a circle through it is refused.
        self::assertStringContainsString('circle', $this->send('PUT', "/v1/admin/redirects/{$page['id']}", ['from_path' => '/TEST-Old Page', 'to_path' => '/TEST-older'], $editor)->decoded()['error']['fields']['to_path']);

        // No circles.
        self::assertSame(201, $add('/TEST-a', '/TEST-b')->status());
        $circle = $add('/TEST-b', '/TEST-a');
        self::assertSame(422, $circle->status());
        self::assertStringContainsString('circle', $circle->decoded()['error']['fields']['to_path']);

        // Sales staff cannot change redirects; deleting one removes it from the website's list.
        $sales = $this->staffLogin('sales');
        self::assertSame(403, $this->send('GET', '/v1/admin/redirects', null, $sales)->status());
        $list = $this->send('GET', '/v1/admin/redirects', ['q' => 'TEST-'], $editor)->decoded();
        self::assertGreaterThanOrEqual(5, $list['meta']['pagination']['total']);
        self::assertArrayHasKey('missing', $list['meta']['counts']);
        self::assertSame(204, $this->send('DELETE', "/v1/admin/redirects/{$page['id']}", null, $editor)->status());
        self::assertNotContains('/TEST-Old Page', array_column($this->send('GET', '/v1/redirects')->decoded()['data'], 'from'));
    }

    public function testMissingAddressesAreReportedAndResolved(): void
    {
        $report = fn (string $path, ?string $referrer = null) => $this->send('POST', '/v1/missing-pages', ['path' => $path, 'referrer' => $referrer]);
        self::assertSame(204, $report('/test-missing-page/', 'https://www.google.com/')->status());
        $report('/TEST-missing-page');
        // Bot probes and the website's own areas are not recorded.
        foreach (['/wp-login.php', '/.env', '/admin/nothing', '/test-missing.php', '/'] as $junk) {
            self::assertSame(204, $report($junk)->status());
        }

        $editor = $this->staffLogin('editor');
        $open = $this->send('GET', '/v1/admin/missing-pages', null, $editor)->decoded();
        $entry = $this->missing($open['data'], '/test-missing-page');
        self::assertSame([2, 'https://www.google.com/', false], [$entry['hits'], $entry['last_referrer'], $entry['ignored']]);
        foreach (['/wp-login.php', '/.env', '/admin/nothing', '/test-missing.php'] as $junk) {
            self::assertNull($this->missing($open['data'], $junk), $junk);
        }
        self::assertGreaterThanOrEqual(1, $this->send('GET', '/v1/admin/dashboard', null, $editor)->decoded()['data']['content']['missing_pages']);

        // Ignored entries move to their own list, and back.
        self::assertSame(204, $this->send('POST', "/v1/admin/missing-pages/{$entry['id']}/ignore", null, $editor)->status());
        self::assertNotNull($this->missing($this->send('GET', '/v1/admin/missing-pages', ['status' => 'ignored'], $editor)->decoded()['data'], '/test-missing-page'));
        self::assertNull($this->missing($this->send('GET', '/v1/admin/missing-pages', null, $editor)->decoded()['data'], '/test-missing-page'));
        self::assertSame(204, $this->send('POST', "/v1/admin/missing-pages/{$entry['id']}/restore", null, $editor)->status());

        // Redirecting the address takes it off the list; reports of a redirected address are not recorded.
        $this->send('POST', '/v1/admin/redirects', ['from_path' => '/test-missing-page', 'to_path' => '/blog'], $editor);
        self::assertNull($this->missing($this->send('GET', '/v1/admin/missing-pages', null, $editor)->decoded()['data'], '/test-missing-page'));
        $report('/test-missing-page');
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM missing_pages WHERE path = '/test-missing-page'"));

        // Each visitor can report up to 30 an hour; more are dropped without an error.
        (new RateLimiter($this->db))->clear('missing-page:' . $this->testIp());
        for ($i = 0; $i < 32; $i++) {
            self::assertSame(204, $report('/test-missing-flood')->status());
        }
        self::assertSame(30, (int) $this->db->fetchValue("SELECT hits FROM missing_pages WHERE path = '/test-missing-flood'"));

        // Old entries nobody hits any more are pruned.
        $this->db->execute("UPDATE missing_pages SET last_seen_at = '2020-01-01 00:00:00' WHERE path = '/test-missing-flood'");
        $this->app->container->get(MissingPage::class)->prune(gmdate('Y-m-d H:i:s', strtotime('-90 days')));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM missing_pages WHERE path = '/test-missing-flood'"));
    }

    public function testChangedAddressesOfStylesAndPostsKeepWorking(): void
    {
        $admin = $this->staffLogin('admin');
        $style = $this->send('GET', "/v1/admin/products/{$this->productId}", null, $admin)->decoded()['data'];
        $moveStyle = function (string $slug) use ($style, $admin): void {
            // Hidden while it has no photos; it was in the shop, so its address moves.
            $response = $this->send('PUT', "/v1/admin/products/{$this->productId}", ['slug' => $slug, 'status' => 'draft'] + $style, $admin);
            self::assertSame(200, $response->status(), (string) json_encode($response->decoded()));
            $this->db->execute("UPDATE products SET status = 'active' WHERE id = ?", [$this->productId]);
        };
        $original = '/wholesale-shop/men/jeans-pant/' . $this->fixtureSlug();
        $renamed = $original . '-renamed';

        $moveStyle($this->fixtureSlug() . '-renamed');
        self::assertSame(['to_path' => $renamed, 'source' => 'style'], array_intersect_key($this->find($original) ?? [], array_flip(['to_path', 'source'])));
        // Moved back: the original address is live again and the renamed one leads to it, with no loop.
        $moveStyle($this->fixtureSlug());
        self::assertNull($this->find($original));
        self::assertSame($original, $this->find($renamed)['to_path']);

        // A published post.
        $post = ['title' => 'TEST launch post', 'slug' => 'test-launch-post', 'body_html' => '<p>Hello buyers.</p>', 'status' => 'published', 'published_at' => '2026-01-01T00:00:00Z'];
        $created = $this->send('POST', '/v1/admin/posts', $post, $admin);
        self::assertSame(201, $created->status(), (string) json_encode($created->decoded()));
        $id = $created->decoded()['data']['id'];
        self::assertSame(200, $this->send('PUT', "/v1/admin/posts/{$id}", ['slug' => 'test-launch-post-renamed'] + $post, $admin)->status());
        self::assertSame(['to_path' => '/post/test-launch-post-renamed', 'source' => 'post'], array_intersect_key($this->find('/post/test-launch-post') ?? [], array_flip(['to_path', 'source'])));
    }

    public function testTheLaunchChecklist(): void
    {
        $admin = $this->staffLogin('admin');
        $items = $this->send('GET', '/v1/admin/launch-checklist', null, $admin)->decoded()['data'];
        $byKey = array_column($items, null, 'key');
        foreach (['company', 'bank', 'freight', 'courier', 'addons', 'email', 'admin', 'certificates', 'factory', 'daily_job', 'legal_review', 'photos', 'search_console', 'test_order'] as $key) {
            self::assertArrayHasKey($key, $byKey);
        }
        // The test bank details count; the test zone has no courier rate.
        self::assertTrue($byKey['bank']['done']);
        self::assertFalse($byKey['courier']['done']);
        self::assertStringContainsString('TEST zone', (string) $byKey['courier']['note']);
        self::assertFalse($byKey['email']['done'], 'Tests write email to the log');

        // The daily job records when it ran.
        $this->app->container->get(DailyJob::class)->run();
        $byKey = array_column($this->send('GET', '/v1/admin/launch-checklist', null, $admin)->decoded()['data'], null, 'key');
        self::assertTrue($byKey['daily_job']['done']);

        // Items the site cannot check are ticked by hand; the others cannot be.
        $ticked = $this->send('PUT', '/v1/admin/launch-checklist/legal_review', ['done' => true], $admin)->decoded()['data'];
        self::assertTrue(array_column($ticked, null, 'key')['legal_review']['done']);
        $unticked = $this->send('PUT', '/v1/admin/launch-checklist/legal_review', ['done' => false], $admin)->decoded()['data'];
        self::assertFalse(array_column($unticked, null, 'key')['legal_review']['done']);
        self::assertSame(404, $this->send('PUT', '/v1/admin/launch-checklist/bank', ['done' => true], $admin)->status());

        // Admins only.
        self::assertSame(403, $this->send('GET', '/v1/admin/launch-checklist', null, $this->staffLogin('editor'))->status());
    }

    /** @return array<string, mixed>|null the redirect from this path */
    private function find(string $from): ?array
    {
        $row = $this->db->fetch('SELECT id, from_path, to_path, source FROM redirects WHERE from_path = ?', [$from]);

        return $row === null ? null : ['id' => (int) $row['id']] + $row;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function missing(array $entries, string $path): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['path'] === $path) {
                return $entry;
            }
        }

        return null;
    }

    private function cleanUpLaunch(): void
    {
        $this->db->execute("DELETE FROM redirects WHERE from_path LIKE '/test-%' OR from_path LIKE '/post/test-launch-%' OR from_path LIKE '/wholesale-shop/men/jeans-pant/test-style-launch%'");
        $this->db->execute("DELETE FROM missing_pages WHERE path LIKE '/test-%'");
        $this->db->execute('DELETE FROM posts WHERE slug IN (?, ?)', self::POST_SLUGS);
        (new RateLimiter($this->db))->clear('missing-page:' . $this->testIp());
    }
}
