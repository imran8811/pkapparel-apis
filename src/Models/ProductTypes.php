<?php

declare(strict_types=1);

namespace App\Models;

/**
 * What the shop sells: segments, product types, their size systems and the details (specs) each type shows.
 * The website's lib/nav.ts mirrors the type list (slug, label, segments) for menus and static pages; everything
 * else (labels, suggestions, sizes) reaches the website through the API, so it is defined only here.
 */
final class ProductTypes
{
    public const SEGMENTS = ['men', 'women'];

    /**
     * How a style's sizes are named. "other" is the style's own list, in the order typed (e.g. US 0–16 or "One size").
     * Waist sizes are inches, every size from 24 to 48 (odd sizes such as 33 are common for jeans).
     */
    public const SIZE_SYSTEMS = [
        'waist' => 'Waist (inches)',
        'letter' => 'Letter sizes',
        'other' => 'Other',
    ];

    public const LETTER_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL'];

    /** Other ways of writing the same letter size. */
    private const LETTER_ALIASES = ['2XS' => 'XXS', '2XL' => 'XXL', 'XXXL' => '3XL', 'XXXXL' => '4XL', 'XXXXXL' => '5XL'];

    private const WAIST_MIN = 24;

    private const WAIST_MAX = 48;

    /** Specs stored in their own column of `products`; the others go in `products.attributes`. */
    public const COLUMNS = ['fit', 'fabric', 'composition', 'fabric_weight', 'stretch', 'wash', 'closure'];

    /** Fabric details a color may change (e.g. heather gray in a cotton-polyester blend). Design details stay the style's. */
    public const COLOR_SPECS = ['fabric', 'composition', 'fabric_weight', 'stretch', 'wash', 'closure'];

    /** Default label and maximum length of every spec. Stretch is a fixed choice: "stretch" or "non-stretch". */
    private const FIELDS = [
        'fit' => ['Fit', 60],
        'rise' => ['Rise', 60],
        'length' => ['Length', 60],
        'neckline' => ['Neckline', 60],
        'sleeve' => ['Sleeve', 60],
        'collar' => ['Collar', 60],
        'placket' => ['Placket', 60],
        'hood' => ['Hood', 60],
        'pockets' => ['Pockets', 60],
        'closure' => ['Closure', 60],
        'lining' => ['Lining', 60],
        'fabric' => ['Fabric', 120],
        'composition' => ['Composition', 120],
        'fabric_weight' => ['Fabric weight', 40],
        'stretch' => ['Stretch', 20],
        'wash' => ['Wash', 120],
    ];

    private const DENIM = ['Rigid denim', 'Stretch denim', 'Comfort stretch denim', 'Selvedge denim'];

    private const DENIM_COMPOSITION = ['100% cotton', '99% cotton, 1% spandex', '98% cotton, 2% spandex', '92% cotton, 6% polyester, 2% spandex'];

    private const DENIM_WEIGHT = ['10 oz', '11 oz', '12 oz', '13.5 oz', '14 oz'];

    private const DENIM_WASH = ['Rinse wash', 'Stone wash', 'Enzyme wash', 'Enzyme and stone wash', 'Acid wash', 'Bleach wash', 'Raw (unwashed)'];

    private const TWILL_COMPOSITION = ['100% cotton', '98% cotton, 2% spandex', '65% polyester, 35% cotton'];

    private const TWILL_FINISH = ['Silicone wash', 'Enzyme wash', 'Garment dyed', 'Peach finish'];

    private const KNIT_COMPOSITION = ['100% cotton', '60% cotton, 40% polyester', '80% cotton, 20% polyester', '95% cotton, 5% spandex', '100% polyester'];

    private const KNIT_FINISH = ['Silicone softener', 'Enzyme washed', 'Garment dyed', 'Pigment dyed', 'Acid wash'];

    private const INSEAM = ['30 in', '32 in', '34 in', 'Ankle length'];

    private const RISE = ['Low rise', 'Mid rise', 'High rise'];

    /**
     * Each type: label, the segments it is sold in, its size systems (the first is the usual one) and its specs in display order.
     * A spec maps to its suggested values, or to ['label' => …, 'suggestions' => …] when the type names it differently ("Finish", "Inseam").
     *
     * @var array<string, array{label: string, segments: list<string>, sizes: list<string>, specs: array<string, mixed>}>
     */
    public const TYPES = [
        'jeans-pant' => [
            'label' => 'Jeans pants',
            'segments' => ['men', 'women'],
            'sizes' => ['waist', 'other'],
            'specs' => [
                'fit' => ['Skinny', 'Slim', 'Straight', 'Regular', 'Relaxed', 'Tapered', 'Bootcut', 'Flare', 'Wide leg', 'Baggy', 'Mom', 'Boyfriend'],
                'rise' => self::RISE,
                'length' => ['label' => 'Inseam', 'suggestions' => self::INSEAM],
                'fabric' => self::DENIM,
                'composition' => self::DENIM_COMPOSITION,
                'fabric_weight' => self::DENIM_WEIGHT,
                'stretch' => [],
                'wash' => self::DENIM_WASH,
                'closure' => ['Zip fly', 'Button fly', 'Elastic waist'],
            ],
        ],
        'denim-jacket' => [
            'label' => 'Denim jackets',
            'segments' => ['men', 'women'],
            'sizes' => ['letter', 'other'],
            'specs' => [
                'fit' => ['Regular', 'Slim', 'Oversized', 'Cropped'],
                'closure' => ['Metal buttons', 'Snap buttons', 'Zip'],
                'lining' => ['Unlined', 'Sherpa lined', 'Quilted lining', 'Cotton lining'],
                'fabric' => self::DENIM,
                'composition' => self::DENIM_COMPOSITION,
                'fabric_weight' => self::DENIM_WEIGHT,
                'stretch' => [],
                'wash' => self::DENIM_WASH,
            ],
        ],
        'jeans-short' => [
            'label' => 'Jeans shorts',
            'segments' => ['men', 'women'],
            'sizes' => ['waist', 'letter', 'other'],
            'specs' => [
                'fit' => ['Slim', 'Regular', 'Relaxed', 'Baggy'],
                'rise' => self::RISE,
                'length' => ['label' => 'Inseam', 'suggestions' => ['5 in', '7 in', '9 in', 'Bermuda (11 in)']],
                'fabric' => self::DENIM,
                'composition' => self::DENIM_COMPOSITION,
                'fabric_weight' => self::DENIM_WEIGHT,
                'stretch' => [],
                'wash' => self::DENIM_WASH,
                'closure' => ['Zip fly', 'Button fly', 'Elastic waist', 'Drawstring'],
            ],
        ],
        'cargo-trouser' => [
            'label' => 'Cargo trousers',
            'segments' => ['men'],
            'sizes' => ['waist', 'other'],
            'specs' => [
                'fit' => ['Regular', 'Relaxed', 'Tapered', 'Baggy'],
                'rise' => self::RISE,
                'length' => ['label' => 'Inseam', 'suggestions' => self::INSEAM],
                'pockets' => ['6 pockets', '8 pockets'],
                'fabric' => ['Cotton twill', 'Stretch cotton twill', 'Ripstop', 'Canvas'],
                'composition' => self::TWILL_COMPOSITION,
                'fabric_weight' => ['280 GSM', '300 GSM', '320 GSM', '340 GSM'],
                'stretch' => [],
                'wash' => ['label' => 'Finish', 'suggestions' => self::TWILL_FINISH],
                'closure' => ['Zip fly', 'Button fly', 'Elastic waist with drawcord'],
            ],
        ],
        'chino-pant' => [
            'label' => 'Chino pants',
            'segments' => ['men'],
            'sizes' => ['waist', 'other'],
            'specs' => [
                'fit' => ['Slim', 'Regular', 'Tapered', 'Relaxed'],
                'rise' => self::RISE,
                'length' => ['label' => 'Inseam', 'suggestions' => self::INSEAM],
                'fabric' => ['Cotton twill', 'Stretch cotton twill'],
                'composition' => self::TWILL_COMPOSITION,
                'fabric_weight' => ['280 GSM', '300 GSM', '320 GSM'],
                'stretch' => [],
                'wash' => ['label' => 'Finish', 'suggestions' => self::TWILL_FINISH],
                'closure' => ['Zip fly', 'Button fly'],
            ],
        ],
        'denim-skirt' => [
            'label' => 'Denim skirts',
            'segments' => ['women'],
            'sizes' => ['waist', 'letter', 'other'],
            'specs' => [
                'fit' => ['A-line', 'Straight', 'Pencil', 'A-line maxi'],
                'rise' => self::RISE,
                'length' => ['Mini', 'Knee length', 'Midi', 'Maxi'],
                'fabric' => self::DENIM,
                'composition' => self::DENIM_COMPOSITION,
                'fabric_weight' => self::DENIM_WEIGHT,
                'stretch' => [],
                'wash' => self::DENIM_WASH,
                'closure' => ['Zip front', 'Button front', 'Side zip', 'Elastic waist'],
            ],
        ],
        't-shirt' => [
            'label' => 'T-shirts',
            'segments' => ['men', 'women'],
            'sizes' => ['letter', 'other'],
            'specs' => [
                'fit' => ['Regular', 'Slim', 'Relaxed', 'Oversized', 'Boxy'],
                'neckline' => ['Crew neck', 'V-neck', 'Henley', 'Mock neck', 'Scoop neck'],
                'sleeve' => ['Short sleeve', 'Long sleeve', 'Sleeveless', 'Drop shoulder', 'Raglan sleeve'],
                'fabric' => ['Single jersey', 'Slub jersey', 'Heavyweight jersey', 'Interlock', 'Rib knit'],
                'composition' => self::KNIT_COMPOSITION,
                'fabric_weight' => ['160 GSM', '180 GSM', '200 GSM', '220 GSM', '240 GSM'],
                'wash' => ['label' => 'Finish', 'suggestions' => self::KNIT_FINISH],
            ],
        ],
        'hoodie' => [
            'label' => 'Hoodies',
            'segments' => ['men', 'women'],
            'sizes' => ['letter', 'other'],
            'specs' => [
                'fit' => ['Regular', 'Relaxed', 'Oversized', 'Boxy'],
                'closure' => ['Pullover', 'Full zip', 'Half zip', 'Quarter zip'],
                'hood' => ['Double-layer hood with drawcord', 'Single-layer hood with drawcord', 'Double-layer hood, no drawcord'],
                'pockets' => ['Kangaroo pocket', 'Side pockets', 'Zip pockets', 'No pockets'],
                'fabric' => ['Fleece', 'Brushed fleece', 'French terry', 'Loopback terry'],
                'composition' => self::KNIT_COMPOSITION,
                'fabric_weight' => ['280 GSM', '320 GSM', '360 GSM', '400 GSM', '450 GSM'],
                'wash' => ['label' => 'Finish', 'suggestions' => self::KNIT_FINISH],
            ],
        ],
        'polo-shirt' => [
            'label' => 'Polo shirts',
            'segments' => ['men', 'women'],
            'sizes' => ['letter', 'other'],
            'specs' => [
                'fit' => ['Regular', 'Slim', 'Relaxed'],
                'collar' => ['Rib-knit collar', 'Tipped rib-knit collar', 'Self-fabric collar'],
                'placket' => ['2-button placket', '3-button placket', 'Hidden-button placket', 'Zip placket'],
                'sleeve' => ['Short sleeve, rib cuffs', 'Short sleeve, hemmed', 'Long sleeve'],
                'fabric' => ['Pique', 'Single jersey', 'Interlock', 'Performance mesh'],
                'composition' => self::KNIT_COMPOSITION,
                'fabric_weight' => ['180 GSM', '200 GSM', '220 GSM', '240 GSM'],
                'wash' => ['label' => 'Finish', 'suggestions' => self::KNIT_FINISH],
            ],
        ],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::TYPES);
    }

    public static function fits(string $segment, string $type): bool
    {
        return isset(self::TYPES[$type]) && in_array($segment, self::TYPES[$type]['segments'], true);
    }

    /** "men", "women and men": for the message when a type is not sold in a segment. */
    public static function segmentsText(string $type): string
    {
        return implode(' and ', self::TYPES[$type]['segments'] ?? []);
    }

    /** @return list<string> The size systems a type may use, the usual one first. */
    public static function sizeSystems(string $type): array
    {
        return self::TYPES[$type]['sizes'] ?? ['other'];
    }

    /** @return list<string>|null Every size of a system, in size order; null for "other" (any text). */
    public static function sizesOf(string $system): ?array
    {
        return match ($system) {
            'waist' => array_map('strval', range(self::WAIST_MIN, self::WAIST_MAX)),
            'letter' => self::LETTER_SIZES,
            default => null,
        };
    }

    /**
     * A size as the system writes it ("xl" → "XL", "2XL" → "XXL", "W32" → "32"), or null when the system has no such size.
     * For "other", the trimmed text.
     */
    public static function normalizeSize(string $system, string $size): ?string
    {
        $size = trim($size);
        if ($system === 'waist') {
            $size = preg_replace('/^W\s*/i', '', $size) ?? $size;
        } elseif ($system === 'letter') {
            $size = strtoupper(str_replace(' ', '', $size));
            $size = self::LETTER_ALIASES[$size] ?? $size;
        }
        $all = self::sizesOf($system);
        if ($all === null) {
            return $size === '' ? null : $size;
        }

        return in_array($size, $all, true) ? $size : null;
    }

    /**
     * The specs a type shows, in display order.
     *
     * @return list<array{key: string, label: string, max: int, suggestions: list<string>}>
     */
    public static function specs(string $type): array
    {
        $fields = [];
        foreach (self::TYPES[$type]['specs'] ?? [] as $key => $definition) {
            $fields[] = [
                'key' => $key,
                'label' => $definition['label'] ?? self::FIELDS[$key][0],
                'max' => self::FIELDS[$key][1],
                'suggestions' => $definition['suggestions'] ?? $definition,
            ];
        }

        return $fields;
    }

    /**
     * A style's values for the specs its type shows (from their columns or `attributes`), in display order.
     *
     * @param array<string, mixed> $product decoded products row
     * @return array<string, ?string>
     */
    public static function values(array $product): array
    {
        $values = [];
        foreach (self::specKeys((string) $product['product_type']) as $key) {
            $value = in_array($key, self::COLUMNS, true) ? ($product[$key] ?? null) : ($product['attributes'][$key] ?? null);
            $values[$key] = $value === null || $value === '' ? null : (string) $value;
        }

        return $values;
    }

    /** @return list<string> */
    public static function specKeys(string $type): array
    {
        return array_column(self::specs($type), 'key');
    }

    /** @return list<string> All spec keys of every type. */
    public static function allSpecKeys(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Everything the admin needs to edit styles, size charts and add-ons.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        return [
            'segments' => self::SEGMENTS,
            'size_systems' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::SIZE_SYSTEMS[$key], 'sizes' => self::sizesOf($key)],
                array_keys(self::SIZE_SYSTEMS),
            ),
            'types' => array_map(static fn (string $slug): array => [
                'slug' => $slug,
                'label' => self::TYPES[$slug]['label'],
                'segments' => self::TYPES[$slug]['segments'],
                'size_systems' => self::TYPES[$slug]['sizes'],
                'specs' => array_map(
                    static fn (array $field): array => [
                        'key' => $field['key'],
                        'label' => $field['label'],
                        'max' => $field['max'],
                        'suggestions' => $field['suggestions'],
                        'per_color' => in_array($field['key'], self::COLOR_SPECS, true),
                    ],
                    self::specs($slug),
                ),
            ], self::slugs()),
        ];
    }
}
