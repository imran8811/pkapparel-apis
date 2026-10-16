<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/** An error that should reach the client as a JSON error response with an HTTP status. */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $fields  Field-level messages (validation errors)
     * @param array<string, string> $headers Extra response headers (e.g. Allow, Retry-After)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function badRequest(string $message = 'The request is not valid.', string $code = 'bad_request'): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Please log in to continue.', string $code = 'unauthorized'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'You do not have access to this.', string $code = 'forbidden'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Not found.', string $code = 'not_found'): self
    {
        return new self(404, $code, $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'method_not_allowed', 'This method is not allowed here.', [], ['Allow' => implode(', ', $allowed)]);
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(
            429,
            'too_many_requests',
            'Too many attempts. Please wait a moment and try again.',
            [],
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }
}
