<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Product;
use App\Models\ProductColor;
use App\Services\ImageStore;
use App\Services\WebRevalidator;

/** Colors of a style with their photos (admin and editor). */
final class ProductColorController extends AdminController
{
    private const MAX_PHOTOS = 12;

    public function __construct(
        private readonly Product $products,
        private readonly ProductColor $colors,
        private readonly ImageStore $images,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** POST /v1/admin/products/{id}/colors */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $product = $this->products->find($this->id($request)) ?? throw HttpException::notFound('This style does not exist.');
        [$data, $photos] = $this->validated($request, $product['id'], null);
        $data['sort_order'] ??= $this->colors->nextSortOrder($product['id']);

        $id = $this->colors->create($product['id'], $data, $photos ?? []);
        $this->saved($product);

        return Response::data($this->colors->forProducts([$product['id']], false)[$product['id']][$this->index($product['id'], $id)], [], 201);
    }

    /** PUT /v1/admin/product-colors/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $color = $this->colors->find($this->id($request)) ?? throw HttpException::notFound('This color does not exist.');
        $product = $this->products->find($color['product_id']) ?? throw HttpException::notFound('This style does not exist.');
        [$data, $photos] = $this->validated($request, $product['id'], $color['id']);
        if ($data['sort_order'] === null) {
            unset($data['sort_order']);
        }
        $this->guardLastShopColor($product, $color, $data['is_active'], $photos);

        $this->colors->update($color['id'], $product['id'], $data, $photos);
        $this->saved($product);

        return Response::data($this->colors->forProducts([$product['id']], false)[$product['id']][$this->index($product['id'], $color['id'])]);
    }

    /** DELETE /v1/admin/product-colors/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $color = $this->colors->find($this->id($request)) ?? throw HttpException::notFound('This color does not exist.');
        $product = $this->products->find($color['product_id']) ?? throw HttpException::notFound('This style does not exist.');
        $this->guardLastShopColor($product, $color, false, []);

        $this->colors->delete($color['id']);
        $this->saved($product);

        return Response::noContent();
    }

    /**
     * A style shown in the shop must keep at least one active color with a photo.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $color
     * @param list<array<string, mixed>>|null $photos the color's new photos (null = unchanged)
     */
    private function guardLastShopColor(array $product, array $color, bool $staysActive, ?array $photos): void
    {
        if ($product['status'] !== 'active' || ($staysActive && ($photos === null || $photos !== []))) {
            return;
        }
        foreach ($this->colors->forProducts([$product['id']], true, 1)[$product['id']] ?? [] as $other) {
            if ($other['id'] !== $color['id'] && $other['images'] !== []) {
                return;
            }
        }
        throw new ValidationException(['color' => 'This style is shown in the shop and needs at least one active color with a photo. Hide the style first, or add another color.']);
    }

    /** @param array<string, mixed> $product */
    private function saved(array $product): void
    {
        $this->revalidator->revalidate(['products', 'product:' . $product['slug']]);
    }

    private function index(int $productId, int $colorId): int
    {
        foreach ($this->colors->forProducts([$productId], false)[$productId] ?? [] as $index => $color) {
            if ($color['id'] === $colorId) {
                return $index;
            }
        }
        throw HttpException::notFound('This color does not exist.');
    }

    /** @return array{0: array<string, mixed>, 1: list<array{path: string, alt: string, width: int, height: int}>|null} */
    private function validated(Request $request, int $productId, ?int $colorId): array
    {
        $input = $request->all();
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/'],
            'name' => 'required|string|max:80',
            'hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'price_cents' => 'nullable|int|min:1|max:100000000',
            'spec_overrides' => 'nullable|array',
            'is_active' => 'nullable|bool',
            'sort_order' => 'nullable|int|min:0|max:1000000',
        ])->validate();

        if ($this->colors->codeExists($data['code'], $colorId)) {
            throw new ValidationException(['code' => 'Another color already uses this code.']);
        }
        $data['slug'] = $this->uniqueSlug($productId, $data['name'], $colorId);
        $data['hex'] = isset($data['hex']) ? strtolower($data['hex']) : null;
        $data['price_cents'] ??= null;
        $data['is_active'] ??= true;
        $data['sort_order'] ??= null;
        $data['spec_overrides'] = self::specs($data['spec_overrides'] ?? []);

        $photos = array_key_exists('images', $input) ? $this->photos($input['images']) : null;

        return [$data, $photos];
    }

    private function uniqueSlug(int $productId, string $name, ?int $colorId): string
    {
        $base = Text::slug($name, 70) ?: 'color';
        $slug = $base;
        for ($n = 2; $this->colors->slugExists($productId, $slug, $colorId); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** @return array<string, string> */
    private static function specs(mixed $specs): array
    {
        if (!is_array($specs)) {
            throw new ValidationException(['spec_overrides' => 'Must be a list of fabric details.']);
        }
        $clean = [];
        foreach (ProductColor::SPEC_KEYS as $key) {
            $value = $specs[$key] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            if (!is_string($value) || mb_strlen(trim($value)) > 120) {
                throw new ValidationException(["spec_overrides.{$key}" => 'Use at most 120 characters.']);
            }
            if ($key === 'stretch' && !in_array(trim($value), ['stretch', 'non-stretch'], true)) {
                throw new ValidationException(['spec_overrides.stretch' => 'Choose stretch or non-stretch.']);
            }
            $clean[$key] = trim($value);
        }

        return $clean;
    }

    /** @return list<array{path: string, alt: string, width: int, height: int}> */
    private function photos(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_PHOTOS) {
            throw new ValidationException(['images' => 'Add at most ' . self::MAX_PHOTOS . ' photos.']);
        }
        $photos = [];
        foreach ($items as $index => $item) {
            $photo = Validator::make(is_array($item) ? $item : [], ['path' => 'required|string|max:255', 'alt' => 'required|string|max:200']);
            if (!$photo->passes()) {
                $fields = [];
                foreach ($photo->errors() as $name => $message) {
                    $fields["images.{$index}.{$name}"] = $message;
                }
                throw new ValidationException($fields);
            }
            $values = $photo->validate();
            $size = $this->images->dimensions($values['path']);
            if ($size === null) {
                throw new ValidationException(["images.{$index}.path" => 'This photo was not found. Upload it again.']);
            }
            $photos[] = ['path' => $values['path'], 'alt' => $values['alt']] + $size;
        }

        return $photos;
    }
}
