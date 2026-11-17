<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Csv;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Text;
use App\Core\ValidationException;
use App\Models\ProductTypes;
use App\Models\StockCategory;
use App\Models\StockColor;
use App\Models\StockDepartment;
use App\Models\StockStyle;

/**
 * New stock listings in bulk (owner request 2026-11-15): one listing per line of a CSV file saved from Excel, with its
 * details, set, price per piece, color and opening stock. The file is checked first (a preview, nothing saved); the
 * listings are created only when no line has a problem, all of them or none. They start as drafts, like a listing
 * added by hand: each needs a photo of its color before it can be shown.
 *
 * Columns, in any order (others are ignored): Name, Style no., For (a department: Men, Women, Boys…), Category (Jeans,
 * Cotton pants…), Color, Color code, Swatch, Set ("28x2, 30x2, 32x2": waist size x pieces), Price per piece (Rs), Sets
 * in stock, a column per spec named by its label ("Fit", "Inseam", …, "Label") and Description (plain text; a blank line
 * starts a new paragraph). The spec columns are those of every category; each line uses those of its own category.
 *
 * The departments and categories ($sections) come from the database: {departments: [{slug, name}…], categories:
 * [{slug, name, product_type}…]}.
 */
final class StockListingImport
{
    public const MAX_BYTES = 512 * 1024;

    public const MAX_ROWS = 500;

    private const MAX_ERRORS = 100;

    /** The columns before the specs: their name in the template and the names accepted (lower case). */
    private const COLUMNS = [
        'name' => ['Name', ['name', 'style', 'style name']],
        'style_no' => ['Style no.', ['style no.', 'style no', 'style number']],
        'segment' => ['For', ['for', 'department', 'segment']],
        'category' => ['Category', ['category']],
        'color' => ['Color', ['color', 'colour', 'color name']],
        'code' => ['Color code', ['color code', 'colour code', 'code']],
        'hex' => ['Swatch', ['swatch', 'swatch color', 'hex']],
        'set' => ['Set', ['set', 'set sizes', 'sizes']],
        'price' => ['Price per piece (Rs)', ['price per piece (rs)', 'price per piece', 'price']],
        'sets' => ['Sets in stock', ['sets in stock', 'sets']],
    ];

    private const REQUIRED = ['name', 'segment', 'category', 'color', 'code', 'set', 'price'];

    private const DESCRIPTION = 'Description';

    private const STRETCH = ['stretch' => 'stretch', 'rigid' => 'non-stretch', 'non-stretch' => 'non-stretch', 'non stretch' => 'non-stretch', 'no stretch' => 'non-stretch', 'rigid (no stretch)' => 'non-stretch'];

    public function __construct(
        private readonly Database $db,
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
        private readonly StockDepartment $departments,
        private readonly StockCategory $categories,
        private readonly StockLevels $levels,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** An empty file with every column, to fill in Excel. */
    public function template(): string
    {
        return Csv::write(self::headers($this->sections()), []);
    }

    /** @return array{departments: list<array<string, mixed>>, categories: list<array<string, mixed>>} */
    public function sections(): array
    {
        return ['departments' => $this->departments->all(), 'categories' => $this->categories->all()];
    }

    /**
     * The template's columns: the listing, its color and stock, the specs of every category, then the description.
     *
     * @param array{departments: list<array<string, mixed>>, categories: list<array<string, mixed>>} $sections
     * @return list<string>
     */
    public static function headers(array $sections): array
    {
        return [...array_column(self::COLUMNS, 0), ...array_column(self::specColumns($sections), 'label'), self::DESCRIPTION];
    }

    /**
     * The spec columns: every spec of the categories' product types once, named by its first label (a spec named
     * differently by another type, such as "Wash" and "Finish", is read under either name), then the stock's own.
     *
     * @param array{departments: list<array<string, mixed>>, categories: list<array<string, mixed>>} $sections
     * @return list<array{key: string, label: string, names: list<string>}>
     */
    private static function specColumns(array $sections): array
    {
        $columns = [];
        $add = static function (array $field) use (&$columns): void {
            $columns[$field['key']] ??= ['key' => $field['key'], 'label' => $field['label'], 'names' => [$field['key'], str_replace('_', ' ', $field['key'])]];
            $columns[$field['key']]['names'][] = mb_strtolower($field['label']);
        };
        foreach ($sections['categories'] as $category) {
            foreach (ProductTypes::specs((string) $category['product_type']) as $field) {
                $add($field);
            }
        }
        foreach (StockStyle::OWN_SPECS as $field) {
            $add($field);
        }

        return array_values(array_map(static fn (array $column): array => ['names' => array_values(array_unique($column['names']))] + $column, $columns));
    }

    /**
     * Reads new listings from a CSV file and, when $apply is true and no line has a problem, creates them.
     *
     * @return array{listings: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, applied: bool}
     */
    public function import(string $csv, bool $apply, ?int $staffId): array
    {
        $result = ['listings' => [], 'errors' => [], 'applied' => false];
        $sections = $this->sections();
        if (strlen($csv) > self::MAX_BYTES) {
            $result['errors'][] = ['line' => 0, 'message' => 'The file is larger than 512 KB. Import at most ' . self::MAX_ROWS . ' listings at a time.'];

            return $result;
        }
        $lines = Csv::read($csv);
        $header = self::header(array_shift($lines) ?? [], $sections);
        if ($header['missing'] !== []) {
            $names = implode(', ', array_map(static fn (string $name): string => "“{$name}”", $header['missing']));
            $result['errors'][] = ['line' => 1, 'message' => "The file needs the columns {$names}. Start from the template."];

            return $result;
        }
        if (count($lines) > self::MAX_ROWS) {
            $result['errors'][] = ['line' => 0, 'message' => 'The file has more than ' . self::MAX_ROWS . ' listings. Import it in parts.'];

            return $result;
        }

        // What is taken already (0) and, once seen, the line of the file that takes it.
        $codes = array_fill_keys(array_map(static fn (array $row): string => mb_strtolower((string) $row['code']), $this->db->fetchAll('SELECT code FROM stock_colors')), 0);
        $slugs = array_fill_keys(array_map(static fn (array $row): string => (string) $row['slug'], $this->db->fetchAll('SELECT slug FROM stock_styles')), 0);
        $listings = [];
        foreach ($lines as $index => $cells) {
            $line = $index + 2;
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }
            [$listing, $problems] = self::listing($cells, $header, $sections);
            if ($listing !== null) {
                $code = mb_strtolower($listing['color']['code']);
                if (isset($codes[$code])) {
                    $problems[] = $codes[$code] === 0
                        ? "Another stock color already has the code “{$listing['color']['code']}”."
                        : "The code “{$listing['color']['code']}” is also on line {$codes[$code]}.";
                }
                if (isset($slugs[$listing['slug']])) {
                    $problems[] = $slugs[$listing['slug']] === 0
                        ? "A stock listing named “{$listing['name']}” in {$listing['color']['name']} exists already."
                        : "Line {$slugs[$listing['slug']]} has the same name and color.";
                }
                $codes[$code] ??= $line;
                $slugs[$listing['slug']] ??= $line;
            }
            foreach ($problems as $problem) {
                if (count($result['errors']) < self::MAX_ERRORS) {
                    $result['errors'][] = ['line' => $line, 'message' => $problem];
                }
            }
            if ($listing !== null && $problems === []) {
                $listings[] = ['line' => $line] + $listing;
            }
        }
        if ($result['errors'] === [] && $listings === []) {
            $result['errors'][] = ['line' => 0, 'message' => 'The file has no listings: fill in a line for each one under the column names.'];
        }

        $ids = [];
        if ($apply && $result['errors'] === []) {
            $ids = $this->db->transaction(function () use ($listings, $staffId): array {
                $ids = [];
                foreach ($listings as $listing) {
                    $id = $this->styles->create([
                        'slug' => $listing['slug'],
                        'name' => $listing['name'],
                        'style_no' => $listing['style_no'],
                        'segment' => $listing['segment'],
                        'category' => $listing['category'],
                        'specs' => $listing['specs'],
                        'description_html' => $this->sanitizer->sanitize($listing['description_html']),
                        'set_sizes' => $listing['set_sizes'],
                        'piece_price_cents' => $listing['piece_price_cents'],
                        'seo_title' => null,
                        'seo_description' => null,
                        'status' => 'draft',
                        'is_featured' => false,
                        'sort_order' => 0,
                    ]);
                    $colorId = $this->colors->create($id, $listing['color'] + ['is_active' => true, 'sort_order' => 10], []);
                    if ($listing['sets_in_stock'] > 0) {
                        $this->levels->change($colorId, 'received', $listing['sets_in_stock'], 'Stock when the listing was imported', $staffId);
                    }
                    $ids[] = $id;
                }

                return $ids;
            });
            $result['applied'] = true;
            $this->revalidator->revalidate(['stock']);
        }

        $result['listings'] = array_map(static fn (array $listing, int $index): array => [
            'line' => $listing['line'],
            'id' => $ids[$index] ?? null,
            'name' => $listing['name'],
            'style_no' => $listing['style_no'],
            'segment' => $listing['segment'],
            'category' => $listing['category'],
            'department_name' => $listing['department_name'],
            'category_name' => $listing['category_name'],
            'slug' => $listing['slug'],
            'color' => $listing['color']['name'],
            'code' => $listing['color']['code'],
            'hex' => $listing['color']['hex'],
            'set_sizes' => $listing['set_sizes'],
            'set_pieces' => StockStyle::setPieces($listing['set_sizes']),
            'piece_price_cents' => $listing['piece_price_cents'],
            'set_price_cents' => $listing['piece_price_cents'] * StockStyle::setPieces($listing['set_sizes']),
            'sets_in_stock' => $listing['sets_in_stock'],
            'specs' => count($listing['specs']),
        ], $listings, array_keys($listings));

        return $result;
    }

    /**
     * Where each column is in the file's first line, and the required columns it lacks.
     *
     * @param list<string> $cells
     * @param array{departments: list<array<string, mixed>>, categories: list<array<string, mixed>>} $sections
     * @return array{columns: array<string, int>, specs: array<string, int>, description: ?int, missing: list<string>}
     */
    public static function header(array $cells, array $sections): array
    {
        $names = array_map(static fn (string $cell): string => mb_strtolower(trim($cell)), $cells);
        $find = static function (array $accepted) use ($names): ?int {
            foreach ($names as $index => $name) {
                if (in_array($name, $accepted, true)) {
                    return $index;
                }
            }

            return null;
        };

        $columns = [];
        foreach (self::COLUMNS as $key => [, $accepted]) {
            $index = $find($accepted);
            if ($index !== null) {
                $columns[$key] = $index;
            }
        }
        $specs = [];
        foreach (self::specColumns($sections) as $column) {
            $index = $find($column['names']);
            if ($index !== null) {
                $specs[$column['key']] = $index;
            }
        }
        $missing = array_values(array_map(
            static fn (string $key): string => self::COLUMNS[$key][0],
            array_filter(self::REQUIRED, static fn (string $key): bool => !isset($columns[$key])),
        ));

        return ['columns' => $columns, 'specs' => $specs, 'description' => $find([mb_strtolower(self::DESCRIPTION)]), 'missing' => $missing];
    }

    /**
     * One line of the file as a listing, or the problems that stop it (stock colors and web addresses already taken
     * are checked by import()).
     *
     * @param list<string> $cells
     * @param array{columns: array<string, int>, specs: array<string, int>, description: ?int, missing: list<string>} $header
     * @param array{departments: list<array<string, mixed>>, categories: list<array<string, mixed>>} $sections
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    public static function listing(array $cells, array $header, array $sections): array
    {
        $cell = static fn (?int $index): string => $index === null ? '' : trim((string) ($cells[$index] ?? ''));
        $value = static fn (string $key): string => $cell($header['columns'][$key] ?? null);
        $problems = [];

        $name = $value('name');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 200) {
            $problems[] = $name === '' ? 'The name is missing.' : 'The name needs 3 to 200 characters.';
        }
        $styleNo = $value('style_no');
        if (mb_strlen($styleNo) > 40) {
            $problems[] = 'The style no. has more than 40 characters.';
        }
        $for = $value('segment');
        $department = self::section($for, $sections['departments']);
        if ($department === null) {
            $names = self::names($sections['departments']);
            $problems[] = $for === '' ? "“For” is missing: write {$names}." : "“For” is {$names}, not “{$for}”.";
        }
        $categoryText = $value('category');
        $category = self::section($categoryText, $sections['categories']);
        if ($category === null) {
            $names = self::names($sections['categories']);
            $problems[] = $categoryText === '' ? "The category is missing: write {$names}." : "The category is {$names}, not “{$categoryText}”.";
        }

        $color = $value('color');
        if ($color === '' || mb_strlen($color) > 80) {
            $problems[] = $color === '' ? 'The color is missing.' : 'The color has more than 80 characters.';
        }
        $code = $value('code');
        if ($code === '') {
            $problems[] = 'The color code is missing.';
        } elseif (strlen($code) > 40 || preg_match(StockColor::CODE_PATTERN, $code) !== 1) {
            $problems[] = "The color code “{$code}” can only have letters, digits and dashes (at most 40).";
        }
        $hex = $value('hex');
        if ($hex !== '' && preg_match('/^#?[0-9a-f]{6}$/i', $hex) !== 1) {
            $problems[] = "The swatch “{$hex}” is not a color like #4a6fa5.";
        }

        $set = $value('set');
        $sizes = null;
        $items = self::setSizes($set);
        if ($set === '') {
            $problems[] = 'The set is missing, e.g. 28x2, 30x2, 32x2.';
        } elseif ($items === null) {
            $problems[] = "Set “{$set}”: write each waist size x its pieces, e.g. 28x2, 30x2, 32x2.";
        } else {
            try {
                $sizes = StockStyle::cleanSetSizes($items);
            } catch (ValidationException $e) {
                $problems[] = "Set “{$set}”: " . ($e->fields['set_sizes'] ?? $e->getMessage());
            }
        }

        $priceText = $value('price');
        $price = self::paisa($priceText);
        if ($price === null || $price < 100 || $price > 5_000_000) {
            $problems[] = $priceText === '' ? 'The price per piece is missing.' : "The price per piece “{$priceText}” is not an amount from Rs 1 to Rs 50,000.";
        }
        $sets = $value('sets');
        if ($sets !== '' && preg_match('/^\d{1,6}$/', $sets) !== 1) {
            $problems[] = "“{$sets}” is not a whole number of sets in stock.";
        }

        $specs = [];
        foreach ($header['specs'] as $key => $index) {
            $specs[$key] = $cell($index);
        }
        if (($specs['stretch'] ?? '') !== '') {
            $stretch = self::STRETCH[mb_strtolower($specs['stretch'])] ?? null;
            if ($stretch === null) {
                $problems[] = "Stretch is “Stretch” or “Rigid”, not “{$specs['stretch']}”.";
            }
            $specs['stretch'] = $stretch ?? '';
        }
        // The specs of the line's category (those of other categories are left out, as in the listing form).
        if ($category !== null) {
            $productType = (string) $category['product_type'];
            try {
                $specs = StockStyle::cleanSpecs($specs, $productType);
            } catch (ValidationException $e) {
                $labels = array_column(StockStyle::specFields($productType), 'label', 'key');
                foreach ($e->fields as $field => $message) {
                    $problems[] = ($labels[substr($field, strlen('specs.'))] ?? 'Specifications') . ': ' . $message;
                }
            }
        }

        $slug = $problems === [] ? StockStyle::slugFor($name, $color) : '';
        if ($problems === [] && $slug === '') {
            $problems[] = 'The name and color need some letters or digits for the web address.';
        }
        if ($problems !== [] || $department === null || $category === null) {
            return [null, $problems];
        }

        return [[
            'slug' => $slug,
            'name' => $name,
            'style_no' => $styleNo === '' ? null : $styleNo,
            'segment' => (string) $department['slug'],
            'category' => (string) $category['slug'],
            'department_name' => (string) $department['name'],
            'category_name' => (string) $category['name'],
            'specs' => $specs,
            'description_html' => self::paragraphs($cell($header['description'])),
            'set_sizes' => $sizes,
            'piece_price_cents' => $price,
            'sets_in_stock' => (int) ($sets === '' ? 0 : $sets),
            'color' => [
                'code' => $code,
                'name' => $color,
                'slug' => Text::slug($color, 70) ?: 'color',
                'hex' => $hex === '' ? null : '#' . strtolower(ltrim($hex, '#')),
            ],
        ], []];
    }

    /**
     * "28x2, 30x2, 32x2" (also "28 x 2", "28×2", "28:2" or "28=2", separated by commas, semicolons or spaces) as the
     * set's sizes, for StockStyle::cleanSetSizes(); null when the text is not written that way.
     *
     * @return list<array{size: string, pcs: int}>|null
     */
    public static function setSizes(string $text): ?array
    {
        $text = preg_replace('/\s*(?:x|X|×|:|=)\s*/u', 'x', trim($text)) ?? '';
        $items = [];
        foreach (preg_split('/[,;\s]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (preg_match('/^(\d{1,3})x(\d{1,3})$/', $part, $match) !== 1) {
                return null;
            }
            $items[] = ['size' => $match[1], 'pcs' => (int) $match[2]];
        }

        return $items;
    }

    /**
     * The department or category a cell names, by its name or web address, written loosely: "Men", "men's", "Mens" and
     * "man" are all the men's department, "boy" and "Boys'" the boys', "cotton pant" the cotton pants.
     *
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>|null
     */
    public static function section(string $text, array $sections): ?array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }
        $key = self::matchKey($text);
        foreach ($sections as $section) {
            $names = [mb_strtolower((string) $section['name']), (string) $section['slug']];
            if (in_array($text, $names, true) || ($key !== '' && in_array($key, array_map(self::matchKey(...), $names), true))) {
                return $section;
            }
        }

        return null;
    }

    /** "men's" → "men", "Boys'" → "boy", "cotton pants" → "cottonpant", "woman" → "women". */
    private static function matchKey(string $text): string
    {
        $key = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text));
        $key = (string) preg_replace('/s$/', '', $key);

        return ['man' => 'men', 'woman' => 'women'][$key] ?? $key;
    }

    /**
     * "Men, Women or Boys": the names to write.
     *
     * @param list<array<string, mixed>> $sections
     */
    private static function names(array $sections): string
    {
        $names = array_map(static fn (array $section): string => (string) $section['name'], $sections);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' or ' . $last;
    }

    /** "Rs 1,200" or "980.50" in paisa; null when it is not an amount. */
    public static function paisa(string $text): ?int
    {
        $amount = preg_replace('/^rs\.?\s*/i', '', str_replace([',', ' '], '', trim($text))) ?? '';

        return preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amount) === 1 ? (int) round((float) $amount * 100) : null;
    }

    /** Plain text as HTML paragraphs: a blank line starts a new one, a single line break stays. */
    private static function paragraphs(string $text): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>' . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }
}
