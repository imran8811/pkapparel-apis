<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Inquiry;
use App\Services\InquiryNotifier;

/** POST /v1/inquiries: contact messages, quote requests and sample requests from the website. */
final class InquiryController
{
    private const MAX_PER_HOUR = 5;

    public function __construct(
        private readonly Inquiry $inquiries,
        private readonly RateLimiter $limiter,
        private readonly InquiryNotifier $notifier,
    ) {
    }

    public function store(Request $request): Response
    {
        $input = $request->all();

        // Honeypot: real visitors never see or fill this field. Pretend success for bots.
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return Response::data(['reference' => Inquiry::reference(0)], [], 201);
        }

        $data = Validator::make($input, [
            'type' => 'required|in:contact,quote,sample',
            'name' => 'required|string|min:2|max:120',
            'company' => 'nullable|string|max:160',
            'email' => 'required|email',
            'phone' => 'nullable|phone',
            'country' => 'nullable|country',
            'message' => 'required|string|min:10|max:5000',
            'source_url' => 'nullable|string|max:500',
            'details' => 'nullable|array',
        ])->validate();

        // Quotes and samples depend on the destination (shipping, duties).
        if ($data['type'] !== 'contact' && empty($data['country'])) {
            throw new ValidationException(['country' => 'This field is required.']);
        }

        $details = self::cleanDetails($data['details'] ?? []);

        $bucket = 'inquiry:' . $request->clientIp();
        if ($this->limiter->hit($bucket, self::MAX_PER_HOUR, 3600)) {
            throw HttpException::tooManyRequests($this->limiter->retryAfter($bucket, 3600));
        }

        $inquiry = [
            'type' => $data['type'],
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'country_code' => $data['country'] ?? null,
            'message' => $data['message'],
            'details' => $details,
            'source_url' => $data['source_url'] ?? null,
            'ip_address' => $request->clientIp(),
            'user_agent' => $request->userAgent(),
        ];
        $id = $this->inquiries->create($inquiry);
        $this->notifier->notify($id, $inquiry);

        return Response::data(['id' => $id, 'reference' => Inquiry::reference($id)], [], 201);
    }

    /**
     * Extra form fields (quantity, styles, destination…): short text values only.
     *
     * @param array<mixed> $details
     * @return array<string, string>
     */
    private static function cleanDetails(array $details): array
    {
        if (count($details) > 15) {
            throw new ValidationException(['details' => 'Too many extra fields.']);
        }

        $clean = [];
        foreach ($details as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || !is_scalar($value)) {
                throw new ValidationException(['details' => 'Some extra fields are not valid.']);
            }
            $text = trim((string) $value);
            if ($text !== '') {
                $clean[$key] = mb_substr($text, 0, 500);
            }
        }

        return $clean;
    }
}
