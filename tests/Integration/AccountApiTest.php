<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Customer;
use App\Models\CustomerToken;

/** Buyer accounts: signup, login, email confirmation, password reset, profile and addresses. */
final class AccountApiTest extends BuyerApiTestCase
{
    private const EMAIL = 'buyer-account@example.test';

    private const OTHER = 'buyer-other@example.test';

    private const NEW_EMAIL = 'buyer-renamed@example.test';

    protected function testIp(): string
    {
        return '192.0.2.81';
    }

    protected function testEmails(): array
    {
        return [self::EMAIL, self::OTHER, self::NEW_EMAIL];
    }

    public function testSignupCreatesAnUnverifiedAccountAndLogsIn(): void
    {
        $session = $this->signup(self::EMAIL, ['email' => '  Buyer-Account@Example.TEST ', 'website' => 'https://denim.example.test']);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session['token']);
        self::assertSame(self::EMAIL, $session['customer']['email'], 'Emails are stored in lower case');
        self::assertFalse($session['customer']['email_verified']);
        self::assertArrayNotHasKey('password_hash', $session['customer']);

        $me = $this->send('GET', '/v1/account', null, $session['token'])->decoded()['data'];
        self::assertSame('Test Denim Co', $me['company_name']);
        self::assertSame('https://denim.example.test', $me['website']);

        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM customers WHERE email = ?', [self::EMAIL]);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
    }

    public function testSignupValidation(): void
    {
        $missing = $this->send('POST', '/v1/account/signup', ['email' => 'not-an-email', 'password' => 'short', 'accept_terms' => false]);
        self::assertSame(422, $missing->status());
        self::assertEqualsCanonicalizing(
            ['email', 'password', 'company_name', 'contact_name', 'business_type', 'country_code', 'phone'],
            array_keys($missing->decoded()['error']['fields']),
        );

        $terms = $this->send('POST', '/v1/account/signup', [
            'email' => self::EMAIL, 'password' => self::PASSWORD, 'company_name' => 'Test Denim Co', 'contact_name' => 'Tess Tester',
            'business_type' => 'brand', 'country_code' => 'GB', 'phone' => '+44 20 7946 0000', 'accept_terms' => false,
        ]);
        self::assertSame(['accept_terms'], array_keys($terms->decoded()['error']['fields']));

        $this->signup(self::EMAIL);
        $duplicate = $this->send('POST', '/v1/account/signup', [
            'email' => strtoupper(self::EMAIL), 'password' => self::PASSWORD, 'company_name' => 'Again', 'contact_name' => 'Tess Tester',
            'business_type' => 'brand', 'country_code' => 'GB', 'phone' => '+44 20 7946 0000', 'accept_terms' => true,
        ]);
        self::assertSame(422, $duplicate->status());
        self::assertStringContainsString('already exists', $duplicate->decoded()['error']['fields']['email']);

        $bot = $this->send('POST', '/v1/account/signup', ['fax_number' => '123', 'email' => self::OTHER]);
        self::assertSame(422, $bot->status(), 'The honeypot field rejects bots');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM customers WHERE email = ?', [self::OTHER]));
    }

    public function testEmailConfirmationLink(): void
    {
        $session = $this->signup(self::EMAIL);
        $token = $this->lastMailedToken(self::EMAIL, '/verify-email');

        $verified = $this->send('POST', '/v1/account/verify-email', ['token' => $token]);
        self::assertSame(200, $verified->status());
        self::assertTrue($this->send('GET', '/v1/account', null, $session['token'])->decoded()['data']['email_verified']);

        // Opening the same link again (or after an email scanner) still says "confirmed".
        self::assertSame(200, $this->send('POST', '/v1/account/verify-email', ['token' => $token])->status());
        self::assertSame(400, $this->send('POST', '/v1/account/verify-email', ['token' => str_repeat('a', 64)])->status());

        $again = $this->send('POST', '/v1/account/verify-email/resend', null, $session['token']);
        self::assertSame(409, $again->status());
        self::assertSame('already_verified', $again->decoded()['error']['code']);
    }

    public function testExpiredOrReplacedLinksDoNotWork(): void
    {
        $session = $this->signup(self::EMAIL);
        $first = $this->lastMailedToken(self::EMAIL, '/verify-email');
        self::assertSame(204, $this->send('POST', '/v1/account/verify-email/resend', null, $session['token'])->status());
        $second = $this->lastMailedToken(self::EMAIL, '/verify-email');
        self::assertNotSame($first, $second);
        self::assertSame(400, $this->send('POST', '/v1/account/verify-email', ['token' => $first])->status(), 'A new link replaces the old one');

        $this->db->execute('UPDATE customer_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = ?', [hash('sha256', $second)]);
        self::assertSame(400, $this->send('POST', '/v1/account/verify-email', ['token' => $second])->status());
    }

    public function testLoginAndLogout(): void
    {
        $this->signup(self::EMAIL);

        $wrong = $this->send('POST', '/v1/account/login', ['email' => self::EMAIL, 'password' => 'wrong password']);
        self::assertSame(401, $wrong->status());
        self::assertSame('invalid_credentials', $wrong->decoded()['error']['code']);
        self::assertSame(401, $this->send('POST', '/v1/account/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD])->status());

        $login = $this->send('POST', '/v1/account/login', ['email' => strtoupper(self::EMAIL), 'password' => self::PASSWORD]);
        self::assertSame(200, $login->status());
        $token = $login->decoded()['data']['token'];

        self::assertSame(204, $this->send('POST', '/v1/account/logout', null, $token)->status());
        $after = $this->send('GET', '/v1/account', null, $token);
        self::assertSame(401, $after->status());
        self::assertSame('session_expired', $after->decoded()['error']['code']);
        self::assertSame(401, $this->send('GET', '/v1/account')->status());
    }

    public function testBlockedBuyersAreLockedOut(): void
    {
        $session = $this->signup(self::EMAIL);
        $this->db->execute("UPDATE customers SET status = 'blocked' WHERE email = ?", [self::EMAIL]);

        self::assertSame(401, $this->send('GET', '/v1/account', null, $session['token'])->status());
        $login = $this->send('POST', '/v1/account/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertSame(403, $login->status());
        self::assertSame('account_blocked', $login->decoded()['error']['code']);
    }

    public function testPasswordReset(): void
    {
        $session = $this->signup(self::EMAIL);

        self::assertSame(204, $this->send('POST', '/v1/account/forgot-password', ['email' => self::EMAIL])->status());
        self::assertSame(204, $this->send('POST', '/v1/account/forgot-password', ['email' => 'nobody@example.test'])->status(), 'Same answer for unknown emails');
        $token = $this->lastMailedToken(self::EMAIL, '/reset-password');

        $tooShort = $this->send('POST', '/v1/account/reset-password', ['token' => $token, 'password' => 'short']);
        self::assertSame(422, $tooShort->status());

        $reset = $this->send('POST', '/v1/account/reset-password', ['token' => $token, 'password' => 'a brand new password']);
        self::assertSame(200, $reset->status());
        $newToken = $reset->decoded()['data']['token'];
        self::assertTrue($reset->decoded()['data']['customer']['email_verified'], 'The reset link proves the email address');

        self::assertSame(401, $this->send('GET', '/v1/account', null, $session['token'])->status(), 'Other sessions end');
        self::assertSame(200, $this->send('GET', '/v1/account', null, $newToken)->status());
        self::assertSame(400, $this->send('POST', '/v1/account/reset-password', ['token' => $token, 'password' => 'another new password'])->status(), 'Links work once');
        self::assertSame(200, $this->send('POST', '/v1/account/login', ['email' => self::EMAIL, 'password' => 'a brand new password'])->status());
    }

    public function testProfileAndEmailChange(): void
    {
        $session = $this->signup(self::EMAIL);
        $this->verify(self::EMAIL);
        $this->signup(self::OTHER);
        $profile = [
            'email' => self::EMAIL, 'company_name' => 'Renamed Denim Ltd', 'contact_name' => 'Tess Tester',
            'business_type' => 'wholesaler', 'country_code' => 'de', 'phone' => '+49 30 123456',
        ];

        $saved = $this->send('PUT', '/v1/account', $profile, $session['token']);
        self::assertSame(200, $saved->status());
        self::assertSame('Renamed Denim Ltd', $saved->decoded()['data']['company_name']);
        self::assertSame('DE', $saved->decoded()['data']['country_code']);
        self::assertTrue($saved->decoded()['data']['email_verified']);

        $noPassword = $this->send('PUT', '/v1/account', ['email' => self::NEW_EMAIL] + $profile, $session['token']);
        self::assertSame(['current_password'], array_keys($noPassword->decoded()['error']['fields']));
        $taken = $this->send('PUT', '/v1/account', ['email' => self::OTHER, 'current_password' => self::PASSWORD] + $profile, $session['token']);
        self::assertSame(['email'], array_keys($taken->decoded()['error']['fields']));

        $changed = $this->send('PUT', '/v1/account', ['email' => self::NEW_EMAIL, 'current_password' => self::PASSWORD] + $profile, $session['token']);
        self::assertSame(self::NEW_EMAIL, $changed->decoded()['data']['email']);
        self::assertFalse($changed->decoded()['data']['email_verified'], 'A new email address must be confirmed again');
        $this->verify(self::NEW_EMAIL);
    }

    public function testChangingThePasswordEndsOtherSessions(): void
    {
        $first = $this->signup(self::EMAIL)['token'];
        $second = $this->send('POST', '/v1/account/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])->decoded()['data']['token'];

        $wrong = $this->send('PUT', '/v1/account/password', ['current_password' => 'nope', 'password' => 'another long one'], $first);
        self::assertSame(['current_password'], array_keys($wrong->decoded()['error']['fields']));

        self::assertSame(204, $this->send('PUT', '/v1/account/password', ['current_password' => self::PASSWORD, 'password' => 'another long one'], $first)->status());
        self::assertSame(200, $this->send('GET', '/v1/account', null, $first)->status());
        self::assertSame(401, $this->send('GET', '/v1/account', null, $second)->status());
    }

    public function testAddressBook(): void
    {
        $token = $this->signup(self::EMAIL)['token'];
        $other = $this->signup(self::OTHER)['token'];
        $address = [
            'company' => 'Test Denim Co', 'contact_name' => 'Warehouse Team', 'phone' => '+44 20 7946 0001',
            'line1' => '1 Test Street', 'city' => 'London', 'postal_code' => 'E1 6AN', 'country_code' => 'gb', 'tax_id' => 'GB123456789000',
        ];

        $first = $this->send('POST', '/v1/account/addresses', $address, $token);
        self::assertSame(201, $first->status());
        self::assertTrue($first->decoded()['data']['is_default'], 'The first address is the default');
        self::assertSame('GB', $first->decoded()['data']['country_code']);

        $second = $this->send('POST', '/v1/account/addresses', ['line1' => '2 Dock Road', 'is_default' => true] + $address, $token)->decoded()['data'];
        $list = $this->send('GET', '/v1/account/addresses', null, $token)->decoded()['data'];
        self::assertSame([$second['id'], $first->decoded()['data']['id']], array_column($list, 'id'), 'The default comes first');

        self::assertSame(404, $this->send('PUT', "/v1/account/addresses/{$second['id']}", $address, $other)->status(), "Another buyer's address is out of reach");
        self::assertSame(404, $this->send('DELETE', "/v1/account/addresses/{$second['id']}", null, $other)->status());

        self::assertSame(204, $this->send('DELETE', "/v1/account/addresses/{$second['id']}", null, $token)->status());
        $remaining = $this->send('GET', '/v1/account/addresses', null, $token)->decoded()['data'];
        self::assertCount(1, $remaining);
        self::assertTrue($remaining[0]['is_default'], 'Another address becomes the default');

        self::assertSame(422, $this->send('POST', '/v1/account/addresses', ['country_code' => 'United Kingdom'] + $address, $token)->status());
    }

    public function testTokensAreStoredHashed(): void
    {
        $session = $this->signup(self::EMAIL);
        $customerId = (int) $this->db->fetchValue('SELECT id FROM customers WHERE email = ?', [self::EMAIL]);

        self::assertNotNull($this->db->fetchValue("SELECT id FROM auth_tokens WHERE user_type = 'customer' AND token_hash = ?", [hash('sha256', $session['token'])]));
        $link = $this->lastMailedToken(self::EMAIL, '/verify-email');
        self::assertNotNull($this->db->fetchValue('SELECT id FROM customer_tokens WHERE customer_id = ? AND purpose = ? AND token_hash = ?', [$customerId, CustomerToken::VERIFY_EMAIL, hash('sha256', $link)]));
        self::assertSame(['active', 'blocked'], Customer::STATUSES);
    }
}
