<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Image;

use Contenir\Storage\Image\AnimationDetector;
use Contenir\Storage\Tests\TestAsset\Image\GifFactory;
use Contenir\Storage\Tests\TestAsset\Image\PngFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function fread;
use function fwrite;
use function pack;
use function rewind;
use function strlen;
use function substr;

#[Group('unit')]
#[Group('storage')]
#[Group('image')]
final class AnimationDetectorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function animatedProvider(): array
    {
        return [
            'gif with two frames'          => [GifFactory::bytes(2)],
            'gif with many frames'         => [GifFactory::bytes(12)],
            'gif with local colour tables' => [GifFactory::bytesWithLocalColourTables(2)],
            'apng declaring two frames'    => [PngFactory::bytesWithAnimationControl(2, 2, 2)],
            'webp with the animation bit'  => [self::extendedWebp(0x12)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stillProvider(): array
    {
        return [
            'gif with one frame'                      => [GifFactory::bytes(1)],
            'gif with one frame and a local table'    => [GifFactory::bytesWithLocalColourTables(1)],
            'png'                                     => [PngFactory::bytes(2, 2)],
            'png with a text chunk before the image'  => [PngFactory::bytesWithComment(2, 2, 'still')],
            'apng declaring a single frame'           => [PngFactory::bytesWithAnimationControl(2, 2, 1)],
            'extended webp without the animation bit' => [self::extendedWebp(0x10)],
            'jpeg'                                    => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00"],
            'empty'                                   => [''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function truncatedProvider(): array
    {
        $gif       = GifFactory::bytes(2);
        $localGif  = GifFactory::bytesWithLocalColourTables(2);
        $firstLzw  = 13 + 6 + 19 + 8 + 10;
        $animation = PngFactory::bytesWithAnimationControl(2, 2, 2);

        return [
            'gif inside the screen descriptor'      => [substr($gif, offset: 0, length: 12)],
            'gif inside the global colour table'    => [substr($gif, offset: 0, length: 15)],
            'gif after the colour table, no blocks' => [substr($gif, offset: 0, length: 19)],
            'gif after an extension introducer'     => [substr($gif, offset: 0, length: 20)],
            'gif before an extension sub-block'     => [substr($gif, offset: 0, length: 21)],
            'gif inside an extension'               => [substr($gif, offset: 0, length: 25)],
            'gif inside an image descriptor'        => [substr($gif, offset: 0, length: 13 + 6 + 19 + 8 + 4)],
            'gif before the lzw code size'          => [substr($gif, offset: 0, length: $firstLzw)],
            'gif inside the image data'             => [substr($gif, offset: 0, length: $firstLzw + 3)],
            'gif after its only complete frame'     => [substr($gif, offset: 0, length: $firstLzw + 5)],
            'gif inside a local colour table'       => [substr($localGif, offset: 0, length: 13 + 6 + 8 + 12)],
            'png after the signature'               => [substr($animation, offset: 0, length: 12)],
            'png inside a chunk'                    => [substr($animation, offset: 0, length: 20)],
            'png inside the frame count'            => [substr($animation, offset: 0, length: 8 + 25 + 10)],
            'webp inside the extended header'       => [substr(self::extendedWebp(0x02), offset: 0, length: 18)],
        ];
    }

    /** An extended WebP header: the RIFF wrapper and a `VP8X` chunk with $flags. */
    private static function extendedWebp(int $flags): string
    {
        $extendedChunk = 'VP8X' . pack('V', 10) . pack('C', $flags) . "\x00\x00\x00\x00\x00\x00\x00\x00\x00";

        return 'RIFF' . pack('V', 4 + strlen($extendedChunk)) . 'WEBP' . $extendedChunk;
    }

    #[Test]
    public function readsAStreamFromItsCurrentPositionAndLeavesItOpen(): void
    {
        $bytes  = GifFactory::bytes(2);
        $stream = fopen('php://memory', mode: 'r+b');
        static::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        $animated = AnimationDetector::isAnimatedStream($stream);
        $rest     = fread($stream, strlen($bytes));
        fclose($stream);

        static::assertTrue($animated);
        static::assertNotSame('', $rest);
    }

    #[Test]
    #[DataProvider('animatedProvider')]
    public function recognisesAnAnimatedImage(string $bytes): void
    {
        static::assertTrue(AnimationDetector::isAnimated($bytes));
    }

    #[Test]
    public function reportsAGifWithAnUnknownBlockAsNotAnimated(): void
    {
        $gif = GifFactory::bytes(2);

        static::assertFalse(AnimationDetector::isAnimated(
            substr($gif, offset: 0, length: 19) . "\x99" . substr($gif, offset: 20),
        ));
    }

    #[Test]
    public function reportsAPngWhoseChunksEndWithoutImageDataAsNotAnimated(): void
    {
        $iend = pack('N', 0) . 'IEND' . pack('N', 0xAE42_6082);

        static::assertFalse(AnimationDetector::isAnimated("\x89PNG\r\n\x1A\n{$iend}"));
    }

    #[Test]
    public function reportsASimpleWebpAsNotAnimated(): void
    {
        $webp = 'RIFF' . pack('V', 22) . 'WEBPVP8 ' . pack('V', 10) . "\x00\x00\x00\x9D\x01\x2A\x01\x00\x01\x00";

        static::assertFalse(AnimationDetector::isAnimated($webp));
    }

    #[Test]
    #[DataProvider('stillProvider')]
    public function reportsAStillImageAsNotAnimated(string $bytes): void
    {
        static::assertFalse(AnimationDetector::isAnimated($bytes));
    }

    /**
     * A file cut short is reported as still, so the caller resizes it as it
     * would have before rather than failing.
     */
    #[Test]
    #[DataProvider('truncatedProvider')]
    public function reportsATruncatedImageAsNotAnimated(string $bytes): void
    {
        static::assertFalse(AnimationDetector::isAnimated($bytes));
    }
}
