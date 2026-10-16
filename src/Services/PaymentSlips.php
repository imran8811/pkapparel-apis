<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;

/**
 * Transfer slips buyers attach on the Confirm payment page. They are private files under
 * storage/private/payment-slips, never public uploads. Photos are re-encoded (ImageStore); PDFs are
 * checked by their signature. The website limits requests to 4.5 MB (Vercel), hence 4 MB.
 */
final class PaymentSlips
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const FOLDER = 'payment-slips';

    public function __construct(private readonly Config $config, private readonly ImageStore $images)
    {
    }

    /**
     * @param array<string, mixed>|null $upload one entry of $_FILES, or null when no slip was attached
     * @return array{path: string, name: string, type: string}|null
     */
    public function store(?array $upload): ?array
    {
        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
            throw new ValidationException(['slip' => match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large. The limit is 4 MB.',
                default => 'The upload did not finish. Please try again.',
            }]);
        }
        // Real uploads must come from PHP's upload handling (tests run on the command line).
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($upload['tmp_name'])) {
            throw HttpException::badRequest('The upload is not valid.');
        }

        $file = $upload['tmp_name'];
        $name = mb_substr(basename((string) ($upload['name'] ?? 'slip')), 0, 190);
        $size = (int) filesize($file);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new ValidationException(['slip' => $size === 0 ? 'The file is empty.' : 'The file is too large. The limit is 4 MB.']);
        }

        $handle = fopen($file, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($head === '%PDF-') {
            $relative = sprintf('%s/%s/slip-%s.pdf', self::FOLDER, gmdate('Y/m'), bin2hex(random_bytes(12)));
            $target = $this->storageDir() . '/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new \RuntimeException('Could not create the folder ' . dirname($target));
            }
            if (!copy($file, $target)) {
                throw new \RuntimeException('Could not save the slip to ' . $target);
            }

            return ['path' => $relative, 'name' => $name, 'type' => 'application/pdf'];
        }

        try {
            $image = $this->images->storePrivate($file, 'slip', self::FOLDER, self::MAX_BYTES);
        } catch (ValidationException) {
            throw new ValidationException(['slip' => 'Attach a photo (JPG or PNG) or a PDF of the transfer slip.']);
        }

        return ['path' => $image['path'], 'name' => $name, 'type' => str_ends_with($image['path'], '.png') ? 'image/png' : 'image/jpeg'];
    }

    /** @return string|null The file's content, or null when it is missing. */
    public function read(string $path): ?string
    {
        if (preg_match('#^payment-slips/\d{4}/\d{2}/[a-z0-9-]+\.(?:pdf|jpg|png)$#', $path) !== 1) {
            return null;
        }
        $file = $this->storageDir() . '/' . $path;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function storageDir(): string
    {
        return rtrim($this->config->string('paths.storage'), '/\\') . '/private';
    }
}
