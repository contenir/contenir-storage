<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\PathResolver;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function mkdir;
use function sprintf;

/**
 * PathResolver pins down a stable filename + path shape so consumers can
 * upgrade without breaking persisted DB rows: paths begin with a slash, mime
 * → ext mapping is canonical, suffixes are appended before the extension.
 */
#[Group('integration')]
#[Group('storage')]
final class PathResolverTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private StubImageResizer $resizer;

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function mimeExtensionProvider(): array
    {
        return [
            'jpeg'             => ['image/jpeg', 'jpeg', 'jpg'],
            'jpeg progressive' => ['image/pjpeg', 'jpg', 'jpg'],
            'png'              => ['image/png', 'PNG', 'png'],
            'png x-prefixed'   => ['image/x-png', 'png', 'png'],
            'gif'              => ['image/gif', 'gif', 'gif'],
            'webp'             => ['image/webp', 'webp', 'webp'],
            'svg explicit xml' => ['image/svg+xml', 'svg', 'svg'],
            'unknown mime'     => ['application/octet-stream', 'pdf', 'pdf'],
        ];
    }

    #[Test]
    public function resolveAppliesSuffixBeforeExtension(): void
    {
        $source = $this->writeFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'suffix' => '_lg', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        static::assertSame('/uploads/hero_lg.jpg', $path);
    }

    #[Test]
    public function resolveCopiesNonImageMimeRegardlessOfDimensions(): void
    {
        $source = $this->writeFile('source.txt', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'width' => 200, 'height' => 200, 'mimeType' => 'text/plain', 'extension' => 'txt'],
            $source,
            'note',
        );

        static::assertSame([], $this->resizer->calls);
        static::assertSame('/uploads/note.txt', $path);
        static::assertFileExists($this->tmpDir . $path);
    }

    #[Test]
    public function resolveCopiesUnsizedFileAndReturnsLeadingSlashPath(): void
    {
        $source = $this->writeFile('source.jpg', 'data');
        $path   = $this->resolver()->resolve(
            ['path' => '/uploads/products', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        static::assertSame('/uploads/products/hero.jpg', $path);
        static::assertFileExists($this->tmpDir . $path);
        static::assertSame('data', file_get_contents($this->tmpDir . $path));
        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function resolveCopiesWhenWidthAndHeightAreZero(): void
    {
        $source = $this->writeFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'width' => 0, 'height' => 0, 'mimeType' => 'image/jpeg'],
            $source,
            'hero',
        );

        static::assertSame([], $this->resizer->calls);
        static::assertFileExists($this->tmpDir . $path);
    }

    #[Test]
    public function resolveCreatesNestedDestinationDirectories(): void
    {
        $source = $this->writeFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads/2026/04', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        static::assertDirectoryExists("{$this->tmpDir}/uploads/2026/04");
        static::assertFileExists($this->tmpDir . $path);
    }

    #[Test]
    public function resolveDelegatesToImageResizerForImageMimeWithDimensions(): void
    {
        $source = $this->writeFile('source.jpg', 'data');

        $this->resolver()->resolve(
            [
                'path'     => '/uploads',
                'width'    => 200,
                'height'   => 200,
                'mimeType' => 'image/jpeg',
            ],
            $source,
            'hero',
        );

        static::assertCount(1, $this->resizer->calls);
        static::assertSame(200, $this->resizer->calls[0]['width']);
        static::assertSame(200, $this->resizer->calls[0]['height']);
    }

    #[Test]
    public function resolveDerivesFilenameFromSourceWhenNoneProvided(): void
    {
        $source = $this->writeFile('IMG_1234.original.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'extension' => 'jpg'],
            $source,
        );

        static::assertSame('/uploads/IMG_1234.original.jpg', $path);
    }

    #[Test]
    public function resolveJoinsPathPrefixAndFilename(): void
    {
        $source = $this->writeFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'prefix' => 'products', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        static::assertSame('/uploads/products/hero.jpg', $path);
    }

    #[Test]
    #[DataProvider('mimeExtensionProvider')]
    public function resolveOverridesExtensionFromMime(
        string $mime,
        string $fallback,
        string $expected,
    ): void {
        $source = $this->writeFile('source.bin', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'mimeType' => $mime, 'extension' => $fallback],
            $source,
            'hero',
        );

        static::assertSame(sprintf('/uploads/hero.%s', $expected), $path);
    }

    #[Test]
    public function resolveReportsADestinationDirectoryThatCannotBeCreated(): void
    {
        $this->writeFile('blocker', 'b');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot create destination directory');
        $this->resolver()->resolve(['path' => 'blocker/sub'], $this->writeFile('src.txt', 'x'));
    }

    #[Test]
    public function resolveReportsADestinationDirectoryThatIsNotWritable(): void
    {
        $this->skipWhenRunningAsRoot();
        mkdir($this->path('locked'), permissions: 0o555);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('is not writable');
        $this->resolver()->resolve(['path' => 'locked'], $this->writeFile('src.txt', 'x'));
    }

    #[Test]
    public function resolveSkipsEmptyPathSegments(): void
    {
        $source = $this->writeFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '', 'prefix' => 'gallery', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        static::assertSame('/gallery/hero.jpg', $path);
    }

    #[Test]
    public function resolveThrowsWhenSourceUnreadable(): void
    {
        $this->expectException(WriteException::class);

        $this->resolver()->resolve(
            ['path' => '/uploads', 'extension' => 'jpg'],
            '/nonexistent/source.jpg',
            'hero',
        );
    }

    #[Test]
    public function sanitiseBasenameMatchesLegacyFilenameFilter(): void
    {
        $resolver = $this->resolver();

        static::assertSame('my-file-', $resolver->sanitiseBasename('My File!'));
        static::assertSame('img_1234', $resolver->sanitiseBasename('IMG_1234'));
        static::assertSame('hello-world', $resolver->sanitiseBasename('Hello   World'));
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

    private function resolver(): PathResolver
    {
        return new PathResolver($this->tmpDir, $this->resizer);
    }
}
