<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

/** End-to-end checks of the public endpoints through App::handle (real routes, real database). */
final class PublicApiTest extends IntegrationTestCase
{
    private const TEST_IP = '192.0.2.77'; // TEST-NET address, never a real visitor

    protected function tearDown(): void
    {
        $this->db->execute("DELETE FROM inquiries WHERE email LIKE '%@example.test'");
        (new RateLimiter($this->db))->clear('inquiry:' . self::TEST_IP);
    }

    public function testContentEndpointsReturnSeededData(): void
    {
        $faqs = $this->get('/v1/faqs')->decoded()['data'];
        self::assertNotEmpty($faqs);
        self::assertArrayHasKey('question', $faqs[0]);

        $shipping = $this->get('/v1/faqs', ['category' => 'shipping'])->decoded()['data'];
        self::assertSame(['shipping'], array_values(array_unique(array_column($shipping, 'category'))));

        $factory = $this->get('/v1/factory')->decoded()['data'];
        self::assertSame('fabric-store', $factory['departments'][0]['slug']);
        self::assertNotEmpty($factory['departments'][0]['images']);

        $testimonials = $this->get('/v1/testimonials')->decoded()['data'];
        self::assertNotContains('Wholesaler', array_column($testimonials, 'author_role'), 'Unpublished review must stay hidden');
    }

    public function testBlogListAndArticle(): void
    {
        $list = $this->get('/v1/posts', ['per_page' => '5'])->decoded();
        self::assertCount(5, $list['data']);
        self::assertSame(12, $list['meta']['pagination']['total']);

        $post = $this->get('/v1/posts/jeans-manufacturing-cost')->decoded()['data'];
        self::assertStringContainsString('id="cost-table"', $post['body_html']);

        self::assertSame(404, $this->get('/v1/posts/does-not-exist')->status());
        self::assertSame(404, $this->get('/v1/posts/Bad_Slug')->status());
    }

    public function testLegalPage(): void
    {
        $page = $this->get('/v1/pages/terms-of-sale')->decoded()['data'];
        self::assertSame('Terms of sale', $page['title']);
    }

    public function testInquiryIsStoredAndValidated(): void
    {
        $created = $this->post('/v1/inquiries', [
            'type' => 'quote',
            'name' => 'Test Buyer',
            'company' => 'Example Ltd',
            'email' => 'buyer@example.test',
            'country' => 'de',
            'message' => 'We need 600 pcs of men’s jeans in 3 colors.',
            'details' => ['quantity' => '600', 'product' => 'Men jeans'],
        ]);
        self::assertSame(201, $created->status());
        $reference = $created->decoded()['data']['reference'];
        self::assertMatchesRegularExpression('/^INQ-\d{5}$/', $reference);

        $row = $this->db->fetch("SELECT * FROM inquiries WHERE email = 'buyer@example.test'");
        self::assertSame('DE', $row['country_code']);
        self::assertSame(self::TEST_IP, $row['ip_address']);
        self::assertSame(['quantity' => '600', 'product' => 'Men jeans'], json_decode($row['details'], true));

        $invalid = $this->post('/v1/inquiries', ['type' => 'contact', 'email' => 'nope', 'message' => 'short']);
        self::assertSame(422, $invalid->status());
        self::assertSame(['name', 'email', 'message'], array_keys($invalid->decoded()['error']['fields']));
    }

    public function testHoneypotPretendsSuccessWithoutStoring(): void
    {
        $response = $this->post('/v1/inquiries', [
            'type' => 'contact', 'name' => 'Bot', 'email' => 'bot@example.test',
            'message' => 'Buy cheap stuff now please', 'website' => 'http://spam.test',
        ]);

        self::assertSame(201, $response->status());
        self::assertNull($this->db->fetch("SELECT id FROM inquiries WHERE email = 'bot@example.test'"));
    }

    public function testInquiriesAreRateLimitedPerVisitor(): void
    {
        $payload = ['type' => 'contact', 'name' => 'Busy', 'email' => 'busy@example.test', 'message' => 'Hello, this is a test message.'];
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(201, $this->post('/v1/inquiries', $payload)->status());
        }

        $blocked = $this->post('/v1/inquiries', $payload);
        self::assertSame(429, $blocked->status());
        self::assertArrayHasKey('Retry-After', $blocked->headers());
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request('GET', $path, $query, $this->headers()));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): Response
    {
        $headers = $this->headers() + ['content-type' => 'application/json'];

        return $this->app->handle(new Request('POST', $path, [], $headers, (string) json_encode($body)));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['x-client-key' => $this->app->config()->string('client_key'), 'x-client-ip' => self::TEST_IP];
    }
}
