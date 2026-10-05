<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Image;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\VariantFit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function exec;
use function extension_loaded;
use function file;
use function fileperms;
use function filesize;
use function getenv;
use function getimagesize;
use function is_executable;
use function mkdir;
use function putenv;
use function sprintf;
use function symlink;
use function umask;

use const FILE_IGNORE_NEW_LINES;

/**
 * Exercises ImageResizer against both a real ImageMagick binary and the
 * native `imagick` PHP extension, since production hosts only have the
 * extension compiled in (no CLI binary) while some local dev setups only
 * have the CLI. Pins down the geometry semantics both backends are
 * sensitive to — in particular, resizing with only one dimension given must
 * still preserve the source aspect ratio.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('image')]
final class ImageResizerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string|false $originalPath;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function backendProvider(): array
    {
        return [
            'imagick extension' => [true],
            'CLI binary'        => [false],
        ];
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: VariantFit, 3: list<string>}>
     */
    public static function cliGeometryProvider(): array
    {
        return [
            'cover'               => [
                40,
                20,
                VariantFit::Cover,
                ['-resize', '40x20^', '-gravity', 'center', '-extent', '40x20'],
            ],
            'contain'             => [40, 20, VariantFit::Contain, ['-resize', '40x20']],
            'contain width only'  => [40, 0, VariantFit::Contain, ['-resize', '40x']],
            'contain height only' => [0, 20, VariantFit::Contain, ['-resize', 'x20']],
            'fill'                => [40, 20, VariantFit::Fill, ['-resize', '40x20!']],
        ];
    }

    #[Test]
    public function anEmptyBinaryPathDisablesTheCli(): void
    {
        static::assertNull((new ImageResizer(binaryPath: ''))->binaryPath());
    }

    #[Test]
    public function choosesABackendPerCallWhenNotForced(): void
    {
        $this->skipUnlessBackendAvailable(useExtension: false);
        $source = $this->writePng('source.png', 40, 20);
        $dest   = $this->path('out.png');

        (new ImageResizer())->resize($source, $dest, 20, 0, VariantFit::Contain);

        static::assertSame([20, 10], $this->dimensions($dest));
    }

    #[Test]
    public function constructorNeverThrowsRegardlessOfExtensionOrBinaryAvailability(): void
    {
        $this->expectNotToPerformAssertions();
        new ImageResizer();
        new ImageResizer(useExtension: true);
        new ImageResizer(useExtension: false);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function containResizesProportionallyWhenOnlyHeightIsGiven(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->makeResizer($useExtension)->resize($source, $dest, 0, 300, VariantFit::Contain);

        [$width, $height] = $this->dimensions($dest);
        static::assertSame(400, $width);
        static::assertSame(300, $height);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function containResizesProportionallyWhenOnlyWidthIsGiven(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->makeResizer($useExtension)->resize($source, $dest, 400, 0, VariantFit::Contain);

        [$width, $height] = $this->dimensions($dest);
        static::assertSame(400, $width);
        static::assertSame(300, $height);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function coverCropsToExactDimensions(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->makeResizer($useExtension)->resize($source, $dest, 400, 400, VariantFit::Cover);

        [$width, $height] = $this->dimensions($dest);
        static::assertSame(400, $width);
        static::assertSame(400, $height);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function coverRejectsZeroDimension(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->expectException(InvalidArgumentException::class);
        $this->makeResizer($useExtension)->resize($source, $dest, 400, 0, VariantFit::Cover);
    }

    #[Test]
    public function createsTheDestinationDirectoryWithDefaultPermissions(): void
    {
        $source  = $this->writePng('source.png', 10, 10);
        $resizer = new ImageResizer(
            binaryPath: '',
            useExtension: false,
        );

        try {
            $resizer->resize($source, $this->path('new/out.png'), 10, 10);
            static::fail('Without a backend the resize itself must still fail.');
        } catch (WriteException $e) {
            static::assertStringContainsString('No ImageMagick backend available', $e->getMessage());
        }

        static::assertSame(0o777 & ~umask(), fileperms($this->path('new')) & 0o777);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function defaultsToQuality85(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source   = $this->writePng('source.png', 40, 40);
        $default  = $this->path('default.jpg');
        $explicit = $this->path('explicit.jpg');

        $this->makeResizer($useExtension)->resize($source, $default, 20, 20, VariantFit::Fill);
        $this->makeResizer($useExtension)->resize($source, $explicit, 20, 20, VariantFit::Fill, quality: 85);

        static::assertFileEquals($explicit, $default);
    }

    #[Test]
    public function discoversTheConvertBinaryWhenMagickIsNotOnThePath(): void
    {
        $which = exec('command -v which');
        $bin   = $this->writeFile('bin/convert', "#!/bin/sh\nexit 0\n");
        chmod($bin, permissions: 0o755);
        symlink((string) $which, $this->path('bin/which'));
        putenv("PATH={$this->path('bin')}");

        static::assertSame($bin, (new ImageResizer())->binaryPath());
    }

    #[Test]
    public function discoversTheMagickBinaryBeforeConvert(): void
    {
        $which  = exec('command -v which');
        $magick = $this->writeFile('bin/magick', "#!/bin/sh\nexit 0\n");
        $this->writeFile('bin/convert', "#!/bin/sh\nexit 0\n");
        chmod($magick, permissions: 0o755);
        chmod($this->path('bin/convert'), permissions: 0o755);
        symlink((string) $which, $this->path('bin/which'));
        putenv("PATH={$this->path('bin')}");

        static::assertSame($magick, (new ImageResizer())->binaryPath());
    }

    /**
     * @param list<string> $geometry
     */
    #[Test]
    #[DataProvider('cliGeometryProvider')]
    public function drivesTheCliWithTheGeometryForTheFit(
        int $width,
        int $height,
        VariantFit $fit,
        array $geometry,
    ): void {
        $bin = $this->writeFile(
            'bin/fake-magick',
            "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$(dirname \"\$0\")/args\"\nfor last; do :; done\n: > \"\$last\"\n",
        );
        chmod($bin, permissions: 0o755);
        $source = $this->writePng('source.png', 10, 10);
        $dest   = $this->path('out.png');

        (new ImageResizer(
            binaryPath: $bin,
            useExtension: false,
        ))->resize($source, $dest, $width, $height, $fit);

        static::assertSame(
            [
                $source,
                '-background',
                'none',
                '-colorspace',
                'sRGB',
                '-strip',
                ...$geometry,
                '-unsharp',
                '0x0.75',
                '-quality',
                '85',
                $dest,
            ],
            file($this->path('bin/args'), FILE_IGNORE_NEW_LINES),
        );
    }

    #[Test]
    public function failsForAFormatNoBackendSupports(): void
    {
        $source = $this->writePng('source.png', 10, 10);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('No ImageMagick backend available for "NOSUCHFORMAT" output');
        (new ImageResizer(binaryPath: ''))->resize($source, $this->path('out.nosuchformat'), 10, 10);
    }

    #[Test]
    public function failsWhenNoImageMagickBackendIsAvailable(): void
    {
        $source  = $this->writePng('source.png', 10, 10);
        $resizer = new ImageResizer(
            binaryPath: '',
            useExtension: false,
        );

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage(
            'No ImageMagick backend available for "PNG" output: the imagick extension doesn\'t support it '
                . 'and no magick/convert CLI binary was found.',
        );
        $resizer->resize($source, $this->path('out.png'), 10, 10);
    }

    #[Test]
    public function failsWhenTheCliExitsCleanlyWithoutWritingTheDestination(): void
    {
        $bin = $this->writeFile('bin/fake-magick', "#!/bin/sh\nexit 0\n");
        chmod($bin, permissions: 0o755);
        $source = $this->writePng('source.png', 10, 10);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('ImageMagick failed resizing');
        (new ImageResizer(
            binaryPath: $bin,
            useExtension: false,
        ))->resize($source, $this->path('out.png'), 10, 10);
    }

    #[Test]
    public function failsWhenTheDestinationDirectoryCannotBeCreated(): void
    {
        $source = $this->writePng('source.png', 10, 10);
        $this->writeFile('blocker', 'a file where a directory is needed');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot create destination directory');
        (new ImageResizer(binaryPath: '/bin/false'))->resize($source, $this->path('blocker/sub/out.png'), 10, 10);
    }

    #[Test]
    public function failsWhenTheDestinationDirectoryIsNotWritable(): void
    {
        $this->skipWhenRunningAsRoot();
        $source = $this->writePng('source.png', 10, 10);
        mkdir($this->path('locked'), permissions: 0o555);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('is not writable');
        (new ImageResizer(binaryPath: '/bin/false'))->resize($source, $this->path('locked/out.png'), 10, 10);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function failsWhenTheSourceIsNotAnImage(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writeFile('source.png', 'not an image');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('ImageMagick failed resizing');
        $this->makeResizer($useExtension)->resize($source, $this->path('out.png'), 10, 10);
    }

    #[Test]
    public function fallsBackToTheCliForADestinationWithoutAnExtension(): void
    {
        $this->skipUnlessBackendAvailable(useExtension: false);
        $source = $this->writePng('source.png', 40, 20);
        $dest   = $this->path('out');

        (new ImageResizer())->resize($source, $dest, 20, 10, VariantFit::Fill);

        static::assertSame([20, 10], $this->dimensions($dest));
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function fillRejectsZeroDimension(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->expectException(InvalidArgumentException::class);
        $this->makeResizer($useExtension)->resize($source, $dest, 0, 300, VariantFit::Fill);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function fillStretchesToExactDimensions(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->makeResizer($useExtension)->resize($source, $dest, 200, 500, VariantFit::Fill);

        [$width, $height] = $this->dimensions($dest);
        static::assertSame(200, $width);
        static::assertSame(500, $height);
    }

    #[Test]
    public function keepsAnExplicitBinaryPath(): void
    {
        static::assertSame('/opt/magick', (new ImageResizer(binaryPath: '/opt/magick'))->binaryPath());
    }

    #[Test]
    public function probesKnownInstallPathsWhenNothingIsOnThePath(): void
    {
        $which = exec('command -v which');
        mkdir($this->path('bin'));
        symlink((string) $which, $this->path('bin/which'));
        putenv("PATH={$this->path('bin')}");

        $resizer = new ImageResizer();

        $expected = null;
        foreach ([
            '/usr/local/bin/magick',
            '/usr/bin/magick',
            '/opt/homebrew/bin/magick',
            '/usr/local/bin/convert',
            '/usr/bin/convert',
        ] as $candidate) {
            if (! is_executable($candidate)) {
                continue;
            }

            $expected = $candidate;
            break;
        }

        static::assertSame($expected, $resizer->binaryPath());
    }

    #[Test]
    public function rejectsAnUnreadableSource(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('is not readable');
        (new ImageResizer(binaryPath: '/bin/false'))->resize(
            $this->path('missing.png'),
            $this->path('out.png'),
            10,
            10,
        );
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function rejectsZeroForBothDimensions(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->expectException(InvalidArgumentException::class);
        $this->makeResizer($useExtension)->resize($source, $dest, 0, 0, VariantFit::Contain);
    }

    #[Test]
    public function resizesThroughWhicheverBackendSupportsTheFormat(): void
    {
        if (! extension_loaded('imagick') && exec('which magick') === '' && exec('which convert') === '') {
            self::markTestSkipped('Neither the imagick extension nor an ImageMagick CLI is available.');
        }
        $source = $this->writePng('source.png', 40, 20);
        $dest   = $this->path('out.png');

        (new ImageResizer())->resize($source, $dest, 20, 10, VariantFit::Fill);

        static::assertSame([20, 10], $this->dimensions($dest));
    }

    #[Test]
    public function resizeThrowsWhenForcedToCliAndBinaryCannotRun(): void
    {
        $source = $this->writePng('source.png', 800, 600);
        $dest   = "{$this->tmpDir}/out.png";

        $this->expectException(WriteException::class);
        (new ImageResizer(
            binaryPath: '/nonexistent/magick',
            useExtension: false,
        ))->resize($source, $dest, 400, 400, VariantFit::Cover);
    }

    #[Test]
    #[DataProvider('backendProvider')]
    public function writesTheRequestedQuality(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePng('source.png', 40, 40);
        $low    = $this->path('low.jpg');
        $high   = $this->path('high.jpg');

        $this->makeResizer($useExtension)->resize($source, $low, 20, 20, VariantFit::Fill, quality: 10);
        $this->makeResizer($useExtension)->resize($source, $high, 20, 20, VariantFit::Fill, quality: 100);

        static::assertLessThan(filesize($high), filesize($low));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
        $this->originalPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->originalPath ? 'PATH' : "PATH={$this->originalPath}");
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $path): array
    {
        $info = getimagesize($path);
        if (false === $info) {
            self::fail(sprintf('Could not read image dimensions for "%s".', $path));
        }
        return [$info[0], $info[1]];
    }

    private function makeResizer(bool $useExtension): ImageResizer
    {
        return new ImageResizer(useExtension: $useExtension);
    }

    /**
     * @mago-expect lint:no-boolean-flag-parameter Mirrors the data provider's backend switch.
     */
    private function skipUnlessBackendAvailable(bool $useExtension): void
    {
        if ($useExtension && ! extension_loaded('imagick')) {
            self::markTestSkipped('imagick extension not loaded.');
        }
        if (! $useExtension && exec('which magick') === '' && exec('which convert') === '') {
            self::markTestSkipped('ImageMagick (magick/convert) not installed.');
        }
    }
}
