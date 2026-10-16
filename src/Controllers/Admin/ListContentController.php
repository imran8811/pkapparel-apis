<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Repository;
use App\Services\WebRevalidator;

/**
 * List, add, edit and delete rows of a simple content list (FAQs, reviews, certificates).
 * Admins and editors only. After every change the website refreshes the matching cache tag.
 */
abstract class ListContentController extends AdminController
{
    public function __construct(private readonly Repository $items, private readonly WebRevalidator $revalidator)
    {
    }

    /** @return array<string, string> Validation rules for one row (without sort_order / is_published). */
    abstract protected function rules(): array;

    /** The website cache tag to refresh, e.g. "faqs". */
    abstract protected function cacheTag(): string;

    /** What to call one row in messages, e.g. "FAQ". */
    abstract protected function label(): string;

    /**
     * Extra checks or clean-up after validation.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function prepare(array $data): array
    {
        return $data;
    }

    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->items->all());
    }

    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->validated($request);
        $data['is_published'] ??= true;

        $id = $this->items->create($data);
        $this->revalidator->revalidate([$this->cacheTag()]);

        return Response::data($this->items->find($id), [], 201);
    }

    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->findOrFail($request);
        $data = $this->validated($request);
        if ($data['sort_order'] === null) {
            unset($data['sort_order']);
        }
        if ($data['is_published'] === null) {
            unset($data['is_published']);
        }

        $this->items->updateById($id, $data);
        $this->revalidator->revalidate([$this->cacheTag()]);

        return Response::data($this->items->find($id));
    }

    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $this->items->delete($this->findOrFail($request));
        $this->revalidator->revalidate([$this->cacheTag()]);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = Validator::make($request->all(), $this->rules() + [
            'sort_order' => 'nullable|int|min:0|max:1000000',
            'is_published' => 'nullable|bool',
        ])->validate();
        $data += ['sort_order' => null, 'is_published' => null];

        return $this->prepare($data);
    }

    private function findOrFail(Request $request): int
    {
        $id = $this->id($request);
        if ($this->items->find($id) === null) {
            throw HttpException::notFound("This {$this->label()} does not exist.");
        }

        return $id;
    }
}
