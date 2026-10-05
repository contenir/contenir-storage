<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Config;

use Contenir\Storage\Config\StorageConfig;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Builds a local backend from config and stores a real upload, so the flat
 * variant declaration is observed through the resizer it drives.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('filesystem')]
final class StorageConfigTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int|null}>
     */
    public static function qualityProvider(): array
    {
        return [
            'declared quality' => [['width' => 10, 'height' => 10, 'quality' => '70'], 70],
            'no quality'       => [['width' => 10, 'height' => 10], null],
        ];
    }

    /**
     * @param array<string, mixed> $spec
     */
    #[Test]
    #[DataProvider('qualityProvider')]
    public function flatVariantResizesAtTheDeclaredQuality(array $spec, ?int $expected): void
    {
        $resizer = new StubImageResizer();
        $manager = StorageConfig::fromArray(['variants' => ['thumb' => $spec]], $resizer, $this->tmpDir);

        $manager->primary()->store(new UploadInput($this->writePng('a.png', 4, 4), 'a.png'), 'gallery');

        static::assertSame($expected, $resizer->calls[0]['quality']);
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
