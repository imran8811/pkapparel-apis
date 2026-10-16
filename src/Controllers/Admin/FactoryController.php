<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\FactoryDepartment;
use App\Services\ImageStore;
use App\Services\WebRevalidator;

/** Factory departments with their photos and numbers (admin and editor). */
final class FactoryController extends AdminController
{
    private const MAX_STATS = 6;

    private const MAX_IMAGES = 12;

    public function __construct(
        private readonly FactoryDepartment $departments,
        private readonly ImageStore $images,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** GET /v1/admin/factory/departments */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->departments->all());
    }

    /** GET /v1/admin/factory/departments/{slug} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->findOrFail((string) $request->param('slug')));
    }

    /** PUT /v1/admin/factory/departments/{slug} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $slug = (string) $request->param('slug');
        $this->findOrFail($slug);

        $input = $request->all();
        $data = Validator::make($input, [
            'name' => 'required|string|max:120',
            'summary' => 'required|string|min:10|max:2000',
            'sort_order' => 'required|int|min:0|max:1000000',
            'is_published' => 'required|bool',
        ])->validate();
        $data['stats'] = $this->stats($input['stats'] ?? []);
        $data['images'] = $this->photos($input['images'] ?? []);

        $this->departments->update($slug, $data);
        $this->revalidator->revalidate(['factory']);

        return Response::data($this->findOrFail($slug));
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function stats(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_STATS) {
            throw new ValidationException(['stats' => 'Add at most ' . self::MAX_STATS . ' numbers.']);
        }

        $stats = [];
        foreach ($items as $index => $item) {
            $stats[] = $this->item($item, "stats.{$index}", ['label' => 'required|string|max:40', 'value' => 'required|string|max:40']);
        }

        return $stats;
    }

    /**
     * @return list<array{path: string, alt: string}>
     */
    private function photos(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_IMAGES) {
            throw new ValidationException(['images' => 'Add at most ' . self::MAX_IMAGES . ' photos.']);
        }

        $photos = [];
        foreach ($items as $index => $item) {
            $photo = $this->item($item, "images.{$index}", ['path' => 'required|string|max:255', 'alt' => 'required|string|max:200']);
            if (!$this->images->exists($photo['path'])) {
                throw new ValidationException(["images.{$index}.path" => 'This photo was not found. Upload it again.']);
            }
            $photos[] = $photo;
        }

        return $photos;
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    private function item(mixed $item, string $prefix, array $rules): array
    {
        $validator = Validator::make(is_array($item) ? $item : [], $rules);
        if (!$validator->passes()) {
            $fields = [];
            foreach ($validator->errors() as $field => $message) {
                $fields["{$prefix}.{$field}"] = $message;
            }
            throw new ValidationException($fields);
        }

        return $validator->validate();
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $slug): array
    {
        return $this->departments->findBySlug($slug) ?? throw HttpException::notFound('This department does not exist.');
    }
}
