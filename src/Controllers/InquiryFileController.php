<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\InquiryAttachments;

/** POST /v1/inquiry-files: one file for a quote request or a custom sample order (multipart: file). */
final class InquiryFileController
{
    private const MAX_PER_HOUR = 30;

    public function __construct(private readonly InquiryAttachments $attachments, private readonly RateLimiter $limiter)
    {
    }

    public function store(Request $request): Response
    {
        $bucket = 'inquiry-upload:' . $request->clientIp();
        if ($this->limiter->hit($bucket, self::MAX_PER_HOUR, 3600)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
        }
        $upload = $request->files()['file'] ?? null;

        return Response::data($this->attachments->upload(is_array($upload) ? $upload : null, $request->clientIp()), [], 201);
    }
}
