<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Text;
use PHPUnit\Framework\TestCase;

/** Web addresses made from names, and following a new name. */
final class TextTest extends TestCase
{
    public function testAnAddressMadeFromTheNameFollowsANewName(): void
    {
        self::assertSame('men-s-skinny-jeans', Text::renamedSlug('men-s-slim-jeans', "Men's slim jeans", "Men's skinny jeans"));
        self::assertSame('men-s-skinny-jeans', Text::renamedSlug('men-s-slim-jeans-2', "Men's slim jeans", "Men's skinny jeans"), 'The number told it from another style: dropped');
        self::assertSame('men-s-slim-jeans', Text::renamedSlug('men-s-slim-jeans-copy', "Men's slim jeans (copy)", "Men's slim jeans"), 'A duplicate renamed');
        self::assertSame('men-s-skinny-jeans-sky-blue', Text::renamedSlug('men-s-slim-jeans-sky-blue', "Men's slim jeans", "Men's skinny jeans", '-sky-blue'), 'A stock listing keeps its color');
        self::assertSame('men-s-skinny-jeans-sky-blue', Text::renamedSlug('men-s-slim-jeans-sky-blue-12', "Men's slim jeans", "Men's skinny jeans", '-sky-blue'));
    }

    public function testATypedAddressStays(): void
    {
        self::assertNull(Text::renamedSlug('pk-1051-slim', "Men's slim jeans", "Men's skinny jeans"), 'Not made from the name');
        self::assertNull(Text::renamedSlug('men-s-slim-jeans-black', "Men's slim jeans", "Men's skinny jeans"), 'Not its color');
        self::assertNull(Text::renamedSlug('men-s-slim-jeans-2', "Men's slim jeans", "MEN'S SLIM JEANS"), 'The same address: nothing changes');
        self::assertNull(Text::renamedSlug('jeans', 'Jeans', '!!!'), 'A name without letters or digits');
    }

    public function testAFreeAddressIsNumbered(): void
    {
        $taken = ['slim-jeans' => true, 'slim-jeans-2' => true];
        self::assertSame('slim-jeans-3', Text::freeSlug('slim-jeans', static fn (string $slug): bool => isset($taken[$slug])));
        self::assertSame('wide-jeans', Text::freeSlug('wide-jeans', static fn (string $slug): bool => isset($taken[$slug])));
    }
}
