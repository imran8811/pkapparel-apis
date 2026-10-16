<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Services\ImageStore;

/** ImageStore writes real files to public/uploads; every file made here is deleted again. */
final class ImageUploadTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $public = dirname($this->app->config()->string('paths.uploads'));
        foreach ($this->files as $file) {
            @unlink(str_starts_with($file, 'uploads/') ? $public . '/' . $file : $file);
        }
    }

    public function testLargePhotosAreResizedAndStoredAsJpeg(): void
    {
        $source = $this->makeImage(3000, 1500, false, 'png');
        $stored = $this->store()->store($source, 'Denim Rolls (1).PNG', 'blog');
        $this->files[] = $stored['path'];

        self::assertMatchesRegularExpression('#^uploads/blog/\d{4}/\d{2}/denim-rolls-1-[a-f0-9]{6}\.jpg$#', $stored['path']);
        self::assertSame([2400, 1200], [$stored['width'], $stored['height']]);
        self::assertTrue($this->store()->exists($stored['path']));
        self::assertSame(IMAGETYPE_JPEG, getimagesize(dirname($this->app->config()->string('paths.uploads')) . '/' . $stored['path'])[2]);
    }

    public function testTransparentImagesStayPng(): void
    {
        $stored = $this->store()->store($this->makeImage(400, 200, true, 'png'), 'logo.png', 'certificates');
        $this->files[] = $stored['path'];

        self::assertStringStartsWith('uploads/site/certificates/', $stored['path']);
        self::assertStringEndsWith('.png', $stored['path']);
    }

    public function testRejectsFilesThatAreNotImages(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'pk');
        file_put_contents($fake, '<?php echo "not an image";');
        $this->files[] = $fake;

        $this->expectException(ValidationException::class);
        $this->store()->store($fake, 'evil.php.jpg', 'blog');
    }

    public function testExistsOnlyAcceptsPathsInsideUploads(): void
    {
        $store = $this->store();
        self::assertTrue($store->exists('uploads/site/factory/cutting.jpg'));
        self::assertFalse($store->exists('uploads/../index.php'));
        self::assertFalse($store->exists('/uploads/site/factory/cutting.jpg'));
        self::assertFalse($store->exists('uploads/site/factory/missing.jpg'));
        self::assertFalse($store->exists(null));
    }

    private function store(): ImageStore
    {
        return $this->app->container->get(ImageStore::class);
    }

    private function makeImage(int $width, int $height, bool $transparent, string $type): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $color = $transparent ? imagecolorallocatealpha($image, 0, 72, 172, 100) : imagecolorallocate($image, 0, 72, 172);
        imagefill($image, 0, 0, $color);

        $file = tempnam(sys_get_temp_dir(), 'pk') . '.' . $type;
        imagepng($image, $file);
        $this->files[] = $file;

        return $file;
    }
}
