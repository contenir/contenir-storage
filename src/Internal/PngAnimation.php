<?php

declare(strict_types=1);

namespace Contenir\Storage\Internal;

use function strlen;
use function substr;
use function unpack;

/**
 * Whether a PNG is an APNG of more than one frame.
 *
 * An APNG declares its frame count in an `acTL` chunk, which must come before
 * the first `IDAT`, so the chunks are walked only as far as the image data.
 *
 * @internal
 */
final class PngAnimation
{
    private const int CHUNK_HEADER_LENGTH = 8;

    private const int CRC_LENGTH = 4;

    /**
     * @param StreamReader $reader Positioned after the 8-byte signature, with
     *                             $pending the bytes of the first chunk already read.
     */
    public static function isAnimated(StreamReader $reader, string $pending): bool
    {
        while (true) {
            $header  = $pending . $reader->read(self::CHUNK_HEADER_LENGTH - strlen($pending));
            $pending = '';
            if (self::CHUNK_HEADER_LENGTH !== strlen($header)) {
                return false;
            }

            $type = substr($header, offset: 4, length: 4);
            if ('acTL' === $type) {
                return self::declaresFrames($reader);
            }
            if ('IDAT' === $type || 'IEND' === $type || ! $reader->skip(self::length($header) + self::CRC_LENGTH)) {
                return false;
            }
        }
    }

    /** Whether the `acTL` data that follows declares more than one frame. */
    private static function declaresFrames(StreamReader $reader): bool
    {
        $frames = $reader->readExactly(4);

        return null !== $frames && self::number($frames) > 1;
    }

    private static function length(string $header): int
    {
        return self::number(substr($header, offset: 0, length: 4));
    }

    /** A big-endian unsigned 32-bit number from 4 bytes. */
    private static function number(string $bytes): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', $bytes);

        return $value[1];
    }
}
