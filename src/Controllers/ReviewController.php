<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Review;
use App\Services\Reviews;

/**
 * The review page opened from the emailed link (no login): GET shows the order, POST saves the review.
 * Account\ReviewController does the same from the buyer's order page.
 */
final class ReviewController
{
    private const MAX_PER_HOUR = 10;

    public function __construct(private readonly Reviews $reviews, private readonly RateLimiter $limiter)
    {
    }

    /** GET /v1/reviews/{token} */
    public function show(Request $request): Response
    {
        return Response::data($this->reviews->forToken((string) $request->param('token')));
    }

    /** POST /v1/reviews/{token} {rating, body, show_as} */
    public function store(Request $request): Response
    {
        $bucket = 'review:' . $request->clientIp();
        if ($this->limiter->hit($bucket, self::MAX_PER_HOUR, 3600)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
        }
        $token = (string) $request->param('token');
        $this->reviews->submitByToken($token, self::input($request));

        return Response::data($this->reviews->forToken($token), [], 201);
    }

    /** @return array{rating: int, body: string, show_as: string} */
    public static function input(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'rating' => 'required|int|min:1|max:5',
            'body' => 'required|string|min:20|max:2000',
            'show_as' => 'required|in:' . implode(',', Review::SHOW_AS),
        ])->validate();

        return ['rating' => (int) $data['rating'], 'body' => trim((string) $data['body']), 'show_as' => (string) $data['show_as']];
    }
}
