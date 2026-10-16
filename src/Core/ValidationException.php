<?php

declare(strict_types=1);

namespace App\Core;

final class ValidationException extends HttpException
{
    /** @param array<string, string> $fields */
    public function __construct(array $fields, string $message = 'Please check the highlighted fields.')
    {
        parent::__construct(422, 'validation_failed', $message, $fields);
    }
}
