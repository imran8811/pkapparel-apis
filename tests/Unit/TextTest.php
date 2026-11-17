<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Text;
use PHPUnit\Framework\TestCase;

/** Web addresses made from names. */
final class TextTest extends TestCase
{
    public function testAStyleAddressEndsWithItsStyleNo(): void
    {
        self::assertSame('men-s-sky-blue-stretch-jeans-1051', Text::styleSlug("Men's sky blue stretch jeans", '1051'));
        self::assertSame('mens-sky-blue-stretch-jeans-pk-1051', Text::styleSlug('Mens sky blue stretch jeans', 'PK-1051'));
        self::assertSame('men-s-slim-jeans', Text::styleSlug("Men's slim jeans", null), 'Without a style no.');
        self::assertSame('men-s-slim-jeans', Text::styleSlug("Men's slim jeans", '  '));
        self::assertSame('', Text::styleSlug('', '1051'), 'Not without a name');
    }

    public function testALongNameMakesRoomForTheStyleNo(): void
    {
        $slug = Text::styleSlug(str_repeat('jeans ', 50), '1051');

        self::assertLessThanOrEqual(190, strlen($slug));
        self::assertStringEndsWith('-1051', $slug);
    }
}
