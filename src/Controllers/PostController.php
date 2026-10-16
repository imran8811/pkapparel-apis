<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Post;

final class PostController
{
    public function __construct(private readonly Post $posts)
    {
    }

    /** GET /v1/posts?page=1&per_page=12 */
    public function index(Request $request): Response
    {
        $query = Validator::make($request->allQuery(), [
            'page' => 'nullable|int|min:1|max:1000',
            'per_page' => 'nullable|int|min:1|max:100',
        ])->validate();
        $page = $query['page'] ?? 1;
        $perPage = $query['per_page'] ?? 12;

        $result = $this->posts->published($page, $perPage);

        return Response::data($result['items'], [
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /** GET /v1/posts/{slug} */
    public function show(Request $request): Response
    {
        $post = $this->posts->findPublished((string) $request->param('slug'));
        if ($post === null) {
            throw HttpException::notFound('This article does not exist.');
        }

        return Response::data($post);
    }
}
