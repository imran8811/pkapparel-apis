<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyRuledFieldsTrimmedAndCast(): void
    {
        $data = Validator::make(
            ['email' => '  buyer@example.com ', 'quantity' => '120', 'agree' => 'yes', 'country' => 'de', 'extra' => 'ignored'],
            ['email' => 'required|email', 'quantity' => 'required|int|min:100', 'agree' => 'bool', 'country' => 'required|country'],
        )->validate();

        self::assertSame(['email' => 'buyer@example.com', 'quantity' => 120, 'agree' => true, 'country' => 'DE'], $data);
    }

    public function testCollectsOneMessagePerField(): void
    {
        $validator = Validator::make(
            ['email' => 'not-an-email', 'quantity' => '50', 'mode' => 'truck'],
            ['email' => 'required|email', 'quantity' => 'required|int|min:100', 'mode' => 'required|in:air,sea', 'company' => 'required|string'],
        );

        self::assertFalse($validator->passes());
        self::assertSame([
            'email' => 'Enter a valid email address.',
            'quantity' => 'Must be at least 100.',
            'mode' => 'Choose one of: air, sea.',
            'company' => 'This field is required.',
        ], $validator->errors());
    }

    public function testThrowsValidationExceptionWith422(): void
    {
        try {
            Validator::make([], ['name' => 'required'])->validate();
            self::fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(['name' => 'This field is required.'], $e->fields);
        }
    }

    public function testOptionalFieldsAreSkippedWhenMissingAndNullWhenEmpty(): void
    {
        $data = Validator::make(['website' => ''], ['website' => 'nullable|url', 'notes' => 'nullable|string|max:500'])->validate();

        self::assertSame(['website' => null], $data);
    }

    public function testStringLengthUsesCharactersNotBytes(): void
    {
        self::assertTrue(Validator::make(['name' => 'Ünal Öz'], ['name' => 'string|max:7'])->passes());
        self::assertFalse(Validator::make(['name' => 'Ünal Özz'], ['name' => 'string|max:7'])->passes());
    }

    public function testUrlAndPhoneAndRegexRules(): void
    {
        self::assertFalse(Validator::make(['u' => 'ftp://x.com'], ['u' => 'url'])->passes());
        self::assertTrue(Validator::make(['u' => 'https://pkapparel.com'], ['u' => 'url'])->passes());
        self::assertTrue(Validator::make(['p' => '+92 310 4489288'], ['p' => 'phone'])->passes());
        self::assertFalse(Validator::make(['p' => 'call me'], ['p' => 'phone'])->passes());
        self::assertTrue(Validator::make(['s' => 'PK-1058'], ['s' => ['regex:/^PK-\d{4}$/']])->passes());
    }

    public function testIntRejectsFloatsAndBooleans(): void
    {
        self::assertFalse(Validator::make(['q' => '10.5'], ['q' => 'int'])->passes());
        self::assertFalse(Validator::make(['q' => true], ['q' => 'int'])->passes());
    }

    public function testUnknownRuleIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);
        Validator::make(['a' => 'b'], ['a' => 'shiny'])->passes();
    }
}
