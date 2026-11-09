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
use App\Models\ProductTypes;
use App\Models\StockColor;
use App\Models\StockMovement;
use App\Models\StockStyle;
use App\Services\Redirects;
use App\Services\StockCatalog;
use App\Services\WebRevalidator;

/**
 * Stock styles (admin and editor): ready jeans sold by set. Colors, their photos and their stock are saved by
 * StockColorController.
 */
final class StockStyleController extends AdminController
{
    public function __construct(
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
        private readonly StockMovement $movements,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WebRevalidator $revalidator,
        private readonly Redirects $redirects,
    ) {
    }

    /** GET /v1/admin/stock/styles?status=active&q=…&page=1 */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 50);
        $filters = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:' . implode(',', StockStyle::STATUSES),
            'q' => 'nullable|string|max:80',
        ])->validate();

        $result = $this->styles->adminList($filters['status'] ?? null, $filters['q'] ?? null, $page, $perPage);
        $items = array_map(static fn (array $s): array => [
            'id' => $s['id'],
            'slug' => $s['slug'],
            'name' => $s['name'],
            'style_no' => $s['style_no'],
            'segment' => $s['segment'],
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

    /** POST /v1/admin/stock/styles: new styles start as drafts until they have a color with photos. */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->validated($request, null);
        $data['status'] = 'draft';

        $id = $this->styles->create($data);
        $this->revalidator->revalidate(['stock']);

        return Response::data($this->present($id), [], 201);
    }

    /** PUT /v1/admin/stock/styles/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $before = $this->findOrFail($id);
        $data = $this->validated($request, $id);

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

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id): array
    {
        $input = $request->all();
        $data = Validator::make($input, [
            'name' => 'required|string|min:3|max:200',
            'slug' => 'nullable|slug|max:190',
            'style_no' => 'nullable|string|max:40',
            'segment' => 'required|in:' . implode(',', StockStyle::SEGMENTS),
            'specs' => 'nullable|array',
            'description_html' => 'nullable|string|max:200000',
            'set_sizes' => 'required|array|max:20',
            'set_price_cents' => 'required|int|min:100|max:1000000000',
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

        $data['slug'] = ($data['slug'] ?? null) ?: Text::slug($data['name']);
        if ($this->styles->slugExists($data['slug'], $id)) {
            throw new ValidationException(['slug' => 'Another stock style already uses this web address.']);
        }
        $data['description_html'] = $this->sanitizer->sanitize((string) ($data['description_html'] ?? ''));
        $data['specs'] = self::specs($input['specs'] ?? []);
        $data['set_sizes'] = self::setSizes($input['set_sizes'] ?? []);

        return $data;
    }

    /**
     * The jeans specs that are filled in, as {key: value}.
     *
     * @return array<string, string>
     */
    private static function specs(mixed $input): array
    {
        if (!is_array($input)) {
            throw new ValidationException(['specs' => 'Must be a list of details.']);
        }
        $specs = [];
        foreach (ProductTypes::specs(StockStyle::PRODUCT_TYPE) as $field) {
            $value = $input[$field['key']] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new ValidationException(["specs.{$field['key']}" => 'Must be text.']);
            }
            $value = $value === null ? '' : trim($value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $field['max']) {
                throw new ValidationException(["specs.{$field['key']}" => "Use at most {$field['max']} characters."]);
            }
            if ($field['key'] === 'stretch' && !in_array($value, ['stretch', 'non-stretch'], true)) {
                throw new ValidationException(['specs.stretch' => 'Choose stretch or non-stretch.']);
            }
            $specs[$field['key']] = $value;
        }

        return $specs;
    }

    /**
     * The set: waist sizes (inches) with the pieces of each, smallest size first.
     *
     * @return list<array{size: string, pcs: int}>
     */
    private static function setSizes(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw new ValidationException(['set_sizes' => 'Add the sizes of the set.']);
        }
        $sizes = [];
        foreach ($items as $item) {
            $size = is_array($item) ? trim((string) ($item['size'] ?? '')) : '';
            $pcs = is_array($item) ? ($item['pcs'] ?? null) : null;
            if (!preg_match('/^\d{2}$/', $size) || (int) $size < StockStyle::SIZE_MIN || (int) $size > StockStyle::SIZE_MAX) {
                throw new ValidationException(['set_sizes' => sprintf('Sizes are waist sizes from %d to %d.', StockStyle::SIZE_MIN, StockStyle::SIZE_MAX)]);
            }
            if (!is_int($pcs) || $pcs < 1 || $pcs > StockStyle::MAX_PCS_PER_SIZE) {
                throw new ValidationException(['set_sizes' => sprintf('Each size needs 1 to %d pieces per set.', StockStyle::MAX_PCS_PER_SIZE)]);
            }
            if (isset($sizes[$size])) {
                throw new ValidationException(['set_sizes' => 'Each size can only be listed once.']);
            }
            $sizes[$size] = $pcs;
        }
        ksort($sizes, SORT_NUMERIC);

        return array_map(static fn (int|string $size, int $pcs): array => ['size' => (string) $size, 'pcs' => $pcs], array_keys($sizes), $sizes);
    }
}
