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
use App\Models\Post;
use App\Services\ImageStore;
use App\Services\Redirects;
use App\Services\WebRevalidator;

/** Blog posts (admin and editor). */
final class PostController extends AdminController
{
    public function __construct(
        private readonly Post $posts,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ImageStore $images,
        private readonly WebRevalidator $revalidator,
        private readonly Redirects $redirects,
    ) {
    }

    /** GET /v1/admin/posts?status=draft&q=…&page=1 */
    public function index(Request $request): Response
    {
        $this->authorize($request, 'editor');
        [$page, $perPage] = $this->pagination($request, 25);
        $filters = Validator::make($request->allQuery(), [
            'status' => 'nullable|in:' . implode(',', Post::STATUSES),
            'q' => 'nullable|string|max:120',
        ])->validate();

        $result = $this->posts->adminList($filters['status'] ?? null, $filters['q'] ?? null, $page, $perPage);

        return Response::data($result['items'], self::paginationMeta($page, $perPage, $result['total']) + ['counts' => $this->posts->counts()]);
    }

    /** GET /v1/admin/posts/{id} */
    public function show(Request $request): Response
    {
        $this->authorize($request, 'editor');

        return Response::data($this->findOrFail($this->id($request)));
    }

    /** POST /v1/admin/posts */
    public function store(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $data = $this->validated($request, null);

        $id = $this->posts->create($data);
        $this->revalidator->revalidate(['posts', 'post:' . $data['slug']]);

        return Response::data($this->findOrFail($id), [], 201);
    }

    /** PUT /v1/admin/posts/{id} */
    public function update(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $before = $this->findOrFail($id);
        $data = $this->validated($request, $id);

        $this->posts->update($id, $data);
        // A post readers could already open keeps its old address working.
        if ($before['visibility'] === 'published') {
            $this->redirects->moved('/post/' . $before['slug'], '/post/' . $data['slug'], 'post');
        }
        $this->revalidator->revalidate(array_values(array_unique(['posts', 'post:' . $before['slug'], 'post:' . $data['slug']])));

        return Response::data($this->findOrFail($id));
    }

    /** DELETE /v1/admin/posts/{id} */
    public function destroy(Request $request): Response
    {
        $this->authorize($request, 'editor');
        $id = $this->id($request);
        $post = $this->findOrFail($id);

        $this->posts->delete($id);
        $this->revalidator->revalidate(['posts', 'post:' . $post['slug']]);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id): array
    {
        $data = Validator::make($request->all(), [
            'title' => 'required|string|min:3|max:200',
            'slug' => 'nullable|slug|max:190',
            'excerpt' => 'nullable|string|max:500',
            'body_html' => 'required|string|max:1000000',
            'cover_image_path' => 'nullable|string|max:255',
            'cover_image_alt' => 'nullable|string|max:200',
            'author_name' => 'nullable|string|max:120',
            'status' => 'required|in:' . implode(',', Post::STATUSES),
            'published_at' => 'nullable|datetime',
            'seo_title' => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:320',
        ])->validate();

        $data['slug'] = ($data['slug'] ?? null) ?: Text::slug($data['title']);
        if ($data['slug'] === '') {
            throw new ValidationException(['slug' => 'Enter a web address for this post.']);
        }
        if ($this->posts->slugExists($data['slug'], $id)) {
            throw new ValidationException(['slug' => 'Another post already uses this web address.']);
        }

        $data['body_html'] = $this->sanitizer->sanitize($data['body_html']);
        if (trim(strip_tags($data['body_html'], '<img>')) === '') {
            throw new ValidationException(['body_html' => 'Write the article before saving.']);
        }

        $data['cover_image_path'] ??= null;
        if ($data['cover_image_path'] !== null && !$this->images->exists($data['cover_image_path'])) {
            throw new ValidationException(['cover_image_path' => 'Upload the cover image again.']);
        }

        $data['excerpt'] = ($data['excerpt'] ?? null) ?: Text::excerpt($data['body_html']);
        $data['author_name'] = ($data['author_name'] ?? null) ?: 'PK Apparel';
        // Publishing without a date publishes now; a future date schedules the post.
        if ($data['status'] === 'published' && empty($data['published_at'])) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }

        return $data + ['cover_image_alt' => null, 'published_at' => null, 'seo_title' => null, 'seo_description' => null];
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id): array
    {
        return $this->posts->find($id) ?? throw HttpException::notFound('This post does not exist.');
    }
}
