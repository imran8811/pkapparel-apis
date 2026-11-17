<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\StockSection;
use App\Services\WebRevalidator;

/**
 * Stock departments and categories (admins and editors): list, add, rename, reorder and delete. A web address (slug)
 * is made from the name when none is given and never changes afterwards. One with listings cannot be deleted, nor the
 * last one. Every change refreshes the stock pages.
 */
abstract class StockSectionController extends AdminController
{
    public function __construct(private readonly StockSection $sections, private readonly WebRevalidator $revalidator)
    {
    }

    /** "department" or "category", in messages. */
    abstract protected function label(): string;

    /** @return array<string, string> Rules of its own fields, besides the name, web address and order. */
    protected function rules(): array
    {
        return [];
    }

    /** Why a new web address cannot be used (besides being taken), or null. */
    protected function slugProblem(string $slug): ?string
    {
        return null;
    }

    /**
     * Why this one cannot be deleted (besides having listings or being the last one), or null.
     *
     * @param array<string, mixed> $section
     */
    protected function deleteProblem(array $section): ?string
    {
        return null;
    }

    /** GET: every one in staff order, with its number of listings (`styles`). */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->sections->withCounts());
    }

    /** POST {name, slug?, sort_order?, …} */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->validated($request, null);

        $id = $this->sections->create($data);
        $this->revalidator->revalidate(['stock']);

        return Response::data($this->present($id), [], 201);
    }

    /** PUT {name, sort_order?, …}: the web address stays as it is. */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $this->findOrFail($id);
        $data = $this->validated($request, $id);

        $this->sections->updateById($id, $data);
        $this->revalidator->revalidate(['stock']);

        return Response::data($this->present($id));
    }

    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $section = $this->findOrFail($id);
        $listings = $this->sections->styleCount($section['slug']);
        $problem = match (true) {
            $listings > 0 => sprintf(
                '%s has %d stock listing%s. Move %s to another %s first.',
                $section['name'],
                $listings,
                $listings === 1 ? '' : 's',
                $listings === 1 ? 'it' : 'them',
                $this->label(),
            ),
            $this->sections->count() <= 1 => "The stock needs at least one {$this->label()}.",
            default => $this->deleteProblem($section),
        };
        if ($problem !== null) {
            throw new HttpException(409, 'in_use', $problem);
        }

        $this->sections->delete($id);
        $this->revalidator->revalidate(['stock']);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id): array
    {
        $slugMax = $this->sections::SLUG_MAX;
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:' . StockSection::NAME_MAX,
            'slug' => 'nullable|slug|max:' . $slugMax,
            'sort_order' => 'nullable|int|min:0|max:1000000',
        ] + $this->rules())->validate();

        if ($this->sections->nameExists($data['name'], $id)) {
            throw new ValidationException(['name' => "Another {$this->label()} is called {$data['name']}."]);
        }
        if ($id !== null) {
            unset($data['slug']);
            if (($data['sort_order'] ?? null) === null) {
                unset($data['sort_order']);
            }

            return $data;
        }

        $given = ($data['slug'] ?? null) ?: null;
        $slug = $given ?? Text::slug($data['name'], $slugMax);
        $field = $given === null ? 'name' : 'slug';
        if ($slug === '') {
            throw new ValidationException(['name' => 'The name needs some letters or digits for the web address.']);
        }
        if ($this->sections->slugExists($slug)) {
            throw new ValidationException([$field => "Another {$this->label()} has the web address “{$slug}”."]);
        }
        $problem = $this->slugProblem($slug);
        if ($problem !== null) {
            throw new ValidationException([$field => $problem]);
        }
        $data['slug'] = $slug;

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(int $id): array
    {
        $section = $this->findOrFail($id);
        $section['styles'] = $this->sections->styleCount($section['slug']);

        return $section;
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->sections->find($id) ?? throw HttpException::notFound("This {$this->label()} does not exist.");
    }
}
