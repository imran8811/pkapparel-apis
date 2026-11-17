<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\StockCategory;
use App\Models\StockColor;
use App\Models\StockDepartment;
use App\Models\StockMovement;
use App\Models\StockStyle;
use App\Services\ImageStore;
use App\Services\Redirects;
use App\Services\StockCatalog;
use App\Services\StockLevels;
use App\Services\WebRevalidator;

/**
 * Stock styles (admin and editor): ready clothes sold by set, each in a department and a category. Colors, their photos
 * and their stock are saved by StockColorController.
 */
final class StockStyleController extends AdminController
{
    public function __construct(
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
        private readonly StockMovement $movements,
        private readonly StockDepartment $departments,
        private readonly StockCategory $categories,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WebRevalidator $revalidator,
        private readonly Redirects $redirects,
        private readonly ImageStore $images,
        private readonly StockLevels $levels,
        private readonly Database $db,
    ) {
    }

    /** GET /v1/admin/stock/styles?status=active&segment=men&category=jeans&q=…&page=1 */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 50);
        $filters = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:' . implode(',', StockStyle::STATUSES),
            'segment' => 'nullable|in:' . implode(',', $this->departments->slugs()),
            'category' => 'nullable|in:' . implode(',', $this->categories->slugs()),
            'q' => 'nullable|string|max:80',
        ])->validate();

        $result = $this->styles->adminList($filters['status'] ?? null, $filters['q'] ?? null, $page, $perPage, $filters['segment'] ?? null, $filters['category'] ?? null);
        $items = array_map(static fn (array $s): array => [
            'id' => $s['id'],
            'slug' => $s['slug'],
            'name' => $s['name'],
            'style_no' => $s['style_no'],
            'segment' => $s['segment'],
            'category' => $s['category'],
            'department_name' => $s['department_name'],
            'category_name' => $s['category_name'],
            'piece_price_cents' => $s['piece_price_cents'],
            'set_price_cents' => $s['set_price_cents'],
            'set_pieces' => $s['set_pieces'],
            'status' => $s['status'],
            'is_featured' => $s['is_featured'],
            'color_count' => $s['color_count'],
            'sets_in_stock' => $s['sets_in_stock'],
            // The listing's color (one per listing).
            'color_name' => $s['color_name'],
            'color_code' => $s['color_code'],
            'color_hex' => $s['color_hex'],
            'thumbnail' => $s['thumbnail'] ?? null,
            'updated_at' => $s['updated_at'],
        ], $result['items']);

        return Response::data($items, self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->styles->countByStatus()]);
    }

    /** GET /v1/admin/stock/styles/{id}: the style with all its colors, photos and sets in stock. */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->present($this->id($request)));
    }

    /**
     * POST /v1/admin/stock/styles: the listing's color may come along as "color": {name, images, sets_in_stock, code?,
     * hex?}; it is checked first, so the listing is saved with it or not at all. With a photo, the listing is shown on
     * the stock pages at once (owner request 2026-11-16: the add form publishes); without one it is a draft, as a
     * listing needs a photo to be shown.
     */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->validated($request, null);
        $color = $this->firstColor($request->all()['color'] ?? null);
        $data['status'] = $color !== null && $color['photos'] !== [] ? 'active' : 'draft';
        $staffId = $this->staff($request)['id'];

        $id = $this->db->transaction(function () use ($data, $color, $staffId): int {
            $id = $this->styles->create($data);
            if ($color !== null) {
                $colorId = $this->colors->create($id, $color['data'], $color['photos']);
                if ($color['sets'] > 0) {
                    $this->levels->change($colorId, 'received', $color['sets'], 'Stock when the color was added', $staffId);
                }
            }

            return $id;
        });
        $this->revalidator->revalidate(['stock', 'stock:' . $data['slug']]);

        return Response::data($this->present($id), [], 201);
    }

    /** PUT /v1/admin/stock/styles/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $before = $this->findOrFail($id);
        $data = $this->validated($request, $before);
        // A web address made from the name (and the color, if it has it) follows a new name, unless staff typed another.
        if ($data['slug'] === $before['slug']) {
            $color = $this->colors->forStyles([$id], false)[$id][0] ?? null;
            $renamed = Text::renamedSlug($before['slug'], $before['name'], $data['name'], $color === null ? '' : '-' . $color['slug']);
            if ($renamed !== null) {
                $data['slug'] = $this->freeSlug($renamed, $id);
            }
        }

        if ($data['status'] === 'active' && !$this->hasColorWithPhoto($id)) {
            throw new ValidationException(['status' => 'Add at least one color with a photo before showing this style.']);
        }

        $this->styles->update($id, $data);
        // A style that was shown keeps its old address working.
        if ($before['status'] === 'active') {
            $this->redirects->moved(self::path($before), self::path($data), 'style');
        }
        $this->revalidator->revalidate(array_values(array_unique(['stock', 'stock:' . $before['slug'], 'stock:' . $data['slug']])));

        return Response::data($this->present($id));
    }

    /** DELETE /v1/admin/stock/styles/{id}: the style, its colors, photos and stock history. */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $style = $this->findOrFail($id);

        $this->styles->delete($id);
        $this->revalidator->revalidate(['stock', 'stock:' . $style['slug']]);

        return Response::noContent();
    }

    /** GET /v1/admin/stock/movements?style_id=&color_id=&page=: the stock history, newest first. */
    public function movements(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 50);
        $filters = Validator::make($request->allQuery(), [
            'style_id' => 'nullable|int|min:1',
            'color_id' => 'nullable|int|min:1',
        ])->validate();

        $result = $this->movements->list($filters['style_id'] ?? null, $filters['color_id'] ?? null, $page, $perPage);

        return Response::data($result['items'], self::paginationMeta($page, $perPage, $result['total']));
    }

    /** @return array<string, mixed> */
    private function present(int $id): array
    {
        $style = $this->findOrFail($id);
        $style['specs'] = StockCatalog::specValues($style);
        $style['colors'] = $this->colors->forStyles([$id], false)[$id] ?? [];
        $style['sets_in_stock'] = array_sum(array_map(
            static fn (array $color): int => $color['is_active'] ? $color['sets_in_stock'] : 0,
            $style['colors'],
        ));

        return $style;
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->styles->find($id) ?? throw HttpException::notFound('This style does not exist.');
    }

    /** @param array<string, mixed> $style The style's address on the website. */
    private static function path(array $style): string
    {
        return "/stock/{$style['segment']}/{$style['slug']}";
    }

    private function hasColorWithPhoto(int $id): bool
    {
        foreach ($this->colors->forStyles([$id], true, 1)[$id] ?? [] as $color) {
            if ($color['images'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $before the listing being changed, or null for a new one
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?array $before): array
    {
        $id = $before === null ? null : (int) $before['id'];
        $input = $request->all();
        $data = Validator::make($input, [
            'name' => 'required|string|min:3|max:200',
            'slug' => 'nullable|slug|max:190',
            'style_no' => 'nullable|string|max:40',
            'segment' => 'required|in:' . implode(',', $this->departments->slugs()),
            'category' => 'nullable|in:' . implode(',', $this->categories->slugs()),
            'specs' => 'nullable|array',
            'description_html' => 'nullable|string|max:200000',
            'set_sizes' => 'required|array|max:20',
            // Per piece (owner decision 2026-11-09): the set's price is its pieces times this, up to Rs 50,000 a piece.
            'piece_price_cents' => 'required|int|min:100|max:5000000',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:320',
            'status' => 'nullable|in:' . implode(',', StockStyle::STATUSES),
            'is_featured' => 'nullable|bool',
            'sort_order' => 'nullable|int|min:0|max:1000000',
        ])->validate();

        $data += array_fill_keys(['style_no', 'seo_title', 'seo_description'], null);
        $data['status'] ??= 'draft';
        $data['is_featured'] ??= false;
        $data['sort_order'] ??= 0;

        if (($data['slug'] ?? null) ?: null) {
            if ($this->styles->slugExists($data['slug'], $id)) {
                throw new ValidationException(['slug' => 'Another stock style already uses this web address.']);
            }
        } else {
            // Made from the name, which listings of the same jeans in other colors share (e.g. a duplicate): the first free one.
            $data['slug'] = $this->freeSlug(Text::slug($data['name']), $id);
        }
        $data['description_html'] = $this->sanitizer->sanitize((string) ($data['description_html'] ?? ''));
        $data['category'] ??= $this->defaultCategory($before);
        $category = $data['category'] === null ? null : $this->categories->findBySlug($data['category']);
        if ($category === null) {
            throw new ValidationException(['category' => 'Add a stock category first (Stock categories).']);
        }
        $data['specs'] = StockStyle::cleanSpecs($input['specs'] ?? [], (string) $category['product_type']);
        $data['set_sizes'] = StockStyle::cleanSetSizes($input['set_sizes'] ?? []);

        return $data;
    }

    /**
     * A new listing's color with its photos and the sets in stock so far. Without a code (the add form has none), the
     * next free one is used.
     *
     * @return array{data: array<string, mixed>, photos: list<array{path: string, alt: string, width: int, height: int}>, sets: int}|null
     */
    private function firstColor(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $data = self::nested($input, ['code' => ['nullable', ...StockColor::CODE_RULES]] + StockColor::RULES + ['sets_in_stock' => 'nullable|int|min:0|max:1000000'], 'color');
        if (isset($data['code']) && $this->colors->codeExists($data['code'])) {
            throw new ValidationException(['color.code' => 'Another stock color already uses this code.']);
        }
        $data['code'] ??= $this->colors->nextCode();
        $photos = $this->images->photos($input['images'] ?? [], StockColor::MAX_PHOTOS, 'color.images');

        return [
            'data' => [
                'code' => $data['code'],
                'name' => $data['name'],
                'slug' => Text::slug($data['name'], 70) ?: 'color',
                'hex' => isset($data['hex']) ? strtolower($data['hex']) : null,
                'is_active' => true,
                'sort_order' => 10,
            ],
            'photos' => $photos,
            'sets' => $data['sets_in_stock'] ?? 0,
        ];
    }

    /**
     * The category of a listing saved without one (by a listing form from before categories): the one it has, or for a
     * new listing the jeans, as every listing was until then.
     *
     * @param array<string, mixed>|null $before
     */
    private function defaultCategory(?array $before): ?string
    {
        if ($before !== null) {
            return (string) $before['category'];
        }
        $category = $this->categories->firstOfType('jeans-pant') ?? ($this->categories->all()[0] ?? null);

        return $category === null ? null : (string) $category['slug'];
    }

    /** "mens-slim-jeans", or "mens-slim-jeans-2", "-3"… when another listing has it. */
    private function freeSlug(string $base, ?int $id): string
    {
        return Text::freeSlug($base, fn (string $slug): bool => $this->styles->slugExists($slug, $id));
    }
}
