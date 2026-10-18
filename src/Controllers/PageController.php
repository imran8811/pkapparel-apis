<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Page;
use App\Services\ImageStore;

final class PageController
{
    public function __construct(private readonly Page $pages, private readonly ImageStore $images)
    {
    }

    /** GET /v1/pages/{slug} */
    public function show(Request $request): Response
    {
        $page = $this->pages->findPublished((string) $request->param('slug'));
        if ($page === null) {
            throw HttpException::notFound('This page does not exist.');
        }
        $page['body_html'] = $this->images->withSizes($page['body_html']);

        return Response::data($page);
    }
}
