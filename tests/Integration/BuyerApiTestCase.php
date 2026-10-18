<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

/**
 * Helpers for buyer-side API tests: requests as a guest or buyer, signup, and reading links
 * from the mail log (MAIL_DRIVER=log). Test accounts use @example.test emails and are removed afterwards.
 */
abstract class BuyerApiTestCase extends IntegrationTestCase
{
    protected const PASSWORD = 'denim-lover-2026';

    /** Each test class uses its own TEST-NET address, so rate limits do not leak between classes. */
    abstract protected function testIp(): string;

    /** @return list<string> emails of the accounts this test creates */
    abstract protected function testEmails(): array;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUpBuyers();
    }

    protected function tearDown(): void
    {
        $this->cleanUpBuyers();
    }

    protected function cleanUpBuyers(): void
    {
        $emails = $this->testEmails();
        $in = implode(', ', array_fill(0, count($emails), '?'));
        $ids = array_map('intval', array_column($this->db->fetchAll("SELECT id FROM customers WHERE email IN ({$in})", $emails), 'id'));
        if ($ids !== []) {
            $idList = implode(', ', $ids);
            $storage = rtrim($this->app->config()->string('paths.storage'), '/\\') . '/private/';
            // Private files of the orders: payment slips, staff documents and production photos (with previews).
            $files = $this->db->fetchAll(
                "SELECT p.slip_path AS path FROM order_payments p JOIN orders o ON o.id = p.order_id WHERE o.customer_id IN ({$idList}) AND p.slip_path IS NOT NULL
                 UNION ALL SELECT d.path FROM order_documents d JOIN orders o ON o.id = d.order_id WHERE o.customer_id IN ({$idList})
                 UNION ALL SELECT f.path FROM order_photos f JOIN orders o ON o.id = f.order_id WHERE o.customer_id IN ({$idList})
                 UNION ALL SELECT f.preview_path FROM order_photos f JOIN orders o ON o.id = f.order_id WHERE o.customer_id IN ({$idList})",
            );
            foreach ($files as $row) {
                @unlink($storage . $row['path']);
            }
            $this->db->execute("DELETE FROM orders WHERE customer_id IN ({$idList})");
            $this->db->execute("DELETE FROM auth_tokens WHERE user_type = 'customer' AND user_id IN ({$idList})");
            $this->db->execute("DELETE FROM customers WHERE id IN ({$idList})");
        }

        $limiter = new RateLimiter($this->db);
        foreach (['signup:', 'customer-login-ip:', 'forgot-ip:', 'reset-ip:'] as $bucket) {
            $limiter->clear($bucket . $this->testIp());
        }
        foreach ($emails as $email) {
            $limiter->clear('customer-login:' . $email);
            $limiter->clear('forgot:' . $email);
        }
    }

    /** @param array<string, mixed>|null $body */
    protected function send(string $method, string $path, ?array $body = null, ?string $token = null, ?string $cartToken = null): Response
    {
        $headers = ['x-client-key' => $this->app->config()->string('client_key'), 'x-client-ip' => $this->testIp()];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        if ($cartToken !== null) {
            $headers['x-cart-token'] = $cartToken;
        }
        if ($method === 'GET') {
            return $this->app->handle(new Request('GET', $path, $body ?? [], $headers));
        }
        $headers['content-type'] = 'application/json';

        return $this->app->handle(new Request($method, $path, [], $headers, $body === null ? '' : (string) json_encode($body)));
    }

    /**
     * A multipart/form-data request, like the website's file uploads.
     *
     * @param array<string, mixed> $fields
     * @param array<string, array<string, mixed>> $files
     */
    protected function sendForm(string $path, array $fields, array $files, ?string $token): Response
    {
        $headers = [
            'x-client-key' => $this->app->config()->string('client_key'),
            'x-client-ip' => $this->testIp(),
            'content-type' => 'multipart/form-data; boundary=test',
        ];
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }

        return $this->app->handle(new Request('POST', $path, [], $headers, '', $fields, $files));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{token: string, customer: array<string, mixed>}
     */
    protected function signup(string $email, array $overrides = []): array
    {
        $response = $this->send('POST', '/v1/account/signup', $overrides + [
            'email' => $email,
            'password' => self::PASSWORD,
            'company_name' => 'Test Denim Co',
            'contact_name' => 'Tess Tester',
            'business_type' => 'brand',
            'country_code' => 'GB',
            'phone' => '+44 20 7946 0000',
            'accept_terms' => true,
        ]);
        self::assertSame(201, $response->status(), (string) json_encode($response->decoded()));

        return $response->decoded()['data'];
    }

    /** The token of the last link of this kind emailed to this address (from the mail log). */
    protected function lastMailedToken(string $email, string $path): string
    {
        $log = rtrim($this->app->config()->string('paths.storage'), '/\\') . '/logs/mail-' . gmdate('Y-m-d') . '.log';
        $entries = explode('==== ', (string) @file_get_contents($log));
        foreach (array_reverse($entries) as $entry) {
            if (str_contains($entry, "To: {$email}\n") && preg_match('#' . preg_quote($path, '#') . '\?token=([a-f0-9]{64})#', $entry, $match) === 1) {
                return $match[1];
            }
        }
        self::fail("No {$path} link was emailed to {$email}.");
    }

    /** Confirms the email address with the link from the mail log. */
    protected function verify(string $email): void
    {
        $response = $this->send('POST', '/v1/account/verify-email', ['token' => $this->lastMailedToken($email, '/verify-email')]);
        self::assertSame(200, $response->status(), (string) json_encode($response->decoded()));
    }
}
