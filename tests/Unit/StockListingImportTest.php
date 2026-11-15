<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csv;
use App\Services\StockListingImport;
use PHPUnit\Framework\TestCase;

/** Reading new stock listings from a CSV file, line by line (the database checks are in the integration tests). */
final class StockListingImportTest extends TestCase
{
    public function testTheTemplateHasEveryColumnAndReadsBack(): void
    {
        $rows = Csv::read(StockListingImport::template());

        self::assertCount(1, $rows);
        self::assertSame(['Name', 'Style no.', 'For', 'Color', 'Color code', 'Swatch', 'Set', 'Price per piece (Rs)', 'Sets in stock'], array_slice($rows[0], 0, 9));
        self::assertContains('Inseam', $rows[0], 'Specs are named by their label');
        self::assertSame(['Label', 'Description'], array_slice($rows[0], -2));
        self::assertSame([], StockListingImport::header($rows[0])['missing']);
    }

    public function testExcelFilesSavedWithSemicolonsAreRead(): void
    {
        self::assertSame([['Name', 'For'], ['Slim jeans', 'Men']], Csv::read("\xEF\xBB\xBFName;For\r\nSlim jeans;Men\r\n"));
    }

    public function testALineBecomesAListing(): void
    {
        [$listing, $problems] = $this->line([
            'Name' => " Men's slim stretch jeans ", 'Style no.' => 'PK-1051', 'For' => 'men', 'Color' => 'Sky blue', 'Color code' => 'ST-1051',
            'Swatch' => '4A6FA5', 'Set' => '32x2, 28 x 1; 30×2 34:1', 'Price per piece (Rs)' => 'Rs 1,200', 'Sets in stock' => '15',
            'Fit' => 'Slim', 'Stretch' => 'Rigid', 'Label' => 'Our own label', 'Description' => "Five pockets.\n\nSoft & light.",
        ]);

        self::assertSame([], $problems);
        self::assertSame("Men's slim stretch jeans", $listing['name']);
        self::assertSame('men-s-slim-stretch-jeans-sky-blue', $listing['slug'], 'Name and color, as for copies of the wholesale shop');
        self::assertSame('men', $listing['segment']);
        self::assertSame([['size' => '28', 'pcs' => 1], ['size' => '30', 'pcs' => 2], ['size' => '32', 'pcs' => 2], ['size' => '34', 'pcs' => 1]], $listing['set_sizes']);
        self::assertSame(120000, $listing['piece_price_cents']);
        self::assertSame(15, $listing['sets_in_stock']);
        self::assertSame(['fit' => 'Slim', 'stretch' => 'non-stretch', 'label' => 'Our own label'], $listing['specs']);
        self::assertSame(['code' => 'ST-1051', 'name' => 'Sky blue', 'slug' => 'sky-blue', 'hex' => '#4a6fa5'], $listing['color']);
        self::assertSame('<p>Five pockets.</p><p>Soft &amp; light.</p>', $listing['description_html']);
    }

    public function testEveryProblemOfALineIsListed(): void
    {
        [$listing, $problems] = $this->line([
            'Name' => 'Ab', 'For' => 'Women', 'Color' => 'Black', 'Color code' => 'ST 1', 'Swatch' => 'blue',
            'Set' => '28-2', 'Price per piece (Rs)' => '0', 'Sets in stock' => 'ten', 'Stretch' => 'some', 'Fit' => str_repeat('x', 61),
        ]);

        self::assertNull($listing);
        self::assertSame([
            'The name needs 3 to 200 characters.',
            '“For” is Men or Boys, not “Women”.',
            'The color code “ST 1” can only have letters, digits and dashes (at most 40).',
            'The swatch “blue” is not a color like #4a6fa5.',
            'Set “28-2”: write each waist size x its pieces, e.g. 28x2, 30x2, 32x2.',
            'The price per piece “0” is not an amount from Rs 1 to Rs 50,000.',
            '“ten” is not a whole number of sets in stock.',
            'Stretch is “Stretch” or “Rigid”, not “some”.',
            'Fit: Use at most 60 characters.',
        ], $problems);
    }

    public function testSetsFollowTheStockRules(): void
    {
        $problem = fn (string $set): string => $this->line(['Name' => 'Slim jeans', 'For' => 'Boys', 'Color' => 'Black', 'Color code' => 'B-1', 'Set' => $set, 'Price per piece (Rs)' => '500'])[1][0] ?? '';

        self::assertSame('', $problem('16x1, 18x1'), 'Boys\' sizes start at 16');
        self::assertSame('Set “50x1”: Sizes are waist sizes from 16 to 48.', $problem('50x1'));
        self::assertSame('Set “30x25”: Each size needs 1 to 24 pieces per set.', $problem('30x25'));
        self::assertSame('Set “30x1, 30x2”: Each size can only be listed once.', $problem('30x1, 30x2'));
        self::assertSame('The set is missing, e.g. 28x2, 30x2, 32x2.', $problem(''));
        self::assertSame('Set “28, 30”: write each waist size x its pieces, e.g. 28x2, 30x2, 32x2.', $problem('28, 30'));
    }

    public function testRequiredColumnsAreNamed(): void
    {
        self::assertSame(['For', 'Set', 'Price per piece (Rs)'], StockListingImport::header(['Name', 'Color', 'Color code'])['missing']);
    }

    /**
     * A line under the template's columns.
     *
     * @param array<string, string> $values by column name
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private function line(array $values): array
    {
        $columns = StockListingImport::headers();
        $cells = array_map(static fn (string $column): string => $values[$column] ?? '', $columns);

        return StockListingImport::listing($cells, StockListingImport::header($columns));
    }
}
