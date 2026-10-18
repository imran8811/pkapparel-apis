<?php

declare(strict_types=1);

/*
 * One-time import of the 12 blog posts from the old website's PHP views
 * (D:/xampp/htdocs/pkapparel/app/views/post/*.php).
 *
 *   php bin/import-legacy-posts.php              import posts that are not in the database yet
 *   php bin/import-legacy-posts.php --overwrite  also replace posts imported before (edits made in the admin are lost)
 *   php bin/import-legacy-posts.php --dry-run    show what would be imported
 *
 * Clean-up done on the way:
 *   - only the article is kept (no PHP, comment forms, numbered badges or old call-to-action box)
 *   - layout boxes become simple HTML: highlight boxes → quotes, number cards → a list, FAQ questions → headings
 *   - HTML is sanitised; old links point to the new shop URLs; the wrong phone link is fixed
 *   - images are copied to public/uploads/blog (large ones resized)
 *   - in the "list of manufacturers" tables, the copy-pasted Certifications/Buyers columns are
 *     removed and PK Apparel's own row is corrected (MOQ, capacity)
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Text;
use App\Models\Post;

require dirname(__DIR__) . '/vendor/autoload.php';

const TITLES = [
    'jeans-manufacturers-in-pakistan' => 'Jeans Manufacturers in Pakistan',
    'jeans-manufacturing-cost' => 'Jeans Manufacturing Cost',
];

// Order of the old blog listing (first = newest).
const ORDER = [
    'jeans-manufacturers', 'bulk-jeans', 'motorcycle-jeans-manufacturers', 'wholesale-jeans-bulk',
    'wholesale-women-jeans', 'jeans-manufacturing-cost', 'jeans-pants-manufacturers', 'jeans-wholesale',
    'wholesale-denim-jeans-suppliers', 'wholesale-jeans-manufacturers', 'wholesale-jeans-suppliers',
    'jeans-manufacturers-in-pakistan',
];

// Sentences with wrong facts, corrected on import (owner decision 2026-10-17). Migration 0024 corrects posts imported before.
const FACT_FIXES = [
    'As the best jeans manufacturer in the USA, we deliver superb quality jeans with skillful stitching.'
        => 'From our factory in Lahore, we make jeans for brands in the USA, Europe and beyond, with skillful stitching and consistent quality.',
    'At PK Apparel, we work with brands to accommodate flexible order quantities while keeping the '
        => 'At PK Apparel, the minimum is 100 pieces per color, with at least 20 of each size, while keeping the ',
];

// Posts without their own picture get a factory photo.
const FALLBACK_COVERS = [
    'jeans-manufacturers-in-pakistan' => ['site/factory/washing-unit.jpg', 'Washing unit in the PK Apparel factory, Lahore'],
    'wholesale-denim-jeans-suppliers' => ['site/factory/packing-area.jpg', 'Packed denim jeans ready for export'],
    'wholesale-jeans-manufacturers' => ['site/factory/stitching-floor.jpg', 'Stitching hall at the PK Apparel factory'],
    'wholesale-jeans-suppliers' => ['site/factory/cartons.jpg', 'Export cartons ready for dispatch'],
];

const LINK_MAP = [
    '/men/jeans-pant' => '/wholesale-shop/men/jeans-pant',
    '/women/jeans-pant' => '/wholesale-shop/women/jeans-pant',
    '/men/chino-pant' => '/wholesale-shop/men/chino-pant',
    'tel:+923019062877' => 'tel:+923104489288',
];

$dryRun = in_array('--dry-run', $argv, true);
$overwrite = in_array('--overwrite', $argv, true);
$app = App::create(dirname(__DIR__));
$config = $app->container->get(Config::class);
$posts = $app->container->get(Post::class);
$db = $app->container->get(Database::class);
$sanitizer = new HtmlSanitizer();

$siteDir = rtrim($config->string('legacy_db.site_dir'), '/\\');
$uploads = $config->string('paths.uploads');

$files = glob($siteDir . '/app/views/post/*.php') ?: [];
if ($files === []) {
    fwrite(STDERR, "No posts found in {$siteDir}/app/views/post\n");
    exit(1);
}
$rank = static function (string $file): int {
    $position = array_search(basename($file, '.php'), ORDER, true);

    return $position === false ? 99 : (int) $position;
};
usort($files, static fn (string $a, string $b): int => $rank($a) <=> $rank($b));

$now = time();
foreach ($files as $index => $file) {
    $slug = basename($file, '.php');
    $source = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));

    preg_match('/\$heading\s*=\s*["\']([^"\']+)["\']/', $source, $heading);
    preg_match('/\$description\s*=\s*["\']([^"\']+)["\']/', $source, $description);
    $title = TITLES[$slug] ?? ($heading[1] ?? ucwords(str_replace('-', ' ', $slug)));

    $existing = $db->fetch('SELECT id, published_at FROM posts WHERE slug = ?', [$slug]);
    if ($existing !== null && !$overwrite) {
        printf("skipped   %-36s already imported (--overwrite replaces it)
", $slug);
        continue;
    }

    $images = [];
    $body = $sanitizer->sanitize(
        prepareArticle(extractArticle($source)),
        static function (string $url, string $attribute) use ($siteDir, $uploads, $dryRun, &$images): ?string {
            if ($attribute === 'src') {
                if (preg_match('#^/?public/images/(.+)$#', $url, $m) === 1) {
                    $target = 'blog/' . pathinfo($m[1], PATHINFO_FILENAME) . '.jpg';
                    if (!$dryRun) {
                        copyImage($siteDir . '/public/images/' . $m[1], $uploads . '/' . $target);
                    }
                    $images[] = $target;

                    return '/uploads/' . $target;
                }

                return $url;
            }
            if (isset(LINK_MAP[$url])) {
                return LINK_MAP[$url];
            }
            if ($url === '#') {
                return null; // dead link: keep the text, drop the link
            }
            if (preg_match('#^https?://(?:www\.)?pkapparel\.com(/.*)?$#i', $url, $m) === 1) {
                return $m[1] ?? '/';
            }

            return $url;
        },
    );

    $cover = null;
    foreach (['jpg', 'jfif', 'jpeg', 'png'] as $extension) {
        $candidate = "{$siteDir}/public/images/posts/{$slug}.{$extension}";
        if (is_file($candidate)) {
            $cover = ["blog/{$slug}-cover.jpg", $title];
            if (!$dryRun) {
                copyImage($candidate, "{$uploads}/{$cover[0]}");
            }
            break;
        }
    }
    $cover ??= FALLBACK_COVERS[$slug] ?? null;

    $excerpt = Text::excerpt($body);
    $data = [
        'slug' => $slug,
        'title' => $title,
        'excerpt' => $excerpt,
        'body_html' => $body,
        'cover_image_path' => $cover === null ? null : 'uploads/' . $cover[0],
        'cover_image_alt' => $cover[1] ?? null,
        'author_name' => 'PK Apparel',
        'status' => 'published',
        // Keep the date of posts imported before, so re-importing does not move them in the blog.
        'published_at' => $existing['published_at'] ?? gmdate('Y-m-d H:i:s', $now - $index * 60),
        'seo_title' => null,
        'seo_description' => mb_substr($description[1] ?? $excerpt, 0, 300),
        'legacy_path' => '/post/' . $slug,
    ];

    if (!$dryRun) {
        $posts->upsert($data);
    }
    printf(
        "%s %-36s %5d words, %d image(s), cover: %s\n",
        $dryRun ? '[dry-run]' : 'imported ',
        $slug,
        str_word_count(strip_tags($body)),
        count($images),
        $cover[0] ?? 'none',
    );
}

/** The inner HTML of <div class="post-article">, without any PHP. */
function extractArticle(string $source): string
{
    $source = (string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $source);
    if (preg_match('/<div\b[^>]*\bclass="post-article"[^>]*>/i', $source, $marker, PREG_OFFSET_CAPTURE) !== 1) {
        throw new RuntimeException('Article wrapper not found');
    }

    $contentStart = $marker[0][1] + strlen($marker[0][0]);
    $offset = $contentStart;
    $depth = 1;
    while (preg_match('/<(\/?)div\b[^>]*>/i', $source, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $depth += $m[1][0] === '/' ? -1 : 1;
        if ($depth === 0) {
            return substr($source, $contentStart, $m[0][1] - $contentStart);
        }
        $offset = $m[0][1] + strlen($m[0][0]);
    }

    return substr($source, $contentStart);
}

/** Remove old UI blocks, keep in-page anchors, clean up manufacturer tables. */
function prepareArticle(string $html): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="import-root">' . $html . '</div></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($doc);

    $hasClass = static fn (string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    $unwanted = $xpath->query("//*[{$hasClass('post-cta')} or {$hasClass('post-section-badge')} or contains(@class, 'comment')] | //form");
    foreach (iterator_to_array($unwanted) as $node) {
        $node->parentNode?->removeChild($node);
    }

    // Anchors on wrapper elements move to the first heading or table inside them.
    foreach (iterator_to_array($xpath->query('//*[@id]')) as $element) {
        /** @var DOMElement $element */
        $tag = strtolower($element->nodeName);
        if ($element->getAttribute('id') === 'import-root' || in_array($tag, ['h1', 'h2', 'h3', 'h4', 'table'], true)) {
            continue;
        }
        $target = $xpath->query('.//h1 | .//h2 | .//h3 | .//h4 | .//table', $element)->item(0);
        if ($target instanceof DOMElement && !$target->hasAttribute('id')) {
            $target->setAttribute('id', $element->getAttribute('id'));
        }
    }

    foreach (iterator_to_array($xpath->query('//table')) as $table) {
        fixManufacturerTable($xpath, $table);
    }

    // The old phone number also appears as visible link text in one post, and two sentences state wrong facts.
    foreach (iterator_to_array($xpath->query('//text()')) as $text) {
        $value = (string) $text->nodeValue;
        $fixed = strtr($value, ['+92 301 9062877' => '+92 310 4489288'] + FACT_FIXES);
        if ($fixed !== $value) {
            $text->nodeValue = $fixed;
        }
    }

    // Number cards (a row of boxes with a big value and a small label) become a list: "1.30 m Men's Jeans".
    $cardRows = $xpath->query(
        "//div[{$hasClass('row')}][.//div[{$hasClass('post-highlight')}]/small][not(.//p) and not(.//h2) and not(.//h3) and not(.//table)]"
    );
    foreach (iterator_to_array($cardRows) as $row) {
        $list = $doc->createElement('ul');
        foreach ($xpath->query(".//div[{$hasClass('post-highlight')}]", $row) as $card) {
            $value = $doc->createElement('strong');
            $value->appendChild($doc->createTextNode(trim($xpath->evaluate('string(.//strong)', $card))));
            $item = $doc->createElement('li');
            $item->appendChild($value);
            $item->appendChild($doc->createTextNode(' ' . trim($xpath->evaluate('string(.//small)', $card))));
            $list->appendChild($item);
        }
        $row->parentNode?->replaceChild($list, $row);
    }

    // Highlight boxes become quotes; FAQ questions become small headings above their answers.
    foreach (iterator_to_array($xpath->query("//div[{$hasClass('post-highlight')}]")) as $box) {
        renameElement($box, 'blockquote');
    }
    foreach (iterator_to_array($xpath->query("//div[{$hasClass('faq-question')}]")) as $question) {
        renameElement($question, 'h4');
    }

    $root = $doc->getElementById('import-root');
    $out = '';
    foreach ($root?->childNodes ?? [] as $child) {
        $out .= $doc->saveHTML($child);
    }

    return $out;
}

function fixManufacturerTable(DOMXPath $xpath, DOMElement $table): void
{
    $headerRow = $xpath->query('.//tr[th]', $table)->item(0);
    if (!$headerRow instanceof DOMElement) {
        return;
    }

    $labels = array_map(static fn (DOMElement $cell): string => strtolower(trim($cell->textContent)), cellsOf($headerRow));
    $drop = array_keys(array_filter($labels, static fn (string $l): bool => preg_match('/^(certifications?|buyers?)$/', $l) === 1));
    $capacity = array_key_first(array_filter($labels, static fn (string $l): bool => str_contains($l, 'capacity')));
    $moq = array_key_first(array_filter($labels, static fn (string $l): bool => str_contains($l, 'moq') || str_contains($l, 'minimum')));
    if ($drop === [] && $capacity === null && $moq === null) {
        return;
    }

    foreach (iterator_to_array($xpath->query('.//tr', $table)) as $row) {
        $cells = cellsOf($row);
        if (isset($cells[0]) && trim($cells[0]->textContent) === 'PK Apparel') {
            if ($capacity !== null && isset($cells[$capacity])) {
                $cells[$capacity]->textContent = '30,000+ pcs/month';
            }
            if ($moq !== null && isset($cells[$moq])) {
                $cells[$moq]->textContent = '100 pcs per style & color';
            }
        }
        foreach ($drop as $column) {
            if (isset($cells[$column])) {
                $row->removeChild($cells[$column]);
            }
        }
    }
}

/** @return list<DOMElement> */
function cellsOf(DOMElement $row): array
{
    $cells = [];
    foreach ($row->childNodes as $child) {
        if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), ['td', 'th'], true)) {
            $cells[] = $child;
        }
    }

    return $cells;
}

/** Replace an element with a new one of another type, keeping its content. */
function renameElement(DOMElement $element, string $tag): void
{
    $replacement = $element->ownerDocument->createElement($tag);
    while ($element->firstChild !== null) {
        $replacement->appendChild($element->firstChild);
    }
    $element->parentNode?->replaceChild($replacement, $element);
}

/** Copy an image as JPEG, resized to at most 1600px wide. */
function copyImage(string $source, string $target): void
{
    if (!is_file($source)) {
        fwrite(STDERR, "  missing image: {$source}\n");

        return;
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }

    $info = getimagesize($source);
    $image = match ($info[2] ?? null) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($source),
        IMAGETYPE_PNG => imagecreatefrompng($source),
        IMAGETYPE_WEBP => imagecreatefromwebp($source),
        default => false,
    };
    if ($image === false) {
        copy($source, $target);

        return;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, 1600 / $width);
    $newWidth = (int) round($width * $scale);
    $newHeight = (int) round($height * $scale);

    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // flatten transparency onto white
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagejpeg($canvas, $target, 82);
}
