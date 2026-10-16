<?php

declare(strict_types=1);

/*
 * One-time import of the old shop's 30 articles into 10 styles (owner-approved grouping, 2026-10-16).
 *
 *   php bin/import-legacy-products.php              import styles that are not in the database yet
 *   php bin/import-legacy-products.php --overwrite  also replace imported styles (edits made in the admin are lost)
 *   php bin/import-legacy-products.php --dry-run    show what would be imported
 *
 * Each old article becomes a color of a style, keeping its article number as the color code
 * (old product URLs end with it, so they can redirect to the right style and color).
 * Prices come from the old database: the most common price becomes the style price, and other
 * colors keep theirs. Photos come from the old site's uploads/<article>/ folders and are re-encoded.
 * Names, fabrics and fits are cleaned up by hand below; MOQ, sets and lead time use the site settings.
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Services\ImageStore;

require dirname(__DIR__) . '/vendor/autoload.php';

const STYLES = [
    [
        'name' => "Men's slim stretch jeans",
        'slug' => 'mens-slim-stretch-jeans',
        'segment' => 'men', 'type' => 'jeans-pant',
        'fit' => 'Slim', 'fabric' => 'Stretch denim', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '11 oz',
        'stretch' => 'stretch', 'wash' => 'Enzyme and stone wash', 'closure' => 'Zip fly',
        'description' => '<p>Slim-fit five-pocket jeans in stretch denim, in light, mid and dark washes plus black, gray, olive and khaki. The olive and khaki colors are made in stretch cotton twill.</p><p>Made to order in our Lahore factory, with your own labels, tags and packaging if you like.</p>',
        'features' => ['Five-pocket styling'],
        'size_chart' => true,
        'colors' => [
            '1051' => ['Sky blue'],
            '1052' => ['Black'],
            '1053' => ['Navy blue'],
            '1054' => ['Olive green', ['fabric' => 'Stretch cotton twill', 'wash' => 'Enzyme wash']],
            '1055' => ['Dark gray', ['wash' => 'Enzyme wash', 'closure' => 'Button fly']],
            '1056' => ['Khaki', ['fabric' => 'Stretch cotton twill', 'wash' => 'Silicone softener wash']],
            '1057' => ['Light gray', ['wash' => 'Enzyme wash']],
            '1058' => ['Mid blue', ['wash' => 'Enzyme wash']],
        ],
    ],
    [
        'name' => "Men's slim stretch chinos",
        'slug' => 'mens-slim-stretch-chinos',
        'segment' => 'men', 'type' => 'chino-pant',
        'fit' => 'Slim', 'fabric' => 'Stretch cotton twill', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '320 GSM',
        'stretch' => 'stretch', 'wash' => 'Silicone wash', 'closure' => 'Zip fly',
        'description' => '<p>Slim-fit chinos in stretch cotton twill with a soft silicone wash, in neutral and seasonal colors from cream to maroon.</p><p>Made to order in our Lahore factory, with your own labels, tags and packaging if you like.</p>',
        'features' => [],
        'size_chart' => true,
        'colors' => [
            '9650' => ['Khaki'],
            '9651' => ['Olive green'],
            '9652' => ['Cream', ['fabric_weight' => '300 GSM']],
            '9653' => ['Dark gray'],
            '9654' => ['Light green'],
            '9655' => ['Maroon'],
            '9656' => ['Navy blue'],
            '9657' => ['Sky blue'],
        ],
    ],
    [
        'name' => "Men's relaxed cargo trousers",
        'slug' => 'mens-relaxed-cargo-trousers',
        'segment' => 'men', 'type' => 'cargo-trouser',
        'fit' => 'Relaxed', 'fabric' => 'Cotton twill (10×10)', 'composition' => '100% cotton', 'fabric_weight' => '320 GSM',
        'stretch' => 'non-stretch', 'wash' => 'Silicone wash', 'closure' => 'Zip fly',
        'description' => '<p>Relaxed-fit cargo trousers with a cargo pocket on each leg, in heavy cotton twill. The olive green has 2% spandex for stretch; the camo is printed twill.</p><p>Made to order in our Lahore factory, with your own labels, tags and packaging if you like.</p>',
        'features' => ['Cargo pocket on each leg'],
        'size_chart' => false,
        'colors' => [
            '4333' => ['Khaki'],
            '4334' => ['Brown'],
            '4335' => ['Olive green', ['composition' => '98% cotton, 2% spandex', 'stretch' => 'stretch']],
            '4336' => ['Camo', ['fabric' => 'Camo-printed cotton twill (10×10)']],
        ],
    ],
    [
        'name' => "Women's wide-leg jeans",
        'slug' => 'womens-wide-leg-jeans',
        'segment' => 'women', 'type' => 'jeans-pant',
        'fit' => 'Wide leg', 'fabric' => 'Rigid denim', 'composition' => '100% cotton', 'fabric_weight' => '12 oz',
        'stretch' => 'non-stretch', 'wash' => 'Stone wash', 'closure' => 'Zip fly',
        'description' => '<p>Wide-leg jeans in rigid cotton denim with a stone wash, in medium blue, ice blue and navy.</p><p>Made to order in our Lahore factory, with your own labels, tags and packaging if you like.</p>',
        'features' => [],
        'size_chart' => false,
        'colors' => [
            '2230' => ['Medium blue'],
            '2231' => ['Ice blue'],
            '2235' => ['Navy blue', ['fabric_weight' => '11 oz']],
        ],
    ],
    [
        'name' => "Women's slim stretch jeans",
        'slug' => 'womens-slim-stretch-jeans',
        'segment' => 'women', 'type' => 'jeans-pant',
        'fit' => 'Slim', 'fabric' => 'Stretch denim', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '11 oz',
        'stretch' => 'stretch', 'wash' => 'Stone wash', 'closure' => 'Zip fly',
        'description' => '<p>Slim-fit jeans in stretch denim with a stone wash, in royal blue and sky blue.</p><p>Made to order in our Lahore factory, with your own labels, tags and packaging if you like.</p>',
        'features' => [],
        'size_chart' => false,
        'colors' => [
            '2233' => ['Royal blue'],
            '2234' => ['Sky blue', ['wash' => 'Silicone and stone wash']],
        ],
    ],
    [
        'name' => "Women's denim maxi skirt",
        'slug' => 'womens-denim-maxi-skirt',
        'segment' => 'women', 'type' => 'denim-skirt',
        'fit' => 'A-line maxi', 'fabric' => 'Rigid denim', 'composition' => '100% cotton', 'fabric_weight' => '12 oz',
        'stretch' => 'non-stretch', 'wash' => 'Stone wash', 'closure' => 'Zip front',
        'description' => '<p>A long A-line skirt in rigid cotton denim with a stone wash.</p><p>Made to order in our Lahore factory, with your own labels, tags and packaging if you like.</p>',
        'features' => [],
        'size_chart' => false,
        'colors' => ['2232' => ['Medium blue']],
    ],
    [
        'name' => "Girls' daisy print flare jeans",
        'slug' => 'girls-daisy-print-flare-jeans',
        'segment' => 'girls', 'type' => 'jeans-pant',
        'fit' => 'Flare', 'fabric' => 'Stretch denim', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '10 oz',
        'stretch' => 'stretch', 'wash' => 'Silicone wash', 'closure' => 'Zip fly',
        'description' => '<p>Flared jeans for girls with a daisy print, in soft stretch denim.</p>',
        'features' => ['Daisy print'],
        'size_chart' => false,
        'colors' => ['3102' => ['Light blue']],
    ],
    [
        'name' => "Girls' pull-on flare jeans",
        'slug' => 'girls-pull-on-flare-jeans',
        'segment' => 'girls', 'type' => 'jeans-pant',
        'fit' => 'Flare', 'fabric' => 'Stretch denim', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '10 oz',
        'stretch' => 'stretch', 'wash' => 'Silicone wash', 'closure' => 'Elastic waist',
        'description' => '<p>Pull-on flared jeans for girls with an elastic waist and a fringed hem with star details, in soft stretch denim.</p>',
        'features' => ['Elastic pull-on waist', 'Fringed hem with star details'],
        'size_chart' => false,
        'colors' => ['3103' => ['Blue']],
    ],
    [
        'name' => "Girls' butterfly print wide-leg jeans",
        'slug' => 'girls-butterfly-print-wide-leg-jeans',
        'segment' => 'girls', 'type' => 'jeans-pant',
        'fit' => 'Wide leg', 'fabric' => 'Stretch denim', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '10 oz',
        'stretch' => 'stretch', 'wash' => 'Silicone wash', 'closure' => 'Zip fly',
        'description' => '<p>Wide-leg jeans for girls with a butterfly print, in a light wash of soft stretch denim.</p>',
        'features' => ['Butterfly print'],
        'size_chart' => false,
        'colors' => ['3104' => ['Light blue']],
    ],
    [
        'name' => "Girls' patched wide-leg jeans",
        'slug' => 'girls-patched-wide-leg-jeans',
        'segment' => 'girls', 'type' => 'jeans-pant',
        'fit' => 'Wide leg', 'fabric' => 'Stretch denim', 'composition' => '98% cotton, 2% spandex', 'fabric_weight' => '10 oz',
        'stretch' => 'stretch', 'wash' => 'Silicone wash', 'closure' => 'Zip fly',
        'description' => '<p>Wide-leg jeans for girls with ripped details and colorful patches, in soft stretch denim.</p>',
        'features' => ['Ripped details', 'Colorful patches'],
        'size_chart' => false,
        'colors' => ['3105' => ['Sky blue']],
    ],
];

/** Photo order on the product page. */
const VIEWS = ['front' => 'front view', 'back' => 'back view', 'side' => 'side view', 'side1' => 'side view', 'side2' => 'detail', 'side3' => 'detail'];

$dryRun = in_array('--dry-run', $argv, true);
$overwrite = in_array('--overwrite', $argv, true);

$app = App::create(dirname(__DIR__));
$config = $app->container->get(Config::class);
$db = $app->container->get(Database::class);
$images = $app->container->get(ImageStore::class);

$legacy = Database::quoteIdentifier($config->string('legacy_db.name'));
$uploads = rtrim($config->string('legacy_db.site_dir'), '/\\') . '/uploads';
$publicDir = dirname($config->string('paths.uploads'));

$old = [];
foreach ($db->fetchAll("SELECT article_no, price, p_sizes, piece_weight, featured FROM {$legacy}.product") as $row) {
    $old[(string) $row['article_no']] = $row;
}

$chartId = $dryRun ? null : menPantsChart($db, $legacy);

foreach (STYLES as $index => $style) {
    $existing = $db->fetchValue('SELECT id FROM products WHERE slug = ?', [$style['slug']]);
    if ($existing !== null && !$overwrite) {
        printf("skipped   %-38s already imported (--overwrite replaces it)\n", $style['slug']);
        continue;
    }

    $articles = array_map('strval', array_keys($style['colors']));
    $missing = array_diff($articles, array_keys($old));
    if ($missing !== []) {
        fwrite(STDERR, "  {$style['slug']}: articles not in the old database: " . implode(', ', $missing) . "\n");
        continue;
    }

    $prices = array_map(static fn (string $a): int => (int) round((float) $old[$a]['price'] * 100), array_combine($articles, $articles));
    $stylePrice = mostCommon(array_values($prices));
    $sizes = explode('-', (string) $old[$articles[0]]['p_sizes']);
    $pieceWeight = max(array_map(static fn (string $a): int => (int) $old[$a]['piece_weight'], $articles));
    $featured = array_filter($articles, static fn (string $a): bool => (int) $old[$a]['featured'] === 1) !== [];

    printf(
        "%s %-38s %d color(s), $%.2f, sizes %s\n",
        $dryRun ? '[dry-run]' : 'imported ',
        $style['slug'],
        count($articles),
        $stylePrice / 100,
        implode('/', $sizes),
    );
    if ($dryRun) {
        continue;
    }

    $db->transaction(function () use ($db, $images, $publicDir, $uploads, $style, $index, $existing, $articles, $prices, $stylePrice, $sizes, $pieceWeight, $featured, $chartId): void {
        if ($existing !== null) {
            deleteStyle($db, $publicDir, (int) $existing);
        }

        $productId = $db->insert('products', [
            'slug' => $style['slug'],
            'name' => $style['name'],
            'segment' => $style['segment'],
            'product_type' => $style['type'],
            'fit' => $style['fit'],
            'fabric' => $style['fabric'],
            'composition' => $style['composition'],
            'fabric_weight' => $style['fabric_weight'],
            'stretch' => $style['stretch'],
            'wash' => $style['wash'],
            'closure' => $style['closure'],
            'description_html' => $style['description'],
            'features' => json_encode($style['features'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'sizes' => json_encode($sizes, JSON_THROW_ON_ERROR),
            'size_chart_id' => $style['size_chart'] ? $chartId : null,
            'price_cents' => $stylePrice,
            'piece_weight_g' => $pieceWeight,
            'status' => 'active',
            'is_featured' => $featured ? 1 : 0,
            'sort_order' => ($index + 1) * 10,
        ]);

        foreach ($articles as $position => $article) {
            [$name, $overrides] = $style['colors'][$article] + [1 => []];
            $folder = "{$uploads}/{$article}";
            $colorSlug = \App\Core\Text::slug($name, 70);
            $colorId = $db->insert('product_colors', [
                'product_id' => $productId,
                'code' => $article,
                'name' => $name,
                'slug' => $colorSlug,
                'hex' => swatch("{$folder}/front.jpg"),
                'price_cents' => $prices[$article] === $stylePrice ? null : $prices[$article],
                'spec_overrides' => json_encode((object) $overrides, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'legacy_article_no' => $article,
                'is_active' => 1,
                'sort_order' => ($position + 1) * 10,
            ]);

            $sort = 0;
            foreach (photos($folder) as $view => $file) {
                $stored = $images->store($file, "{$style['slug']}-{$colorSlug}-{$view}.jpg", 'products');
                $db->insert('product_images', [
                    'product_id' => $productId,
                    'color_id' => $colorId,
                    'path' => $stored['path'],
                    'alt' => sprintf('%s in %s, %s', $style['name'], strtolower($name), VIEWS[$view] ?? 'detail'),
                    'width' => $stored['width'],
                    'height' => $stored['height'],
                    'sort_order' => $sort += 10,
                ]);
            }
        }
    });
}

/** The old site's men's pants chart (both old rows hold the same measurements), created once. */
function menPantsChart(Database $db, string $legacy): ?int
{
    $existing = $db->fetchValue("SELECT id FROM size_charts WHERE name = 'Men''s pants'");
    if ($existing !== null) {
        return (int) $existing;
    }
    $row = $db->fetch("SELECT * FROM {$legacy}.size_charts WHERE dept = 'men' ORDER BY size_chart_id LIMIT 1");
    if ($row === null) {
        return null;
    }

    $columns = ['waist' => 'Waist', 'hip' => 'Hip', 'thigh' => 'Thigh', 'front_rise' => 'Front rise', 'back_rise' => 'Back rise', 'knee' => 'Knee', 'leg_opening' => 'Leg opening'];
    $values = array_map(static fn (string $column): array => explode(',', (string) $row[$column]), array_keys($columns));
    $rows = [];
    foreach (explode(',', (string) $row['size']) as $i => $size) {
        $rows[] = ['size' => trim($size), 'values' => array_map(static fn (array $list): string => trim($list[$i] ?? ''), $values)];
    }

    return $db->insert('size_charts', [
        'name' => "Men's pants",
        'unit' => 'in',
        'measurements' => json_encode(array_values($columns), JSON_THROW_ON_ERROR),
        'size_rows' => json_encode($rows, JSON_THROW_ON_ERROR),
        'note' => 'Garment measurements in inches, as on the old website.',
    ]);
}

/** @return array<string, string> view => file, in display order (front, back, sides) */
function photos(string $folder): array
{
    $files = [];
    foreach (glob($folder . '/*.jpg') ?: [] as $file) {
        $files[pathinfo($file, PATHINFO_FILENAME)] = $file;
    }
    uksort($files, static function (string $a, string $b): int {
        $order = array_flip(array_keys(VIEWS));

        return [$order[$a] ?? 99, $a] <=> [$order[$b] ?? 99, $b];
    });

    return $files;
}

/**
 * The garment's color for the swatch: the median color of the middle of the front photo,
 * leaving out the background (white studio, gray wall…) measured along the photo's left and right edges.
 */
function swatch(string $file): ?string
{
    $image = is_file($file) ? @imagecreatefromjpeg($file) : false;
    if ($image === false) {
        return null;
    }
    $w = imagesx($image);
    $h = imagesy($image);
    $pixels = static function (int $x, int $y, int $width, int $height) use ($image): array {
        $size = 48;
        $sample = imagecreatetruecolor($size, $size);
        imagecopyresampled($sample, $image, 0, 0, $x, $y, $size, $size, max(1, $width), max(1, $height));
        $list = [];
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                $rgb = imagecolorat($sample, $i, $j);
                $list[] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
            }
        }

        return $list;
    };
    $median = static function (array $values): int {
        sort($values);

        return $values[intdiv(count($values), 2)];
    };
    $medianColor = static fn (array $list): array => [
        $median(array_column($list, 0)), $median(array_column($list, 1)), $median(array_column($list, 2)),
    ];

    $edge = (int) ($w * 0.05);
    $background = $medianColor([...$pixels(0, 0, $edge, $h), ...$pixels($w - $edge, 0, $edge, $h)]);
    $garment = array_filter($pixels((int) ($w * 0.2), (int) ($h * 0.25), (int) ($w * 0.6), (int) ($h * 0.35)), static function (array $p) use ($background): bool {
        $distance = sqrt(($p[0] - $background[0]) ** 2 + ($p[1] - $background[1]) ** 2 + ($p[2] - $background[2]) ** 2);
        $white = min($p) > 215 && max($p) - min($p) < 20;

        return $distance > 40 && !$white;
    });
    if ($garment === []) {
        return null;
    }

    return vsprintf('#%02x%02x%02x', $medianColor(array_values($garment)));
}

/** @param list<int> $values The most common value (the lowest on a tie). */
function mostCommon(array $values): int
{
    $counts = array_count_values($values);
    ksort($counts);
    arsort($counts);

    return (int) array_key_first($counts);
}

/** Removes a style imported before, with its photo files. */
function deleteStyle(Database $db, string $publicDir, int $productId): void
{
    foreach ($db->fetchAll('SELECT path FROM product_images WHERE product_id = ?', [$productId]) as $image) {
        $file = $publicDir . '/' . $image['path'];
        if (str_starts_with((string) $image['path'], 'uploads/products/') && is_file($file)) {
            unlink($file);
        }
    }
    $db->execute('DELETE FROM products WHERE id = ?', [$productId]);
}
