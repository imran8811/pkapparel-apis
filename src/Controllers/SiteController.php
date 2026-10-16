<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Settings;

final class SiteController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** GET /v1/site: public company info, contacts and business defaults for the website. */
    public function show(Request $request): Response
    {
        return Response::data($this->settings->public());
    }
}
