<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Models\InquiryFile;

/**
 * Files buyers attach to quote requests and custom sample orders. Each file is uploaded on its own
 * (so several files never exceed the website's 4.5 MB request limit) and gets a token; the form
 * then sends the tokens. Uploads never sent with a form are deleted by the daily job.
 */
final class InquiryAttachments
{
    public function __construct(private readonly InquiryFile $files, private readonly PrivateFiles $storage)
    {
    }

    /**
     * Stores an upload and returns its token, the only way to refer to it until a form is sent.
     *
     * @param array<string, mixed>|null $upload one entry of $_FILES
     * @return array{token: string, name: string, size_bytes: int, mime: string}
     */
    public function upload(?array $upload, string $ip): array
    {
        $file = $this->storage->storeAttachment($upload, 'inquiries', 'file');
        if ($file === null) {
            throw new ValidationException(['file' => 'Choose a file to upload.']);
        }
        $token = bin2hex(random_bytes(32));
        $this->files->createPending(hash('sha256', $token), $file, $ip);

        return ['token' => $token, 'name' => $file['name'], 'size_bytes' => $file['size'], 'mime' => $file['type']];
    }

    /**
     * The uploads these tokens refer to. An unknown, used or expired token is an error on the form field.
     *
     * @param array<mixed> $tokens
     * @return list<array<string, mixed>>
     */
    public function resolve(array $tokens, string $field = 'files'): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, static fn (mixed $token): bool => is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1)));
        if ($tokens === []) {
            return [];
        }
        if (count($tokens) > InquiryFile::MAX_PER_REQUEST) {
            throw new ValidationException([$field => sprintf('Attach at most %d files.', InquiryFile::MAX_PER_REQUEST)]);
        }
        $files = $this->files->pending(array_map(static fn (string $token): string => hash('sha256', $token), $tokens));
        if (count($files) !== count($tokens)) {
            throw new ValidationException([$field => 'One of the files is no longer available. Please attach it again.']);
        }

        return $files;
    }

    /** @param list<array<string, mixed>> $files resolved uploads */
    public function attach(int $inquiryId, array $files): void
    {
        $this->files->attach($inquiryId, array_map(static fn (array $file): int => $file['id'], $files));
    }

    /**
     * Hands a resolved upload over to another owner (a sample order's documents): the upload row goes,
     * the file stays where it is.
     *
     * @param array<string, mixed> $file
     */
    public function release(array $file): void
    {
        $this->files->delete($file['id']);
    }

    /** @param array<string, mixed> $file */
    public function content(array $file): ?string
    {
        return $this->storage->read($file['path']);
    }

    /** Deletes uploads never sent with a form; returns how many. */
    public function deleteStale(): int
    {
        $stale = $this->files->stale();
        foreach ($stale as $file) {
            $this->storage->delete($file['path']);
            $this->files->delete($file['id']);
        }

        return count($stale);
    }
}
