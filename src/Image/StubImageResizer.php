<?php

declare(strict_types=1);

namespace Contenir\Storage\Image;

use Contenir\Storage\VariantFit;
use Override;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Drop-in ImageResizer that records calls and writes a placeholder file rather
 * than shelling out to ImageMagick. Lets LocalFilesystem unit tests
 * verify variant-generation orchestration without real image transformation.
 */
final class StubImageResizer extends ImageResizer
{
    /** @var list<array{source: string, dest: string, width: int, height: int, fit: VariantFit, quality: ?int}> */
    public array $calls = [];

    public function __construct()
    {
        $this->binaryPath = '/dev/null';
    }

    /**
     * @mago-expect lint:excessive-parameter-list Overrides ImageResizer::resize().
     */
    #[Override]
    public function resize(
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        VariantFit $fit = VariantFit::Cover,
        ?int $quality = null,
    ): void {
        $this->calls[] = [
            'source'  => $sourcePath,
            'dest'    => $destPath,
            'width'   => $width,
            'height'  => $height,
            'fit'     => $fit,
            'quality' => $quality,
        ];

        $dir = dirname($destPath);
        if (! is_dir($dir)) {
            mkdir($dir, permissions: 0o777, recursive: true);
        }
        file_put_contents($destPath, sprintf('STUB:%dx%d:%s', $width, $height, $fit->name));
    }
}
