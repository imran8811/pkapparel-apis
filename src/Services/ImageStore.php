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

        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size === 0) {
            throw new ValidationException(['file' => 'Choose an image to upload.']);
        }
        if ($size > self::MAX_BYTES) {
            throw new ValidationException(['file' => 'The image is too large. The limit is 10 MB.']);
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

        $extension = $transparent ? 'png' : 'jpg';
        $relative = sprintf(
            'uploads/%s/%s/%s-%s.%s',
            self::FOLDERS[$folder],
            gmdate('Y/m'),
            self::baseName($originalName),
            bin2hex(random_bytes(3)),
            $extension,
        );
        $target = $this->publicDir() . '/' . $relative;
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

    private static function resize(GdImage $image, bool $keepTransparency): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
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
