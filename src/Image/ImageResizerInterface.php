<?php

declare(strict_types=1);

namespace Contenir\Storage\Image;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\VariantFit;
use InvalidArgumentException;

/**
 * Resizes one image file into another. Storage backends depend on this
 * interface; {@see ImageResizer} is the ImageMagick implementation and
 * {@see StubImageResizer} the test double.
 *
 * @api
 */
interface ImageResizerInterface
{
    /**
     * Resize $sourcePath to $destPath at $width × $height honouring $fit,
     * creating the destination directory and overwriting any existing file.
     * The output format follows $destPath's extension.
     *
     * @throws WriteException           If the source is unreadable or the destination cannot be written.
     * @throws InvalidArgumentException If the dimensions do not suit $fit.
     *
     * @mago-expect lint:excessive-parameter-list Mirrors the 0.x ImageResizer::resize() signature.
     */
    public function resize(
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        VariantFit $fit = VariantFit::Cover,
        ?int $quality = null,
    ): void;
}
