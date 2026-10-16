<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HtmlSanitizer;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Page;
use App\Services\WebRevalidator;

/** Legal and other simple pages (admin and editor). */
final class PageController extends AdminController
{
    public function __construct(
        private readonly Page $pages,
        private readonly HtmlSanitizer $sanitizer,
        private readonly WebRevalidator $revalidator,
    ) {
    }

    /** GET /v1/admin/pages */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->pages->all());
    }

    /** GET /v1/admin/pages/{slug} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->findOrFail((string) $request->param('slug')));
    }

    /** PUT /v1/admin/pages/{slug} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $slug = (string) $request->param('slug');
        $this->findOrFail($slug);

        $data = Validator::make($request->all(), [
            'title' => 'required|string|min:2|max:200',
            'body_html' => 'required|string|max:1000000',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:320',
            'is_published' => 'required|bool',
        ])->validate();

        $data['body_html'] = $this->sanitizer->sanitize($data['body_html']);
        if (trim(strip_tags($data['body_html'])) === '') {
            throw new ValidationException(['body_html' => 'The page cannot be empty.']);
        }

        $this->pages->update($slug, $data + ['seo_title' => null, 'seo_description' => null]);
        $this->revalidator->revalidate(['pages', 'page:' . $slug]);

        return Response::data($this->findOrFail($slug));
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $slug): array
    {
        return $this->pages->findBySlug($slug) ?? throw HttpException::notFound('This page does not exist.');
    }
}
