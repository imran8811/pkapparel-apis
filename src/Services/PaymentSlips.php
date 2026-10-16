<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Transfer slips buyers attach on the Confirm payment page: private files under
 * storage/private/payment-slips (see PrivateFiles), never public uploads.
 */
final class PaymentSlips
{
    public const MAX_BYTES = PrivateFiles::MAX_BYTES;

    public function __construct(private readonly PrivateFiles $files)
    {
    }

    /**
     * @param array<string, mixed>|null $upload one entry of $_FILES, or null when no slip was attached
     * @return array{path: string, name: string, type: string, size: int}|null
     */
    public function store(?array $upload): ?array
    {
        return $this->files->store($upload, 'payment-slips', 'slip');
    }

    /** @return string|null The file's content, or null when it is missing. */
    public function read(string $path): ?string
    {
        return str_starts_with($path, 'payment-slips/') ? $this->files->read($path) : null;
    }
}
