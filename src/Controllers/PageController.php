<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Page;

final class PageController
{
    public function __construct(private readonly Page $pages)
    {
    }

    /** GET /v1/pages/{slug} */
    public function show(Request $request): Response
    {
        $page = $this->pages->findPublished((string) $request->param('slug'));
        if ($page === null) {
            throw HttpException::notFound('This page does not exist.');
        }

        return Response::data($page);
    }
}
