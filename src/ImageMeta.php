<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * What a backend knows about a stored image.
 *
 * $animated marks a source with more than one frame (an animated GIF, APNG or
 * WebP). Resizing would keep one frame at best, so backends make no variants
 * of an animated source and it is served as itself at every size.
 */
final class ImageMeta
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly bool $animated = false,
    ) {}
}
