<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Redirect;
use App\Services\Redirects;

/** The redirects the website follows, and the missing addresses its "page not found" page reports. */
final class RedirectController
{
    /** Reports per visitor and hour; more are silently dropped. */
    private const MAX_REPORTS_PER_HOUR = 30;

    public function __construct(private readonly Redirect $redirects, private readonly Redirects $service, private readonly RateLimiter $limiter)
    {
    }

    /** GET /v1/redirects */
    public function index(Request $request): Response
    {
        return Response::data($this->redirects->map());
    }

    /** POST /v1/missing-pages {path, referrer?} */
    public function missing(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'path' => 'required|string|max:2000',
            'referrer' => 'nullable|string|max:2000',
        ])->validate();
        if (!$this->limiter->hit('missing-page:' . $request->clientIp(), self::MAX_REPORTS_PER_HOUR, 3600)) {
            $this->service->recordMissing((string) $data['path'], $data['referrer'] ?? null);
        }

        return Response::noContent();
    }
}
