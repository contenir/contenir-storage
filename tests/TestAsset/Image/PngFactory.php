<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\TestAsset\Image;

use function crc32;
use function gzcompress;
use function pack;
use function str_repeat;
use function strlen;

/**
 * Builds the bytes of a black RGB PNG of a given size, so tests need no GD
 * extension and unit tests need no file.
 */
final class PngFactory
{
    public static function bytes(int $width, int $height): string
    {
        $row = "\0" . str_repeat("\0\0\0", $width);

        return (
            "\x89PNG\r\n\x1A\n"
                . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
                . self::chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
                . self::chunk('IEND', '')
        );
    }

    /**
     * The same black PNG carrying a tEXt "Comment" chunk, for asserting that
     * metadata is stripped.
     */
    public static function bytesWithComment(int $width, int $height, string $comment): string
    {
        $row = "\0" . str_repeat("\0\0\0", $width);

        return (
            "\x89PNG\r\n\x1A\n"
                . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
                . self::chunk('tEXt', "Comment\0{$comment}")
                . self::chunk('IDAT', (string) gzcompress(str_repeat($row, $height)))
                . self::chunk('IEND', '')
        );
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
