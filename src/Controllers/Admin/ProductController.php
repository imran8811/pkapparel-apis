<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HtmlSanitizer;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\SizeChart;
use App\Services\Redirects;
use App\Services\WebRevalidator;

/** Styles in the wholesale shop (admin and editor). Colors and their photos are saved by ProductColorController. */
final class ProductController extends AdminController
{
    public function __construct(
        private readonly Product $products,
        private readonly ProductColor $colors,
        private readonly SizeChart $charts,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WebRevalidator $revalidator,
        private readonly Redirects $redirects,
    ) {
    }

    /** GET /v1/admin/products?status=active&q=…&page=1 */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 50);
        $filters = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:' . implode(',', Product::STATUSES),
            'q' => 'nullable|string|max:80',
        ])->validate();

        $result = $this->products->adminList($filters['status'] ?? null, $filters['q'] ?? null, $page, $perPage);
        $items = array_map(static fn (array $p): array => [
            'id' => $p['id'],
            'slug' => $p['slug'],
            'name' => $p['name'],
            'style_no' => $p['style_no'],
            'segment' => $p['segment'],
            'product_type' => $p['product_type'],
            'price_cents' => $p['price_cents'],
            'status' => $p['status'],
            'is_featured' => $p['is_featured'],
            'color_count' => $p['color_count'],
            'thumbnail' => $p['thumbnail'] ?? null,
            'updated_at' => $p['updated_at'],
        ], $result['items']);

        return Response::data($items, self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->products->countByStatus()]);
    }

    /** GET /v1/admin/products/{id}: the style with all its colors, photos and price tiers. */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->present($this->id($request)));
    }

    /** POST /v1/admin/products: new styles start as drafts until they have a color with photos. */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->validated($request, null);
        $data['status'] = 'draft';
        $tiers = $data['tiers'];
        unset($data['tiers']);

        $id = $this->products->create($data);
        $this->products->replaceTiers($id, $tiers);
        $this->revalidator->revalidate(['products']);

        return Response::data($this->present($id), [], 201);
    }

    /** PUT /v1/admin/products/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $before = $this->findOrFail($id);
        $data = $this->validated($request, $id);

        if ($data['status'] === 'active' && !$this->hasColorWithPhoto($id)) {
            throw new ValidationException(['status' => 'Add at least one color with a photo before showing this style in the shop.']);
        }
        $tiers = $data['tiers'];
        unset($data['tiers']);

        $this->products->update($id, $data);
        $this->products->replaceTiers($id, $tiers);
        $this->products->touch($id);
        // A style that was in the shop keeps its old address working.
        if ($before['status'] === 'active') {
            $this->redirects->moved(self::path($before), self::path($data), 'style');
        }
        $this->revalidator->revalidate(array_values(array_unique(['products', 'product:' . $before['slug'], 'product:' . $data['slug']])));

        return Response::data($this->present($id));
    }

    /** DELETE /v1/admin/products/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $product = $this->findOrFail($id);

        $this->products->delete($id);
        $this->revalidator->revalidate(['products', 'product:' . $product['slug']]);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function present(int $id): array
    {
        $product = $this->findOrFail($id);
        $product['colors'] = $this->colors->forProducts([$id], false)[$id] ?? [];
        $product['tiers'] = $this->products->tiers($id);

        return $product;
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->products->find($id) ?? throw HttpException::notFound('This style does not exist.');
    }

    /** @param array<string, mixed> $product The style's address in the shop. */
    private static function path(array $product): string
    {
        return "/wholesale-shop/{$product['segment']}/{$product['product_type']}/{$product['slug']}";
    }

    private function hasColorWithPhoto(int $id): bool
    {
        foreach ($this->colors->forProducts([$id], true, 1)[$id] ?? [] as $color) {
            if ($color['images'] !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id): array
    {
        $input = $request->all();
        $data = Validator::make($input, [
            'name' => 'required|string|min:3|max:200',
            'slug' => 'nullable|slug|max:190',
            'style_no' => 'nullable|string|max:40',
            'segment' => 'required|in:' . implode(',', Product::SEGMENTS),
            'product_type' => 'required|in:' . implode(',', Product::TYPES),
            'fit' => 'nullable|string|max:60',
            'fabric' => 'nullable|string|max:120',
            'composition' => 'nullable|string|max:120',
            'fabric_weight' => 'nullable|string|max:40',
            'stretch' => 'nullable|in:stretch,non-stretch',
            'wash' => 'nullable|string|max:120',
            'closure' => 'nullable|string|max:60',
            'description_html' => 'nullable|string|max:200000',
            'features' => 'nullable|array|max:12',
            'sizes' => 'required|array|max:20',
            'size_chart_id' => 'nullable|int|min:1',
            'price_cents' => 'required|int|min:1|max:100000000',
            'moq_per_color' => 'nullable|int|min:1|max:100000',
            'set_pcs_per_size' => 'nullable|int|min:1|max:50',
            'min_pcs_per_size' => 'nullable|int|min:1|max:10000',
            'lead_time_days' => 'nullable|int|min:1|max:365',
            'sample_price_cents' => 'nullable|int|min:0|max:10000000',
            'piece_weight_g' => 'nullable|int|min:1|max:10000',
            'pcs_per_carton' => 'nullable|int|min:1|max:10000',
            'carton_length_mm' => 'nullable|int|min:1|max:5000',
            'carton_width_mm' => 'nullable|int|min:1|max:5000',
            'carton_height_mm' => 'nullable|int|min:1|max:5000',
            'carton_weight_g' => 'nullable|int|min:0|max:100000',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:320',
            'status' => 'nullable|in:' . implode(',', Product::STATUSES),
            'is_featured' => 'nullable|bool',
            'sort_order' => 'nullable|int|min:0|max:1000000',
        ])->validate();

        $data += array_fill_keys(['style_no', 'fit', 'fabric', 'composition', 'fabric_weight', 'stretch', 'wash', 'closure', 'size_chart_id',
            'moq_per_color', 'set_pcs_per_size', 'min_pcs_per_size', 'lead_time_days', 'sample_price_cents', 'piece_weight_g', 'pcs_per_carton',
            'carton_length_mm', 'carton_width_mm', 'carton_height_mm', 'carton_weight_g', 'seo_title', 'seo_description'], null);
        $data['status'] ??= 'draft';
        $data['is_featured'] ??= false;
        $data['sort_order'] ??= 0;

        $data['slug'] = ($data['slug'] ?? null) ?: Text::slug($data['name']);
        if ($this->products->slugExists($data['slug'], $id)) {
            throw new ValidationException(['slug' => 'Another style already uses this web address.']);
        }
        if ($data['size_chart_id'] !== null && $this->charts->find($data['size_chart_id']) === null) {
            throw new ValidationException(['size_chart_id' => 'This size chart does not exist.']);
        }
        if (!Product::typeFits($data['segment'], $data['product_type'])) {
            throw new ValidationException(['product_type' => sprintf('This type is only for %s.', implode(' and ', Product::ONLY_IN[$data['product_type']]))]);
        }

        $data['description_html'] = $this->sanitizer->sanitize((string) ($data['description_html'] ?? ''));
        $data['features'] = self::textList($input['features'] ?? [], 'features', 12, 120);
        $data['sizes'] = self::textList($input['sizes'] ?? [], 'sizes', 20, 12);
        if ($data['sizes'] === []) {
            throw new ValidationException(['sizes' => 'Add at least one size.']);
        }
        if (count(array_unique(array_map('mb_strtolower', $data['sizes']))) !== count($data['sizes'])) {
            throw new ValidationException(['sizes' => 'Each size can only be listed once.']);
        }
        $data['tiers'] = self::tiers($input['tiers'] ?? [], $data['price_cents']);

        return $data;
    }

    /** @return list<string> Trimmed, non-empty text items. */
    private static function textList(mixed $items, string $field, int $max, int $maxLength): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new ValidationException([$field => 'Must be a list.']);
        }
        $clean = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                throw new ValidationException([$field => 'Must be a list of text.']);
            }
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > $maxLength) {
                throw new ValidationException([$field => "Each item can be at most {$maxLength} characters."]);
            }
            $clean[] = $text;
        }
        if (count($clean) > $max) {
            throw new ValidationException([$field => "Add at most {$max} items."]);
        }

        return $clean;
    }

    /** @return list<array{min_qty: int, price_cents: int}> Volume prices, lowest quantity first. */
    private static function tiers(mixed $items, int $basePrice): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > 5) {
            throw new ValidationException(['tiers' => 'Add at most 5 volume prices.']);
        }
        $tiers = [];
        foreach ($items as $index => $item) {
            $tier = Validator::make(is_array($item) ? $item : [], [
                'min_qty' => 'required|int|min:2|max:1000000',
                'price_cents' => 'required|int|min:1|max:100000000',
            ]);
            if (!$tier->passes()) {
                $fields = [];
                foreach ($tier->errors() as $name => $message) {
                    $fields["tiers.{$index}.{$name}"] = $message;
                }
                throw new ValidationException($fields);
            }
            $tiers[] = $tier->validate();
        }
        usort($tiers, static fn (array $a, array $b): int => $a['min_qty'] <=> $b['min_qty']);
        if (count(array_unique(array_column($tiers, 'min_qty'))) !== count($tiers)) {
            throw new ValidationException(['tiers' => 'Each quantity can only be used once.']);
        }
        $previous = $basePrice;
        foreach ($tiers as $tier) {
            if ($tier['price_cents'] >= $previous) {
                throw new ValidationException(['tiers' => 'Each volume price must be lower than the one before it.']);
            }
            $previous = $tier['price_cents'];
        }

        return $tiers;
    }
}
