<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Image;

use Contenir\Storage\Image\AnimationDetector;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;

/**
 * Detection against files on disk: real WebP files written by ImageMagick, and
 * files that cannot be opened.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('image')]
final class AnimationDetectorFileTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'animated webp' => ['animated.webp', true],
            'still webp'    => ['still.webp', false],
            'jpeg'          => ['cmyk.jpg', false],
        ];
    }

    #[Test]
    #[DataProvider('fixtureProvider')]
    public function readsTheAnimationFromAFile(string $fixture, bool $animated): void
    {
        static::assertSame(
            $animated,
            AnimationDetector::isAnimatedFile(__DIR__ . '/../../TestAsset/Image/' . $fixture),
        );
    }

    #[Test]
    public function reportsAMissingFileAsNotAnimated(): void
    {
        static::assertFalse(AnimationDetector::isAnimatedFile($this->path('missing.gif')));
    }

    #[Test]
    public function reportsAnUnreadableFileAsNotAnimated(): void
    {
        $this->skipWhenRunningAsRoot();
        $path = $this->writeFile('locked.gif', 'GIF89a');
        chmod($path, permissions: 0o000);

        static::assertFalse(AnimationDetector::isAnimatedFile($path));
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
}
