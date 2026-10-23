<?php

declare(strict_types=1);

namespace Contenir\Storage\Internal;

use function ord;
use function str_starts_with;

/**
 * Whether a WebP is animated: the animation flag in its `VP8X` chunk, which
 * is the first chunk of any extended WebP. A simple (lossy or lossless) WebP
 * has no such chunk and is always a still.
 *
 * @internal
 */
final class WebpAnimation
{
    private const int ANIMATION_FLAG = 0x02;

    /** The chunk's four-character code, its 4-byte size, then the flags byte. */
    private const int FLAGS_OFFSET = 8;

    /**
     * @param StreamReader $reader Positioned after the 12-byte RIFF header.
     */
    public static function isAnimated(StreamReader $reader): bool
    {
        $chunk = $reader->readExactly(self::FLAGS_OFFSET + 1);

        return (
            null !== $chunk
                && str_starts_with($chunk, 'VP8X')
                && 0 !== (ord($chunk[self::FLAGS_OFFSET]) & self::ANIMATION_FLAG)
        );
    }
}
