<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getimagesize;

/**
 * Exercises LocalFilesystem against the real disk and a real ImageMagick
 * binary. Skips silently if ImageMagick is unavailable on the host.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('filesystem')]
final class LocalFilesystemResizeTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[Test]
    public function deleteRemovesAllVariantsOnDisk(): void
    {
        $source  = $this->writePng('source.png', 20, 20);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 18, 18, VariantFit::Contain),
            new Variant('hero', 120, 60, VariantFit::Cover),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        static::assertFileExists("{$this->tmpDir}/gallery/_variant/admin-thumb/photo.png");
        static::assertFileExists("{$this->tmpDir}/gallery/_variant/hero/photo.png");

        $backend->delete('gallery/photo.png');

        static::assertFileDoesNotExist("{$this->tmpDir}/gallery/photo.png");
        static::assertFileDoesNotExist("{$this->tmpDir}/gallery/_variant/admin-thumb/photo.png");
        static::assertFileDoesNotExist("{$this->tmpDir}/gallery/_variant/hero/photo.png");
    }

    #[Test]
    public function renameMovesAllVariantsOnDisk(): void
    {
        $source  = $this->writePng('source.png', 20, 20);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 18, 18, VariantFit::Contain),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');
        $backend->rename('gallery/photo.png', 'gallery/renamed.png');

        static::assertFileDoesNotExist("{$this->tmpDir}/gallery/photo.png");
        static::assertFileExists("{$this->tmpDir}/gallery/renamed.png");
        static::assertFileExists("{$this->tmpDir}/gallery/_variant/admin-thumb/renamed.png");
    }

    #[Test]
    public function storeCoverFitProducesExactDimensions(): void
    {
        $source  = $this->writePng('source.png', 80, 40);
        $backend = $this->backend(new VariantRegistry(
            new Variant('square', 20, 20, VariantFit::Cover),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        $info = getimagesize("{$this->tmpDir}/gallery/_variant/square/photo.png");
        static::assertIsArray($info);
        static::assertSame(20, $info[0]);
        static::assertSame(20, $info[1]);
    }

    #[Test]
    public function storeFillFitProducesExactDimensions(): void
    {
        $source  = $this->writePng('source.png', 80, 60);
        $backend = $this->backend(new VariantRegistry(
            new Variant('stretched', 10, 5, VariantFit::Fill),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        $info = getimagesize("{$this->tmpDir}/gallery/_variant/stretched/photo.png");
        static::assertIsArray($info);
        static::assertSame(10, $info[0]);
        static::assertSame(5, $info[1]);
    }

    #[Test]
    public function storeGeneratesThumbVariantOnDisk(): void
    {
        $source  = $this->writePng('source.png', 80, 60);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 18, 18, VariantFit::Contain),
        ));

        $entry = $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        static::assertSame('photo.png', $entry->name);
        static::assertFileExists("{$this->tmpDir}/gallery/photo.png");
        static::assertFileExists("{$this->tmpDir}/gallery/_variant/admin-thumb/photo.png");

        $variantInfo = getimagesize("{$this->tmpDir}/gallery/_variant/admin-thumb/photo.png");
        static::assertIsArray($variantInfo);
        static::assertLessThanOrEqual(18, $variantInfo[0]);
        static::assertLessThanOrEqual(18, $variantInfo[1]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }

    private function backend(VariantRegistry $variants): LocalFilesystem
    {
        return new LocalFilesystem(
            $this->tmpDir,
            '',
            $variants,
            new ImageResizer(),
        );
    }
}
