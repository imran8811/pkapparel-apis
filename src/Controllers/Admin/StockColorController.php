<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\StockColor;
use App\Models\StockMovement;
use App\Models\StockStyle;
use App\Services\ImageStore;
use App\Services\StockLevels;
use App\Services\WebRevalidator;

/** Colors of a stock style with their photos, and changes to their sets in stock (admin and editor). */
final class StockColorController extends AdminController
{
    private const MAX_PHOTOS = 12;

    public function __construct(
        private readonly StockStyle $styles,
        private readonly StockColor $colors,
        private readonly StockLevels $levels,
        private readonly ImageStore $images,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** POST /v1/admin/stock/styles/{id}/colors: a new color, optionally with the sets received so far. */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $style = $this->styles->find($this->id($request)) ?? throw HttpException::notFound('This style does not exist.');
        [$data, $photos] = $this->validated($request, $style, null);
        $opening = Validator::make($request->all(), ['sets_in_stock' => 'nullable|int|min:0|max:1000000'])->validate()['sets_in_stock'] ?? 0;
        $data['sort_order'] ??= $this->colors->nextSortOrder($style['id']);

        $id = $this->colors->create($style['id'], $data, $photos ?? []);
        if ($opening > 0) {
            $this->levels->change($id, 'received', $opening, 'Stock when the color was added', $this->staff($request)['id']);
        }
        $this->saved($style);

        return Response::data($this->colorOf($style['id'], $id), [], 201);
    }

    /** PUT /v1/admin/stock/colors/{id}: name, code, swatch, photos and visibility (stock changes go through /stock). */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $color = $this->colors->find($this->id($request)) ?? throw HttpException::notFound('This color does not exist.');
        $style = $this->styles->find($color['style_id']) ?? throw HttpException::notFound('This style does not exist.');
        [$data, $photos] = $this->validated($request, $style, $color['id']);
        if ($data['sort_order'] === null) {
            unset($data['sort_order']);
        }
        $this->guardLastShownColor($style, $color, $data['is_active'], $photos);

        $this->colors->update($color['id'], $style['id'], $data, $photos);
        $this->saved($style);

        return Response::data($this->colorOf($style['id'], $color['id']));
    }

    /** DELETE /v1/admin/stock/colors/{id}: the color, its photos and its stock history. */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $color = $this->colors->find($this->id($request)) ?? throw HttpException::notFound('This color does not exist.');
        $style = $this->styles->find($color['style_id']) ?? throw HttpException::notFound('This style does not exist.');
        $this->guardLastShownColor($style, $color, false, []);

        $this->colors->delete($color['id']);
        $this->saved($style);

        return Response::noContent();
    }

    /**
     * POST /v1/admin/stock/colors/{id}/stock {reason, sets, note}: sets received, returned, sold or damaged,
     * or (reason "correction") the number of sets counted.
     */
    public function stock(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $color = $this->colors->find($this->id($request)) ?? throw HttpException::notFound('This color does not exist.');
        $style = $this->styles->find($color['style_id']) ?? throw HttpException::notFound('This style does not exist.');
        $data = Validator::make($request->all(), [
            'reason' => 'required|in:' . implode(',', array_keys(StockMovement::REASONS)),
            'sets' => 'required|int|min:0|max:1000000',
            'note' => 'nullable|string|max:500',
        ])->validate();
        $note = isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null;

        $this->levels->change($color['id'], $data['reason'], $data['sets'], $note, $this->staff($request)['id']);
        $this->saved($style);

        return Response::data($this->colorOf($style['id'], $color['id']));
    }

    /**
     * A style shown on the website must keep at least one active color with a photo.
     *
     * @param array<string, mixed> $style
     * @param array<string, mixed> $color
     * @param list<array<string, mixed>>|null $photos the color's new photos (null = unchanged)
     */
    private function guardLastShownColor(array $style, array $color, bool $staysActive, ?array $photos): void
    {
        if ($style['status'] !== 'active' || ($staysActive && ($photos === null || $photos !== []))) {
            return;
        }
        foreach ($this->colors->forStyles([$style['id']], true, 1)[$style['id']] ?? [] as $other) {
            if ($other['id'] !== $color['id'] && $other['images'] !== []) {
                return;
            }
        }
        throw new ValidationException(['color' => 'This style is shown on the website and needs at least one active color with a photo. Hide the style first, or add another color.']);
    }

    /** @param array<string, mixed> $style */
    private function saved(array $style): void
    {
        $this->styles->touch($style['id']);
        $this->revalidator->revalidate(['stock', 'stock:' . $style['slug']]);
    }

    /** @return array<string, mixed> The color as the admin lists it, with its photos. */
    private function colorOf(int $styleId, int $colorId): array
    {
        foreach ($this->colors->forStyles([$styleId], false)[$styleId] ?? [] as $color) {
            if ($color['id'] === $colorId) {
                return $color;
            }
        }
        throw HttpException::notFound('This color does not exist.');
    }

    /**
     * @param array<string, mixed> $style
     * @return array{0: array<string, mixed>, 1: list<array{path: string, alt: string, width: int, height: int}>|null}
     */
    private function validated(Request $request, array $style, ?int $colorId): array
    {
        $input = $request->all();
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/'],
            'name' => 'required|string|max:80',
            'hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => 'nullable|bool',
            'sort_order' => 'nullable|int|min:0|max:1000000',
        ])->validate();

        if ($this->colors->codeExists($data['code'], $colorId)) {
            throw new ValidationException(['code' => 'Another stock color already uses this code.']);
        }
        $data['slug'] = $this->uniqueSlug((int) $style['id'], $data['name'], $colorId);
        $data['hex'] = isset($data['hex']) ? strtolower($data['hex']) : null;
        $data['is_active'] ??= true;
        $data['sort_order'] ??= null;

        $photos = array_key_exists('images', $input) ? $this->photos($input['images']) : null;

        return [$data, $photos];
    }

    private function uniqueSlug(int $styleId, string $name, ?int $colorId): string
    {
        $base = Text::slug($name, 70) ?: 'color';
        $slug = $base;
        for ($n = 2; $this->colors->slugExists($styleId, $slug, $colorId); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
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
