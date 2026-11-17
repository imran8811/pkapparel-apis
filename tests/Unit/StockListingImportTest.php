<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csv;
use App\Services\StockListingImport;
use PHPUnit\Framework\TestCase;

/** Reading new stock listings from a CSV file, line by line (the database checks are in the integration tests). */
final class StockListingImportTest extends TestCase
{
    /** The departments and categories as migration 0032 adds them. */
    private const SECTIONS = [
        'departments' => [['slug' => 'men', 'name' => 'Men'], ['slug' => 'women', 'name' => 'Women'], ['slug' => 'boys', 'name' => 'Boys']],
        'categories' => [
            ['slug' => 'jeans', 'name' => 'Jeans', 'product_type' => 'jeans-pant'],
            ['slug' => 'cotton-pants', 'name' => 'Cotton pants', 'product_type' => 'chino-pant'],
        ],
    ];

    public function testTheTemplateHasEveryColumnAndReadsBack(): void
    {
        $rows = Csv::read(Csv::write(StockListingImport::headers(self::SECTIONS), []));

        self::assertCount(1, $rows);
        self::assertSame(['Name', 'Style no.', 'For', 'Category', 'Color', 'Color code', 'Swatch', 'Set', 'Price per piece (Rs)', 'Sets in stock'], array_slice($rows[0], 0, 10));
        self::assertContains('Inseam', $rows[0], 'Specs are named by their label');
        self::assertSame(['Label', 'Description'], array_slice($rows[0], -2));
        self::assertSame([], StockListingImport::header($rows[0], self::SECTIONS)['missing']);
        self::assertCount(1, array_keys($rows[0], 'Wash'), 'A spec of several categories has one column');
        self::assertArrayHasKey('wash', StockListingImport::header(['Name', 'Finish'], self::SECTIONS)['specs'], 'Cotton pants call the wash "Finish"');
    }

    public function testExcelFilesSavedWithSemicolonsAreRead(): void
    {
        self::assertSame([['Name', 'For'], ['Slim jeans', 'Men']], Csv::read("\xEF\xBB\xBFName;For\r\nSlim jeans;Men\r\n"));
    }

    public function testALineBecomesAListing(): void
    {
        [$listing, $problems] = $this->line([
            'Name' => " Men's slim stretch jeans ", 'Style no.' => 'PK-1051', 'For' => 'men', 'Category' => 'Jeans', 'Color' => 'Sky blue', 'Color code' => 'ST-1051',
            'Swatch' => '4A6FA5', 'Set' => '32x2, 28 x 1; 30×2 34:1', 'Price per piece (Rs)' => 'Rs 1,200', 'Sets in stock' => '15',
            'Fit' => 'Slim', 'Stretch' => 'Rigid', 'Label' => 'Our own label', 'Description' => "Five pockets.\n\nSoft & light.",
        ]);

        self::assertSame([], $problems);
        self::assertSame("Men's slim stretch jeans", $listing['name']);
        self::assertSame('men-s-slim-stretch-jeans-sky-blue', $listing['slug'], 'Name and color, as for copies of the wholesale shop');
        self::assertSame(['men', 'jeans', 'Men', 'Jeans'], [$listing['segment'], $listing['category'], $listing['department_name'], $listing['category_name']]);
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
            'Name' => 'Ab', 'For' => 'Girls', 'Category' => 'jeans', 'Color' => 'Black', 'Color code' => 'ST 1', 'Swatch' => 'blue',
            'Set' => '28-2', 'Price per piece (Rs)' => '0', 'Sets in stock' => 'ten', 'Stretch' => 'some', 'Fit' => str_repeat('x', 61),
        ]);

        self::assertNull($listing);
        self::assertSame([
            'The name needs 3 to 200 characters.',
            '“For” is Men, Women or Boys, not “Girls”.',
            'The color code “ST 1” can only have letters, digits and dashes (at most 40).',
            'The swatch “blue” is not a color like #4a6fa5.',
            'Set “28-2”: write each waist size x its pieces, e.g. 28x2, 30x2, 32x2.',
            'The price per piece “0” is not an amount from Rs 1 to Rs 50,000.',
            '“ten” is not a whole number of sets in stock.',
            'Stretch is “Stretch” or “Rigid”, not “some”.',
            'Fit: Use at most 60 characters.',
        ], $problems);

        $line = ['Name' => 'Slim jeans', 'Color' => 'Black', 'Color code' => 'B-1', 'Set' => '30x1', 'Price per piece (Rs)' => '500'];
        self::assertSame(['“For” is missing: write Men, Women or Boys.', 'The category is missing: write Jeans or Cotton pants.'], $this->line($line)[1]);
        self::assertSame(['The category is Jeans or Cotton pants, not “Shirts”.'], $this->line(['For' => 'Men', 'Category' => 'Shirts'] + $line)[1]);
    }

    public function testDepartmentsAndCategoriesAreReadAsPeopleWriteThem(): void
    {
        $department = fn (string $text): ?string => StockListingImport::section($text, self::SECTIONS['departments'])['slug'] ?? null;
        $category = fn (string $text): ?string => StockListingImport::section($text, self::SECTIONS['categories'])['slug'] ?? null;

        self::assertSame(['men', 'men', 'men', 'men', 'women', 'women', 'boys', 'boys', 'boys'], array_map($department, ['Men', "men's", 'Mens', 'man', 'WOMAN', "Women's", 'boy', "Boys'", "boy's"]));
        self::assertNull($department('Girls'));
        self::assertNull($department(''));
        self::assertSame(['jeans', 'jeans', 'cotton-pants', 'cotton-pants', 'cotton-pants'], array_map($category, ['Jeans', 'jean', 'Cotton pants', 'cotton pant', 'cotton-pants']));
        self::assertNull($category('Jeans pant'), 'Only the names and web addresses of the categories');
    }

    public function testEachLineHasTheSpecsOfItsCategory(): void
    {
        $sections = self::SECTIONS;
        $sections['categories'][] = ['slug' => 'cargo', 'name' => 'Cargo trousers', 'product_type' => 'cargo-trouser'];
        $line = ['Name' => 'Test pants', 'For' => 'Men', 'Color' => 'Khaki', 'Color code' => 'K-1', 'Set' => '30x1', 'Price per piece (Rs)' => '500', 'Pockets' => '6 pockets', 'Wash' => 'Peach finish'];

        self::assertContains('Pockets', StockListingImport::headers($sections), 'A spec of only one category has its column');
        $cargo = $this->line(['Category' => 'Cargo trousers'] + $line, $sections)[0];
        self::assertSame(['pockets' => '6 pockets', 'wash' => 'Peach finish'], $cargo['specs']);
        self::assertSame(['wash' => 'Peach finish'], $this->line(['Category' => 'Cotton pants'] + $line, $sections)[0]['specs'], 'Jeans and cotton pants have no pockets spec');
    }

    public function testSetsFollowTheStockRules(): void
    {
        $problem = fn (string $set): string => $this->line(['Name' => 'Slim jeans', 'For' => 'Boys', 'Category' => 'Jeans', 'Color' => 'Black', 'Color code' => 'B-1', 'Set' => $set, 'Price per piece (Rs)' => '500'])[1][0] ?? '';

        self::assertSame('', $problem('16x1, 18x1'), 'Boys\' sizes start at 16');
        self::assertSame('Set “50x1”: Sizes are waist sizes from 16 to 48.', $problem('50x1'));
        self::assertSame('Set “30x25”: Each size needs 1 to 24 pieces per set.', $problem('30x25'));
        self::assertSame('Set “30x1, 30x2”: Each size can only be listed once.', $problem('30x1, 30x2'));
        self::assertSame('The set is missing, e.g. 28x2, 30x2, 32x2.', $problem(''));
        self::assertSame('Set “28, 30”: write each waist size x its pieces, e.g. 28x2, 30x2, 32x2.', $problem('28, 30'));
    }

    public function testRequiredColumnsAreNamed(): void
    {
        self::assertSame(['For', 'Category', 'Set', 'Price per piece (Rs)'], StockListingImport::header(['Name', 'Color', 'Color code'], self::SECTIONS)['missing']);
        self::assertSame([], StockListingImport::header(['Name', 'Department', 'Category', 'Color', 'Code', 'Sizes', 'Price'], self::SECTIONS)['missing'], '“Department” names the For column too');
    }

    /**
     * A line under the template's columns.
     *
     * @param array<string, string> $values by column name
     * @param array{departments: list<array<string, mixed>>, categories: list<array<string, mixed>>} $sections
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    private function line(array $values, array $sections = self::SECTIONS): array
    {
        $columns = StockListingImport::headers($sections);
        $cells = array_map(static fn (string $column): string => $values[$column] ?? '', $columns);

        return StockListingImport::listing($cells, StockListingImport::header($columns, $sections), $sections);
    }
}
