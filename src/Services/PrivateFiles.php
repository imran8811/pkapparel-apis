<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;

/**
 * Private uploads under storage/private, never served directly: payment slips, order documents,
 * production photos and files buyers attach to requests (inquiries). Photos are re-encoded (ImageStore);
 * PDFs and Office files are checked by their content.
 * The website accepts at most 4.5 MB per request (Vercel), hence the 4 MB limit.
 */
final class PrivateFiles
{
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const FOLDERS = ['payment-slips', 'documents', 'production', 'inquiries'];

    /** Office files buyers may attach: extension => [the package's main part, MIME type]. */
    private const OFFICE = [
        'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    public function __construct(private readonly Config $config, private readonly ImageStore $images)
    {
    }

    /**
     * Stores an uploaded photo or PDF.
     *
     * @param array<string, mixed>|null $upload one entry of $_FILES, or null when nothing was attached
     * @param string $field the form field, for error messages
     * @return array{path: string, name: string, type: string, size: int}|null null when no file was attached
     */
    public function store(?array $upload, string $folder, string $field, bool $allowPdf = true): ?array
    {
        $file = $this->checkedUpload($upload, $folder, $field);
        if ($file === null) {
            return null;
        }
        [$tmp, $name] = $file;

        if ($allowPdf && self::isPdf($tmp)) {
            return $this->copy($tmp, $name, $folder, 'pdf', 'application/pdf');
        }

        return $this->storeImage($tmp, $name, $folder, $field, $allowPdf ? 'Attach a photo (JPG or PNG) or a PDF.' : 'Attach a photo (JPG or PNG).');
    }

    /**
     * Stores a file a buyer attaches to a request: a PDF (Illustrator files saved with PDF content count too),
     * an Excel or Word file (.xlsx, .docx; files with macros are refused), or a photo.
     *
     * @param array<string, mixed>|null $upload one entry of $_FILES, or null when nothing was attached
     * @return array{path: string, name: string, type: string, size: int}|null null when no file was attached
     */
    public function storeAttachment(?array $upload, string $folder, string $field): ?array
    {
        $file = $this->checkedUpload($upload, $folder, $field);
        if ($file === null) {
            return null;
        }
        [$tmp, $name] = $file;

        if (self::isPdf($tmp)) {
            return $this->copy($tmp, $name, $folder, 'pdf', 'application/pdf');
        }
        $office = self::officeType($tmp);
        if ($office !== null) {
            return $this->copy($tmp, $name, $folder, $office, self::OFFICE[$office][1]);
        }

        return $this->storeImage($tmp, $name, $folder, $field, 'Attach a PDF, Excel, Word or Illustrator file, or a photo (JPG or PNG).');
    }

    /**
     * Stores an uploaded photo with a small preview copy (for galleries).
     *
     * @param array<string, mixed>|null $upload
     * @return array{path: string, preview_path: string, width: int, height: int}
     */
    public function storePhoto(?array $upload, string $folder, string $field): array
    {
        $file = $this->checkedUpload($upload, $folder, $field);
        if ($file === null) {
            throw new ValidationException([$field => 'Choose a photo to upload.']);
        }
        try {
            $image = $this->images->storePrivate($file[0], self::prefix($folder), $folder, self::MAX_BYTES);
        } catch (ValidationException) {
            throw new ValidationException([$field => 'Upload a photo (JPG or PNG).']);
        }

        return [
            'path' => $image['path'],
            'preview_path' => $this->images->privatePreview($image['path'], 640),
            'width' => $image['width'],
            'height' => $image['height'],
        ];
    }

    /** @return string|null The file's content, or null when the path is not a private file or is missing. */
    public function read(string $path): ?string
    {
        $file = $this->resolve($path);

        return $file !== null && is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function delete(?string $path): void
    {
        $file = $path === null ? null : $this->resolve($path);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    public static function mimeFor(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'xlsx', 'docx' => self::OFFICE[$extension][1],
            default => 'image/jpeg',
        };
    }

    /**
     * Copies a checked file (PDF or Office) into the folder under a random name.
     *
     * @return array{path: string, name: string, type: string, size: int}
     */
    private function copy(string $tmp, string $name, string $folder, string $extension, string $type): array
    {
        $relative = sprintf('%s/%s/%s-%s.%s', $folder, gmdate('Y/m'), self::prefix($folder), bin2hex(random_bytes(12)), $extension);
        $target = $this->baseDir() . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Could not create the folder ' . dirname($target));
        }
        if (!copy($tmp, $target)) {
            throw new \RuntimeException('Could not save the file to ' . $target);
        }

        return ['path' => $relative, 'name' => $name, 'type' => $type, 'size' => (int) filesize($target)];
    }

    /**
     * Re-encodes a photo into the folder; anything that is not a photo gets this error message.
     *
     * @return array{path: string, name: string, type: string, size: int}
     */
    private function storeImage(string $tmp, string $name, string $folder, string $field, string $error): array
    {
        try {
            $image = $this->images->storePrivate($tmp, self::prefix($folder), $folder, self::MAX_BYTES);
        } catch (ValidationException) {
            throw new ValidationException([$field => $error]);
        }

        return [
            'path' => $image['path'],
            'name' => $name,
            'type' => str_ends_with($image['path'], '.png') ? 'image/png' : 'image/jpeg',
            'size' => $image['size'],
        ];
    }

    /**
     * "xlsx" or "docx" when the file is such an Office package (a zip with the matching main part and no macros).
     * Office files need PHP's zip extension to be checked; without it they are refused.
     */
    private static function officeType(string $file): ?string
    {
        $handle = fopen($file, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 4);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($head !== "PK\x03\x04" || !class_exists(\ZipArchive::class)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return null;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), 'vbaproject.bin')) {
                    return null; // macros
                }
            }
            foreach (self::OFFICE as $extension => [$mainPart]) {
                if ($zip->locateName($mainPart) !== false) {
                    return $extension;
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<string, mixed>|null $upload
     * @return array{0: string, 1: string}|null [temporary file, original name], or null when nothing was attached
     */
    private function checkedUpload(?array $upload, string $folder, string $field): ?array
    {
        if (!in_array($folder, self::FOLDERS, true)) {
            throw new \InvalidArgumentException("Unknown private folder [{$folder}].");
        }
        if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
            throw new ValidationException([$field => match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large. The limit is 4 MB.',
                default => 'The upload did not finish. Please try again.',
            }]);
        }
        // Real uploads must come from PHP's upload handling (tests run on the command line).
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($upload['tmp_name'])) {
            throw HttpException::badRequest('The upload is not valid.');
        }
        $size = (int) filesize($upload['tmp_name']);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new ValidationException([$field => $size === 0 ? 'The file is empty.' : 'The file is too large. The limit is 4 MB.']);
        }

        return [$upload['tmp_name'], mb_substr(basename((string) ($upload['name'] ?? 'file')), 0, 190)];
    }

    private static function isPdf(string $file): bool
    {
        $handle = fopen($file, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }

        return $head === '%PDF-';
    }

    /** "payment-slips" → "slip": the start of the stored file names. */
    private static function prefix(string $folder): string
    {
        return match ($folder) {
            'payment-slips' => 'slip',
            'documents' => 'doc',
            'inquiries' => 'file',
            default => 'photo',
        };
    }

    private function resolve(string $path): ?string
    {
        $folders = implode('|', self::FOLDERS);
        if (preg_match('#^(?:' . $folders . ')/\d{4}/\d{2}/[a-z0-9-]+\.(?:pdf|jpg|png|xlsx|docx)$#', $path) !== 1) {
            return null;
        }

        return $this->baseDir() . '/' . $path;
    }

    private function baseDir(): string
    {
        return rtrim($this->config->string('paths.storage'), '/\\') . '/private';
    }
}
