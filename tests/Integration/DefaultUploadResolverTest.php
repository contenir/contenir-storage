<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration;

use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\UnsupportedTypeException;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;
use function uniqid;

/**
 * Detection reads real files, so these run against a scratch directory.
 */
#[Group('integration')]
#[Group('storage')]
final class DefaultUploadResolverTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[Test]
    public function dotsAndUnsafeCharactersCollapseToASingleSlug(): void
    {
        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($this->makePng(), 'My.Cool   Photo!!.jpeg', null),
        );

        static::assertSame('my-cool-photo.png', $resolved->name);
    }

    #[Test]
    public function extensionFollowsDetectedTypeNotTheClientFilename(): void
    {
        $source = $this->makePng(40, 25);

        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($source, 'Holiday Photo.JPG', 'image/jpeg'),
        );

        static::assertSame('holiday-photo.png', $resolved->name);
        static::assertSame('image/png', $resolved->mime);
        static::assertNotNull($resolved->image);
        static::assertSame(40, $resolved->image->width);
        static::assertSame(25, $resolved->image->height);
    }

    #[Test]
    public function injectedExtensionMapIsTheAllowlist(): void
    {
        $resolver = new DefaultUploadResolver(['image/png' => 'png']);

        $this->expectException(UnsupportedTypeException::class);

        $resolver->resolve(new UploadInput($this->makeJpeg(), 'snap.jpg', null));
    }

    #[Test]
    public function jpegResolvesToJpgExtension(): void
    {
        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($this->makeJpeg(), 'snap.bin', null),
        );

        static::assertSame('snap.jpg', $resolved->name);
        static::assertSame('image/jpeg', $resolved->mime);
    }

    #[Test]
    public function nonImageStorableTypeResolvesWithoutDimensions(): void
    {
        $source = $this->writeFile('doc', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($source, 'Report Final.png', 'image/png'),
        );

        static::assertSame('report-final.pdf', $resolved->name);
        static::assertSame('application/pdf', $resolved->mime);
        static::assertNull($resolved->image);
    }

    #[Test]
    public function rejectsAnEmptySourcePath(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('Upload source "" is not readable.');

        (new DefaultUploadResolver())->resolve(new UploadInput('', 'whatever.png'));
    }

    #[Test]
    public function rejectsDetectedTypeThatIsNotInTheAllowlist(): void
    {
        /**
         * Opaque binary bytes → finfo reports application/octet-stream, which is
         * not a storable type. The client's image/png claim is ignored.
         */
        $source = $this->writeFile('blob', "\x00\x01\x02\x03\xFF\xFE\xFD\xFC\x00\x10");

        $this->expectException(UnsupportedTypeException::class);

        (new DefaultUploadResolver())->resolve(new UploadInput($source, 'blob.png', 'image/png'));
    }

    #[Test]
    public function rejectsUnreadableSource(): void
    {
        $this->expectException(UnsupportedTypeException::class);

        (new DefaultUploadResolver())->resolve(
            new UploadInput($this->path('does-not-exist'), 'whatever.png', 'image/png'),
        );
    }

    #[Test]
    public function rejectsWhenClientFilenameHasNoSlugSafeCharacters(): void
    {
        $this->expectException(InvalidPathException::class);

        (new DefaultUploadResolver())->resolve(new UploadInput($this->makePng(), '@@@.png', null));
    }

    #[Test]
    public function underscoresBecomeDashes(): void
    {
        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($this->makePng(), 'my_photo.png', null),
        );

        static::assertSame('my-photo.png', $resolved->name);
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

    /**
     * A JPEG with just enough structure (SOI, SOF0, EOI) for type detection.
     */
    private function makeJpeg(int $width = 10, int $height = 10): string
    {
        return $this->writeFile(
            uniqid(
                prefix: 'jpg',
                more_entropy: true,
            )
                . '.bin',
            "\xFF\xD8\xFF\xC0"
                . pack('nCnnC', 17, 8, $height, $width, 3)
                . str_repeat("\x01\x11\x00", times: 3)
                . "\xFF\xD9",
        );
    }

    private function makePng(int $width = 10, int $height = 10): string
    {
        return $this->writePng(
            uniqid(
                prefix: 'png',
                more_entropy: true,
            )
                . '.bin',
            $width,
            $height,
        );
    }
}
