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
 * stops early in the file. A file cut short simply runs out of blocks.
 *
 * @internal
 */
final class GifAnimation
{
    private const int EXTENSION = 0x21;
    private const int IMAGE     = 0x2C;

    /** The signature and logical screen descriptor before the first block. */
    private const int SCREEN_LENGTH = 13;

    /** Where the screen descriptor's packed field sits in the file. */
    private const int SCREEN_PACKED_OFFSET = 10;

    /** An image descriptor after its introducer: position, size and packed field. */
    private const int DESCRIPTOR_LENGTH = 9;

    /** Where the image descriptor's packed field sits after its introducer. */
    private const int DESCRIPTOR_PACKED_OFFSET = 8;

    /**
     * @param string $head The first bytes of the file, at most the screen
     *                     descriptor's length, already read from $reader.
     */
    public static function isAnimated(StreamReader $reader, string $head): bool
    {
        $screen = $head . $reader->read(self::SCREEN_LENGTH - strlen($head));
        $reader->skip(self::colourTableLength($screen, self::SCREEN_PACKED_OFFSET));

        $frames = 0;
        while (null !== ($introducer = $reader->readExactly(1))) {
            $block = ord($introducer);
            if (self::EXTENSION === $block) {
                $reader->skip(1);
                self::skipSubBlocks($reader);
                continue;
            }
            if (self::IMAGE !== $block) {
                return false;
            }
            if (2 === ++$frames) {
                return true;
            }
            self::skipImage($reader);
        }

        return false;
    }

    /**
     * The bytes of the colour table declared by the packed field at $offset
     * in $bytes, or 0 for none.
     */
    private static function colourTableLength(string $bytes, int $offset): int
    {
        $packed = ord($bytes[$offset] ?? "\0");

        /**
         * The top bit says whether there is a table, and the low three bits
         * give its size as 2^(n+1) entries of three bytes each.
         */
        return ($packed >> 7) * 3 * (2 ** (($packed & 0x07) + 1));
    }

    /** Skip a frame after its introducer: descriptor, colour table and data. */
    private static function skipImage(StreamReader $reader): void
    {
        $descriptor = $reader->read(self::DESCRIPTOR_LENGTH);
        $reader->skip(self::colourTableLength($descriptor, self::DESCRIPTOR_PACKED_OFFSET));
        $reader->skip(1);
        self::skipSubBlocks($reader);
    }

    /**
     * Skip a data sub-block sequence: each block is a length byte and that
     * many bytes, and a zero length ends the sequence.
     */
    private static function skipSubBlocks(StreamReader $reader): void
    {
        while (null !== ($size = $reader->readExactly(1)) && "\0" !== $size) {
            $reader->skip(ord($size));
        }
    }
}
