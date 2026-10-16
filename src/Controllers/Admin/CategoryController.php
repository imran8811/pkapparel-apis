<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HtmlSanitizer;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Category;
use App\Services\WebRevalidator;

/** Texts of the shop's segment and category pages (admin and editor). Pages are fixed; only their text changes. */
final class CategoryController extends AdminController
{
    private const MAX_FAQ = 10;

    public function __construct(
        private readonly Category $categories,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** GET /v1/admin/categories */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->categories->all());
    }

    /** PUT /v1/admin/categories/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        if ($this->categories->find($id) === null) {
            throw HttpException::notFound('This category does not exist.');
        }

        $input = $request->all();
        $data = Validator::make($input, [
            'title' => 'required|string|max:160',
            'intro_html' => 'nullable|string|max:100000',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:320',
        ])->validate();

        $faq = $input['faq'] ?? [];
        if (!is_array($faq) || !array_is_list($faq) || count($faq) > self::MAX_FAQ) {
            throw new ValidationException(['faq' => 'Add at most ' . self::MAX_FAQ . ' questions.']);
        }
        $questions = [];
        foreach ($faq as $index => $item) {
            $entry = Validator::make(is_array($item) ? $item : [], [
                'question' => 'required|string|max:300',
                'answer' => 'required|string|max:2000',
            ]);
            if (!$entry->passes()) {
                $fields = [];
                foreach ($entry->errors() as $name => $message) {
                    $fields["faq.{$index}.{$name}"] = $message;
                }
                throw new ValidationException($fields);
            }
            $questions[] = $entry->validate();
        }

        $this->categories->update($id, [
            'title' => $data['title'],
            'intro_html' => $this->sanitizer->sanitize((string) ($data['intro_html'] ?? '')),
            'faq' => $questions,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ]);
        $this->revalidator->revalidate(['categories']);

        return Response::data($this->categories->find($id));
    }
}
