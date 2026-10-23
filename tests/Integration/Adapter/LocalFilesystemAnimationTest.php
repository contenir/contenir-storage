<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Tests\TestAsset\Image\GifFactory;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An animated source gets no variants, so it is served as itself at every
 * size. Runs against a scratch directory with a recording resizer.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('filesystem')]
final class LocalFilesystemAnimationTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private StubImageResizer $resizer;

    #[Test]
    public function imageMetaFlagsAnAnimatedSource(): void
    {
        $this->writeFile('a.gif', GifFactory::bytes(2));

        static::assertTrue($this->backend()->imageMeta('a.gif')->animated);
    }

    #[Test]
    public function imageMetaReportsAStillSourceAsNotAnimated(): void
    {
        $this->writePng('a.png', 4, 4);

        static::assertFalse($this->backend()->imageMeta('a.png')->animated);
    }

    #[Test]
    public function missingVariantsReportsNothingForAnAnimatedSource(): void
    {
        $this->writeFile('gallery/a.gif', GifFactory::bytes(2));

        static::assertSame([], $this->backend()->missingVariants('gallery/a.gif'));
    }

    #[Test]
    public function regenerateMissingVariantsMakesNothingForAnAnimatedSource(): void
    {
        $this->writeFile('gallery/a.gif', GifFactory::bytes(2));

        static::assertSame([], $this->backend()->regenerateMissingVariants('gallery/a.gif'));
        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function storeMakesNoVariantsForAnAnimatedUpload(): void
    {
        $source = $this->writeFile('upload.gif', GifFactory::bytes(3));

        $entry = $this->backend()->store(new UploadInput($source, 'spin.gif', 'image/gif'), 'gallery');

        static::assertSame([], $this->resizer->calls);
        static::assertTrue($entry->image?->animated);
        static::assertDirectoryDoesNotExist($this->path('gallery/_variant'));
    }

    #[Test]
    public function storeStillMakesVariantsForASingleFrameGif(): void
    {
        $source = $this->writeFile('upload.gif', GifFactory::bytes(1));

        $this->backend()->store(new UploadInput($source, 'dot.gif', 'image/gif'), 'gallery');

        static::assertCount(2, $this->resizer->calls);
    }

    #[Test]
    public function thumbnailUrlOfAnAnimatedSourceIsNull(): void
    {
        $this->writeFile('gallery/a.gif', GifFactory::bytes(2));

        static::assertNull($this->backend()->thumbnailUrl('gallery/a.gif'));
    }

    /**
     * A still written before the source was recognised as animated is not
     * offered, so the animation is shown rather than one frame of it.
     */
    #[Test]
    public function urlIgnoresAStaleVariantOfAnAnimatedSource(): void
    {
        $this->writeFile('gallery/a.gif', GifFactory::bytes(2));
        $this->writeFile('gallery/_variant/hero/a.gif', GifFactory::bytes(1));

        static::assertNull($this->backend()->url('gallery/a.gif', 'hero'));
    }

    #[Test]
    public function urlOfAnAnimatedSourceIsItsPublicPath(): void
    {
        $this->writeFile('gallery/a.gif', GifFactory::bytes(2));

        static::assertSame('/gallery/a.gif', $this->backend()->url('gallery/a.gif'));
    }

    #[Test]
    public function urlOfAVariantOfAnAnimatedSourceIsNullAndNothingIsResized(): void
    {
        $this->writeFile('gallery/a.gif', GifFactory::bytes(2));

        static::assertNull($this->backend()->url('gallery/a.gif', 'hero'));
        static::assertSame([], $this->resizer->calls);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
        $this->resizer = new StubImageResizer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }

    private function backend(): LocalFilesystem
    {
        return new LocalFilesystem(
            $this->tmpDir,
            '',
            new VariantRegistry(
                new Variant(StorageInterface::THUMBNAIL_VARIANT, 18, 18, VariantFit::Contain),
                new Variant('hero', 120, 60, VariantFit::Cover),
            ),
            $this->resizer,
        );
    }
}
