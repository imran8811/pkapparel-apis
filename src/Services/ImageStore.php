<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Text;
use App\Core\ValidationException;
use GdImage;

/**
 * Saves uploaded images under public/uploads. Every image is decoded and re-encoded
 * with GD (which strips metadata and anything hidden in the file), turned upright
 * using its EXIF orientation, and scaled down to at most 2400 px on the long side.
 * Photos are stored as JPEG; images with transparency (logos) as PNG.
 */
final class ImageStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_SIDE = 2400;

    private const MAX_PIXELS = 50_000_000;

    /** Upload folder names the admin may use => path below public/uploads. */
    public const FOLDERS = [
        'blog' => 'blog',
        'pages' => 'pages',
        'products' => 'products',
        'factory' => 'site/factory',
        'certificates' => 'site/certificates',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{path: string, width: int, height: int, size: int} path is relative to public/, e.g. "uploads/blog/2026/10/denim-rolls-3fa2c1.jpg"
     * @throws ValidationException when the file is not a usable image
     */
    public function store(string $file, string $originalName, string $folder): array
    {
        if (!isset(self::FOLDERS[$folder])) {
            throw new ValidationException(['folder' => 'Unknown upload folder.']);
        }

        $relative = sprintf('uploads/%s/%s/%s-%s', self::FOLDERS[$folder], gmdate('Y/m'), self::baseName($originalName), bin2hex(random_bytes(3)));

        return $this->save($file, $this->publicDir(), $relative, self::MAX_BYTES);
    }

    /**
     * Saves an image as a private file under storage/private (e.g. payment slips), which is never served directly.
     *
     * @return array{path: string, width: int, height: int, size: int} path is relative to storage/private, e.g. "payment-slips/2026/10/slip-9f3a….jpg"
     * @throws ValidationException when the file is not a usable image
     */
    public function storePrivate(string $file, string $originalName, string $folder, int $maxBytes): array
    {
        if (preg_match('/^[a-z0-9-]+$/', $folder) !== 1) {
            throw new \InvalidArgumentException("Invalid private folder [{$folder}].");
        }
        $relative = sprintf('%s/%s/%s-%s', $folder, gmdate('Y/m'), self::baseName($originalName), bin2hex(random_bytes(8)));

        return $this->save($file, rtrim($this->config->string('paths.storage'), '/\\') . '/private', $relative, $maxBytes);
    }

    /**
     * A smaller JPEG copy of a private image stored by storePrivate() (for galleries), saved next to it as "…-preview.jpg".
     *
     * @return string the preview's path, relative to storage/private
     */
    public function privatePreview(string $relativePath, int $maxSide): string
    {
        $baseDir = rtrim($this->config->string('paths.storage'), '/\\') . '/private';
        $source = $baseDir . '/' . $relativePath;
        $image = str_ends_with($relativePath, '.png') ? @imagecreatefrompng($source) : @imagecreatefromjpeg($source);
        if (!$image instanceof GdImage) {
            throw new \RuntimeException('Could not read the image ' . $relativePath);
        }

        $preview = self::resize($image, false, $maxSide);
        $relative = (string) preg_replace('/\.(?:jpe?g|png)$/', '', $relativePath) . '-preview.jpg';
        if (!imagejpeg($preview, $baseDir . '/' . $relative, 80)) {
            throw new \RuntimeException('Could not save the preview ' . $relative);
        }

        return $relative;
    }

    /**
     * @param string $relative target path below $baseDir, without the extension (it depends on the image)
     * @return array{path: string, width: int, height: int, size: int}
     */
    private function save(string $file, string $baseDir, string $relative, int $maxBytes): array
    {
        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size === 0) {
            throw new ValidationException(['file' => 'Choose an image to upload.']);
        }
        if ($size > $maxBytes) {
            throw new ValidationException(['file' => sprintf('The image is too large. The limit is %d MB.', intdiv($maxBytes, 1024 * 1024))]);
        }

        $info = @getimagesize($file);
        $type = $info[2] ?? null;
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new ValidationException(['file' => 'Upload a JPG, PNG, WebP or GIF image.']);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ValidationException(['file' => 'The image dimensions are not supported (max 50 megapixels).']);
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            IMAGETYPE_GIF => @imagecreatefromgif($file),
        };
        if (!$image instanceof GdImage) {
            throw new ValidationException(['file' => 'The image could not be read. Try saving it again as JPG.']);
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::applyOrientation($image, $file);
        }

        $transparent = $type !== IMAGETYPE_JPEG && self::hasTransparency($image);
        $resized = self::resize($image, $transparent);

        $relative .= $transparent ? '.png' : '.jpg';
        $target = $baseDir . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Could not create the upload folder ' . dirname($target));
        }

        $saved = $transparent ? imagepng($resized, $target, 6) : imagejpeg($resized, $target, 85);
        if (!$saved) {
            throw new \RuntimeException('Could not save the image to ' . $target);
        }

        return ['path' => $relative, 'width' => imagesx($resized), 'height' => imagesy($resized), 'size' => (int) filesize($target)];
    }

    /** True when $path is an uploaded image that exists, e.g. "uploads/site/factory/cutting.jpg". */
    public function exists(?string $path): bool
    {
        if ($path === null || preg_match('#^uploads/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:jpe?g|png|webp|gif)$#', $path) !== 1) {
            return false;
        }
        if (str_contains($path, '..')) {
            return false;
        }

        return is_file($this->publicDir() . '/' . $path);
    }

    /** @return array{width: int, height: int}|null Pixel size of an uploaded image (null when it does not exist). */
    public function dimensions(string $path): ?array
    {
        if (!$this->exists($path)) {
            return null;
        }
        $info = @getimagesize($this->publicDir() . '/' . $path);

        return $info === false ? null : ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /** The API's public folder (uploads live in public/uploads). */
    private function publicDir(): string
    {
        return dirname(rtrim($this->config->string('paths.uploads'), '/\\'));
    }

    private static function applyOrientation(GdImage $image, string $file): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** Checks a small copy of the image for any transparent pixel. */
    private static function hasTransparency(GdImage $image): bool
    {
        $sample = imagecreatetruecolor(48, 48);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        imagefill($sample, 0, 0, imagecolorallocatealpha($sample, 0, 0, 0, 127));
        imagecopyresampled($sample, $image, 0, 0, 0, 0, 48, 48, imagesx($image), imagesy($image));

        for ($x = 0; $x < 48; $x++) {
            for ($y = 0; $y < 48; $y++) {
                if (((imagecolorat($sample, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function resize(GdImage $image, bool $keepTransparency, int $maxSide = self::MAX_SIDE): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        if ($keepTransparency) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // JPEG has no transparency
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    /** "IMG_2041 (1).JPG" → "img-2041-1" */
    private static function baseName(string $originalName): string
    {
        return Text::slug(pathinfo($originalName, PATHINFO_FILENAME), 60) ?: 'image';
    }
}
