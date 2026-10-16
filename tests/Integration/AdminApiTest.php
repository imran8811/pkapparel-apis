<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Models\Settings;
use App\Models\StaffUser;

/** The admin API through App::handle: login, roles, and editing content (real routes, real database). */
final class AdminApiTest extends IntegrationTestCase
{
    private const TEST_IP = '192.0.2.78'; // TEST-NET address

    private const PASSWORD = 'correct horse battery';

    /** @var array<string, string> role => email */
    private const ACCOUNTS = [
        'admin' => 'admin@example.test',
        'editor' => 'editor@example.test',
        'sales' => 'sales@example.test',
    ];

    /** @var array<string, mixed> */
    private array $savedFacts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();

        $staff = $this->app->container->get(StaffUser::class);
        foreach (self::ACCOUNTS as $role => $email) {
            $staff->create(ucfirst($role) . ' Tester', $email, self::PASSWORD, $role);
        }
        $this->savedFacts = $this->app->container->get(Settings::class)->get('factory_facts', []);
    }

    protected function tearDown(): void
    {
        $this->app->container->get(Settings::class)->set('factory_facts', $this->savedFacts, true);
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $emails = array_values(self::ACCOUNTS);
        $in = implode(', ', array_fill(0, count($emails), '?'));
        $this->db->execute(
            "DELETE t FROM auth_tokens t JOIN staff_users s ON s.id = t.user_id AND t.user_type = 'staff' WHERE s.email IN ({$in})",
            $emails,
        );
        $this->db->execute("DELETE FROM staff_users WHERE email IN ({$in})", $emails);
        $this->db->execute("DELETE FROM faqs WHERE question LIKE 'TEST:%'");
        $this->db->execute("DELETE FROM posts WHERE slug LIKE 'test-post-%'");
        $this->db->execute("DELETE FROM inquiries WHERE email = 'pipeline@example.test'");
        $limiter = new RateLimiter($this->db);
        $limiter->clear('staff-login-ip:' . self::TEST_IP);
        foreach (self::ACCOUNTS as $email) {
            $limiter->clear('staff-login:' . $email);
        }
    }

    public function testLoginMeAndLogout(): void
    {
        $wrong = $this->send('POST', '/v1/admin/auth/login', ['email' => self::ACCOUNTS['admin'], 'password' => 'nope']);
        self::assertSame(401, $wrong->status());
        self::assertSame('invalid_credentials', $wrong->decoded()['error']['code']);

        $token = $this->login('admin');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $stored = $this->db->fetchValue('SELECT token_hash FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        self::assertNotNull($stored, 'Only the hash of the token is stored');

        $me = $this->send('GET', '/v1/admin/auth/me', null, $token)->decoded()['data'];
        self::assertSame(['id', 'name', 'email', 'role'], array_keys($me));
        self::assertSame('admin', $me['role']);

        self::assertSame(204, $this->send('POST', '/v1/admin/auth/logout', null, $token)->status());
        $after = $this->send('GET', '/v1/admin/auth/me', null, $token);
        self::assertSame(401, $after->status());
        self::assertSame('session_expired', $after->decoded()['error']['code']);
    }

    public function testAdminRoutesNeedAValidToken(): void
    {
        self::assertSame(401, $this->send('GET', '/v1/admin/dashboard')->status());
        self::assertSame(401, $this->send('GET', '/v1/admin/dashboard', null, str_repeat('a', 64))->status());

        $noClientKey = $this->app->handle(new Request('POST', '/v1/admin/auth/login', [], ['content-type' => 'application/json'], '{}'));
        self::assertSame(401, $noClientKey->status());
    }

    public function testDeactivatedAccountsAreLockedOutImmediately(): void
    {
        $token = $this->login('editor');
        $this->db->execute('UPDATE staff_users SET is_active = 0 WHERE email = ?', [self::ACCOUNTS['editor']]);

        self::assertSame(401, $this->send('GET', '/v1/admin/faqs', null, $token)->status());
        self::assertSame(401, $this->send('POST', '/v1/admin/auth/login', ['email' => self::ACCOUNTS['editor'], 'password' => self::PASSWORD])->status());
    }

    public function testLoginIsRateLimitedPerAccount(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->send('POST', '/v1/admin/auth/login', ['email' => self::ACCOUNTS['sales'], 'password' => 'wrong-' . $i]);
        }

        $blocked = $this->send('POST', '/v1/admin/auth/login', ['email' => self::ACCOUNTS['sales'], 'password' => self::PASSWORD]);
        self::assertSame(429, $blocked->status());
        self::assertArrayHasKey('Retry-After', $blocked->headers());
    }

    public function testRolesLimitWhatStaffCanDo(): void
    {
        $editor = $this->login('editor');
        $sales = $this->login('sales');

        self::assertSame(200, $this->send('GET', '/v1/admin/faqs', null, $editor)->status());
        self::assertSame(403, $this->send('GET', '/v1/admin/inquiries', null, $editor)->status());
        self::assertSame(403, $this->send('GET', '/v1/admin/settings', null, $editor)->status());

        self::assertSame(200, $this->send('GET', '/v1/admin/inquiries', null, $sales)->status());
        self::assertSame(403, $this->send('POST', '/v1/admin/faqs', ['category' => 'shipping'], $sales)->status());

        $dashboard = $this->send('GET', '/v1/admin/dashboard', null, $editor)->decoded()['data'];
        self::assertArrayHasKey('content', $dashboard);
        self::assertArrayNotHasKey('inquiries', $dashboard);
    }

    public function testFaqCrud(): void
    {
        $token = $this->login('editor');

        $invalid = $this->send('POST', '/v1/admin/faqs', ['category' => 'nope', 'question' => 'Hi', 'answer' => ''], $token);
        self::assertSame(422, $invalid->status());
        self::assertSame(['category', 'question', 'answer'], array_keys($invalid->decoded()['error']['fields']));

        $created = $this->send('POST', '/v1/admin/faqs', [
            'category' => 'shipping', 'question' => 'TEST: Do you ship to Iceland?', 'answer' => 'Yes, by air and sea.',
        ], $token);
        self::assertSame(201, $created->status());
        $faq = $created->decoded()['data'];
        self::assertTrue($faq['is_published']);
        self::assertIsInt($faq['sort_order']);

        $updated = $this->send('PUT', "/v1/admin/faqs/{$faq['id']}", [
            'category' => 'shipping', 'question' => 'TEST: Do you ship to Iceland?', 'answer' => 'Yes.', 'is_published' => false,
        ], $token)->decoded()['data'];
        self::assertSame('Yes.', $updated['answer']);
        self::assertFalse($updated['is_published']);
        self::assertSame($faq['sort_order'], $updated['sort_order'], 'Sort order is kept when not sent');

        $public = $this->send('GET', '/v1/faqs')->decoded()['data'];
        self::assertNotContains('TEST: Do you ship to Iceland?', array_column($public, 'question'), 'Hidden FAQs stay off the website');

        self::assertSame(204, $this->send('DELETE', "/v1/admin/faqs/{$faq['id']}", null, $token)->status());
        self::assertSame(404, $this->send('PUT', "/v1/admin/faqs/{$faq['id']}", ['category' => 'shipping', 'question' => 'TEST: x?', 'answer' => 'y'], $token)->status());
    }

    public function testPostsAreSanitisedAndNeedUniqueSlugs(): void
    {
        $token = $this->login('editor');
        $payload = [
            'title' => 'Test post: denim care',
            'slug' => 'test-post-denim-care',
            'body_html' => '<p onclick="x()">Wash <strong>inside out</strong>.</p><script>alert(1)</script><ul><li><p>Cold water</p></li></ul>',
            'status' => 'published',
        ];

        $created = $this->send('POST', '/v1/admin/posts', $payload, $token);
        self::assertSame(201, $created->status());
        $post = $created->decoded()['data'];
        self::assertSame('<p>Wash <strong>inside out</strong>.</p><ul><li>Cold water</li></ul>', $post['body_html']);
        self::assertSame('Wash inside out.', $post['excerpt']);
        self::assertNotNull($post['published_at']);
        self::assertSame(200, $this->send('GET', '/v1/posts/test-post-denim-care')->status());

        $duplicate = $this->send('POST', '/v1/admin/posts', $payload, $token);
        self::assertSame(422, $duplicate->status());
        self::assertArrayHasKey('slug', $duplicate->decoded()['error']['fields']);

        $draft = $this->send('PUT', "/v1/admin/posts/{$post['id']}", ['slug' => 'test-post-denim-care-2', 'status' => 'draft'] + $payload, $token);
        self::assertSame(200, $draft->status());
        self::assertSame(404, $this->send('GET', '/v1/posts/test-post-denim-care-2')->status(), 'Drafts are not public');

        $scheduled = $this->send('PUT', "/v1/admin/posts/{$post['id']}", ['published_at' => '2099-01-01T09:00:00+05:00'] + $payload, $token)->decoded()['data'];
        self::assertSame('2099-01-01T04:00:00+00:00', $scheduled['published_at']);
        self::assertSame(404, $this->send('GET', '/v1/posts/test-post-denim-care')->status(), 'Scheduled posts are not public yet');

        self::assertSame(204, $this->send('DELETE', "/v1/admin/posts/{$post['id']}", null, $token)->status());
    }

    public function testInquiryFollowUp(): void
    {
        $this->send('POST', '/v1/inquiries', [
            'type' => 'quote', 'name' => 'Pipeline Buyer', 'email' => 'pipeline@example.test', 'country' => 'GB',
            'message' => 'Please quote 500 pcs of chinos.',
        ]);
        $id = (int) $this->db->fetchValue("SELECT id FROM inquiries WHERE email = 'pipeline@example.test'");
        $token = $this->login('sales');

        $open = $this->send('GET', '/v1/admin/inquiries', ['status' => 'open', 'q' => 'pipeline@example.test'], $token)->decoded();
        self::assertSame([$id], array_column($open['data'], 'id'));
        self::assertArrayHasKey('new', $open['meta']['counts']);

        $byReference = $this->send('GET', '/v1/admin/inquiries', ['q' => sprintf('INQ-%05d', $id)], $token)->decoded()['data'];
        self::assertSame([$id], array_column($byReference, 'id'));

        $updated = $this->send('PATCH', "/v1/admin/inquiries/{$id}", ['status' => 'quoted', 'notes' => 'Sent PI by email.'], $token)->decoded()['data'];
        self::assertSame('quoted', $updated['status']);
        self::assertSame('Sent PI by email.', $updated['notes']);
        self::assertSame('Sales Tester', $updated['handled_by']['name']);

        self::assertSame(422, $this->send('PATCH', "/v1/admin/inquiries/{$id}", ['status' => 'maybe'], $token)->status());
    }

    public function testSettingsKeepHiddenValuesAndDropEmptyNumbers(): void
    {
        $token = $this->login('admin');

        $saved = $this->send('PUT', '/v1/admin/settings/factory_facts', ['monthly_capacity' => '30,000+ pcs', 'machines' => ''], $token);
        self::assertSame(200, $saved->status());
        $facts = $saved->decoded()['data'];
        self::assertSame('30,000+ pcs', $facts['monthly_capacity']);
        self::assertArrayNotHasKey('machines', $facts);
        self::assertSame($this->savedFacts['confirmed'] ?? null, $facts['confirmed'] ?? null);

        $badContact = $this->send('PUT', '/v1/admin/settings/contact', ['phone' => 'call me', 'whatsapp' => '+92 310', 'email' => 'x'], $token);
        self::assertSame(['phone', 'whatsapp', 'email'], array_keys($badContact->decoded()['error']['fields']));

        self::assertSame(404, $this->send('PUT', '/v1/admin/settings/bank', [], $token)->status());
    }

    public function testChangingThePasswordEndsOtherSessions(): void
    {
        $first = $this->login('editor');
        $second = $this->login('editor');

        $wrong = $this->send('PUT', '/v1/admin/auth/password', ['current_password' => 'nope', 'password' => 'a much longer secret'], $first);
        self::assertSame(422, $wrong->status());

        $changed = $this->send('PUT', '/v1/admin/auth/password', ['current_password' => self::PASSWORD, 'password' => 'a much longer secret'], $first);
        self::assertSame(204, $changed->status());
        self::assertSame(200, $this->send('GET', '/v1/admin/auth/me', null, $first)->status(), 'The current session stays');
        self::assertSame(401, $this->send('GET', '/v1/admin/auth/me', null, $second)->status(), 'Other sessions end');
    }

    private function login(string $role): string
    {
        $response = $this->send('POST', '/v1/admin/auth/login', ['email' => self::ACCOUNTS[$role], 'password' => self::PASSWORD]);
        self::assertSame(200, $response->status(), $response->body());

        return $response->decoded()['data']['token'];
    }

    /**
     * @param array<string, mixed>|null $body JSON body; for GET requests, the query string
     */
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
