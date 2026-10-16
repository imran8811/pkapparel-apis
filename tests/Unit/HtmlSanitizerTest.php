<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testRemovesScriptsStylesAndFramesWithTheirContent(): void
    {
        $html = '<p>Hi</p><script>alert(1)</script><style>p{}</style><iframe src="https://evil.test"></iframe>';

        self::assertSame('<p>Hi</p>', $this->sanitizer->sanitize($html));
    }

    public function testStripsEventHandlersClassesAndStyles(): void
    {
        $html = '<p class="lead" style="color:red" onclick="steal()">Text <strong onmouseover="x()">bold</strong></p>';

        self::assertSame('<p>Text <strong>bold</strong></p>', $this->sanitizer->sanitize($html));
    }

    public function testUnsafeLinksLoseTheirHrefButKeepTheirText(): void
    {
        self::assertSame('<p>click</p>', $this->sanitizer->sanitize('<p><a href="javascript:alert(1)">click</a></p>'));
        self::assertSame('<p>click</p>', $this->sanitizer->sanitize('<p><a href=" JaVa&#10;script:alert(1)">click</a></p>'));
    }

    public function testKeepsSafeLinksAndProtectsNewTabs(): void
    {
        $html = '<p><a href="https://pkapparel.com" target="_blank">site</a> <a href="mailto:info@pkapparel.com">mail</a> <a href="/factory" target="_self">factory</a></p>';

        self::assertSame(
            '<p><a href="https://pkapparel.com" target="_blank" rel="noopener noreferrer">site</a> <a href="mailto:info@pkapparel.com">mail</a> <a href="/factory">factory</a></p>',
            $this->sanitizer->sanitize($html),
        );
    }

    public function testImagesNeedASafeSourceAndAlwaysGetAlt(): void
    {
        self::assertSame('<p><img src="/uploads/a.jpg" alt=""></p>', $this->sanitizer->sanitize('<p><img src="/uploads/a.jpg" onerror="x()"></p>'));
        self::assertSame('', $this->sanitizer->sanitize('<p><img src="data:image/svg+xml;base64,PHN2Zz4="></p>'));
    }

    public function testUnwrapsUnknownElementsAndRenamesH1(): void
    {
        $html = '<div class="post-section"><h1 id="intro">Title</h1><span>Some <em>text</em></span></div>';

        self::assertSame('<h2 id="intro">Title</h2><p>Some <em>text</em></p>', $this->sanitizer->sanitize($html));
    }

    public function testInvalidIdsAreRemoved(): void
    {
        self::assertSame('<h2>A</h2>', $this->sanitizer->sanitize('<h2 id="1 bad&quot;id">A</h2>'));
    }

    public function testRemovesEmptyParagraphsAndComments(): void
    {
        self::assertSame('<p>Kept</p>', $this->sanitizer->sanitize("<p> </p><!-- note --><p>Kept</p><p>&nbsp;</p>"));
    }

    public function testRewriteHookCanChangeOrDropUrls(): void
    {
        $result = $this->sanitizer->sanitize(
            '<p><a href="/men/jeans-pant">jeans</a> <a href="#">dead</a></p>',
            static fn (string $url): ?string => match ($url) {
                '/men/jeans-pant' => '/wholesale-shop/men/jeans-pant',
                '#' => null,
                default => $url,
            },
        );

        self::assertSame('<p><a href="/wholesale-shop/men/jeans-pant">jeans</a> dead</p>', $result);
    }

    public function testKeepsUtf8Text(): void
    {
        self::assertSame('<p>Ünal – 50% · 100 pcs</p>', $this->sanitizer->sanitize('<p>Ünal – 50% · 100 pcs</p>'));
    }

    public function testKeepsTablesWithSpans(): void
    {
        $html = '<table class="x"><tr><th colspan="2" style="w">A</th></tr><tr><td rowspan="abc">B</td></tr></table>';

        self::assertSame('<table><thead><tr><th colspan="2">A</th></tr></thead><tr><td>B</td></tr></table>', $this->sanitizer->sanitize($html));
    }

    public function testLayoutBoxesBecomeParagraphsSoLinesDoNotRunTogether(): void
    {
        $html = '<div class="faq-item"><div class="q"><i class="icon"></i> Question?</div><div class="a">Answer <strong>here</strong>.</div></div>'
            . '<div><br></div><div class="stat"><strong>1.30 m</strong><small>Men</small></div>';

        self::assertSame(
            '<p> Question?</p><p>Answer <strong>here</strong>.</p><p><strong>1.30 m</strong><small>Men</small></p>',
            $this->sanitizer->sanitize($html),
        );
    }

    public function testLooseTextGetsParagraphs(): void
    {
        self::assertSame(
            '<p>Hello <strong>world</strong></p><h2>Next</h2><p>tail</p><blockquote><p>Quote</p></blockquote>',
            $this->sanitizer->sanitize('Hello <strong>world</strong><h2>Next</h2>tail<br><blockquote>Quote</blockquote>'),
        );
    }

    public function testNormalisesRichTextEditorOutput(): void
    {
        $html = '<table style="min-width: 50px"><colgroup><col style="min-width: 25px"><col></colgroup><tbody>'
            . '<tr><th colspan="1" rowspan="1"><p>Name</p></th><th colspan="1" rowspan="1"><p>MOQ</p></th></tr>'
            . '<tr><td colspan="1" rowspan="1"><p>PK Apparel</p></td><td colspan="2" rowspan="1"><p></p></td></tr>'
            . '</tbody></table><ul><li><p>One</p></li><li><p>Two</p><ul><li><p>Nested</p></li></ul></li><li><p>A</p><p>B</p></li></ul>';

        self::assertSame(
            '<table><thead><tr><th>Name</th><th>MOQ</th></tr></thead><tbody><tr><td>PK Apparel</td><td colspan="2"></td></tr></tbody></table>'
            . '<ul><li>One</li><li>Two<ul><li>Nested</li></ul></li><li><p>A</p><p>B</p></li></ul>',
            $this->sanitizer->sanitize($html),
        );
    }
}
