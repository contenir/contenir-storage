<?php

declare(strict_types=1);

namespace Contenir\Storage\Internal;

use function ord;
use function strlen;

/**
 * Whether a GIF has more than one frame.
 *
 * Its blocks are walked until a second image descriptor turns up, skipping
 * each extension and each frame's data by their sub-block lengths, so reading
 * stops early in the file.
 *
 * @internal
 */
final class GifAnimation
{
    private const int EXTENSION = 0x21;
    private const int IMAGE     = 0x2C;

    /** The signature and logical screen descriptor before the first block. */
    private const int SCREEN_LENGTH = 13;

    /** An image descriptor after its introducer: position, size and packed field. */
    private const int DESCRIPTOR_LENGTH = 9;

    /**
     * @param string $head The first bytes of the file, at most the screen
     *                     descriptor's length, already read from $reader.
     */
    public static function isAnimated(StreamReader $reader, string $head): bool
    {
        $screen = $head . $reader->read(self::SCREEN_LENGTH - strlen($head));
        if (self::SCREEN_LENGTH !== strlen($screen) || ! $reader->skip(self::colourTableLength(ord($screen[10])))) {
            return false;
        }

        $frames = 0;
        while (null !== ($introducer = $reader->readExactly(1))) {
            $block = ord($introducer);
            if (self::IMAGE === $block && 2 === ++$frames) {
                return true;
            }

            $complete = match ($block) {
                self::EXTENSION => null !== $reader->readExactly(1) && self::skipSubBlocks($reader),
                self::IMAGE => self::skipImage($reader),
                default => false,
            };
            if (! $complete) {
                return false;
            }
        }

        return false;
    }

    /** The bytes of the colour table a packed field declares, or 0 for none. */
    private static function colourTableLength(int $packed): int
    {
        return 0 === ($packed & 0x80) ? 0 : 3 * (2 ** (($packed & 0x07) + 1));
    }

    /** Skip a frame after its introducer: descriptor, colour table and data. */
    private static function skipImage(StreamReader $reader): bool
    {
        $descriptor = $reader->readExactly(self::DESCRIPTOR_LENGTH);

        return (
            null !== $descriptor
                && $reader->skip(self::colourTableLength(ord($descriptor[8])))
                && null !== $reader->readExactly(1)
                && self::skipSubBlocks($reader)
        );
    }

    /**
     * Skip a data sub-block sequence: each block is a length byte and that
     * many bytes, and a zero length ends the sequence.
     */
    private static function skipSubBlocks(StreamReader $reader): bool
    {
        while (null !== ($size = $reader->readExactly(1))) {
            if ("\0" === $size) {
                return true;
            }
            if (! $reader->skip(ord($size))) {
                return false;
            }
        }

        return false;
    }
}
