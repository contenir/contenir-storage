<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\TestAsset\Image;

use function str_repeat;

/**
 * Builds the bytes of a 1x1 GIF with any number of frames, so tests need no
 * GD extension and no binary fixture. ImageMagick decodes them.
 */
final class GifFactory
{
    /** Signature and logical screen descriptor, flagging a 2-colour global table. */
    private const string SCREEN = "GIF89a\x01\x00\x01\x00\x80\x00\x00";

    private const string GLOBAL_COLOURS = "\x00\x00\x00\xFF\xFF\xFF";

    /** The NETSCAPE2.0 application extension an animation loops by. */
    private const string LOOP = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

    /** A graphic control extension with a 100ms delay. */
    private const string CONTROL = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

    /** An image descriptor without a local colour table, then its LZW data. */
    private const string IMAGE = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";

    /** The same image carrying a 2-colour local colour table. */
    private const string IMAGE_WITH_LOCAL_COLOURS = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x80\x00\x00\x00\xFF\xFF\xFF\x02\x02\x44\x01\x00";

    private const string TRAILER = "\x3B";

    public static function bytes(int $frames = 1): string
    {
        return (
            self::SCREEN
                . self::GLOBAL_COLOURS
                . ($frames > 1 ? self::LOOP : '')
                . str_repeat(self::CONTROL . self::IMAGE, $frames)
                . self::TRAILER
        );
    }

    /** Frames that each carry their own colour table, as some encoders write. */
    public static function bytesWithLocalColourTables(int $frames): string
    {
        return (
            self::SCREEN
                . self::GLOBAL_COLOURS
                . str_repeat(self::CONTROL . self::IMAGE_WITH_LOCAL_COLOURS, $frames)
                . self::TRAILER
        );
    }
}
