<?php

declare(strict_types=1);

namespace Contenir\Storage\Image;

use Contenir\Storage\Internal\GifAnimation;
use Contenir\Storage\Internal\PngAnimation;
use Contenir\Storage\Internal\StreamReader;
use Contenir\Storage\Internal\Warnings;
use Contenir\Storage\Internal\WebpAnimation;

use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function rewind;
use function str_starts_with;
use function substr;

/**
 * Tells an animated image from a still one by reading its container format,
 * without decoding it or needing ImageMagick.
 *
 * Resizing keeps one frame of an animation at best, so callers use this to
 * serve an animated source as itself at every size instead. Three formats can
 * animate, and each says so before its image data, so only as much of the file
 * is read as it takes to decide:
 *
 * - GIF: more than one image descriptor.
 * - PNG (APNG): an `acTL` chunk declaring more than one frame.
 * - WebP: the animation flag in the `VP8X` header chunk.
 *
 * Anything else, a file that ends before it answers, or one that cannot be
 * read, is reported as not animated: the caller then resizes it as it always
 * has.
 *
 * @api
 */
final class AnimationDetector
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** Long enough to hold any of the three formats' signatures. */
    private const int HEAD_LENGTH = 12;

    /** Whether the image in $bytes is animated. */
    public static function isAnimated(string $bytes): bool
    {
        $stream = fopen('php://memory', mode: 'r+b');
        if (is_resource($stream)) {
            fwrite($stream, $bytes);
            rewind($stream);
        }

        return self::inspect($stream);
    }

    /** Whether the image file at $path is animated; false when it cannot be read. */
    public static function isAnimatedFile(string $path): bool
    {
        return self::inspect(Warnings::suppress(fopen(...), $path, 'rb'));
    }

    /**
     * Whether the image read from $stream, positioned at its start, is
     * animated. The stream is read but not closed.
     *
     * @param resource $stream
     */
    public static function isAnimatedStream(mixed $stream): bool
    {
        $reader = new StreamReader($stream);
        $head   = $reader->read(self::HEAD_LENGTH);

        return match (true) {
            str_starts_with($head, 'GIF8') => GifAnimation::isAnimated($reader, $head),
            str_starts_with($head, self::PNG_SIGNATURE) => PngAnimation::isAnimated($reader, substr($head, offset: 8)),
            self::isWebp($head) => WebpAnimation::isAnimated($reader),
            default => false,
        };
    }

    /**
     * Read $stream, if it opened, and close it.
     *
     * @param resource|false $stream
     */
    private static function inspect(mixed $stream): bool
    {
        if (! is_resource($stream)) {
            return false;
        }

        try {
            return self::isAnimatedStream($stream);
        } finally {
            fclose($stream);
        }
    }

    private static function isWebp(string $head): bool
    {
        return str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, offset: 8, length: 4);
    }
}
