<?php

declare(strict_types=1);

namespace Contenir\Storage\Image;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Internal\Warnings;
use Contenir\Storage\VariantFit;
use Imagick;
use ImagickException;
use ImagickPixel;
use InvalidArgumentException;
use Override;

use function dirname;
use function escapeshellarg;
use function escapeshellcmd;
use function exec;
use function extension_loaded;
use function file_exists;
use function in_array;
use function is_dir;
use function is_executable;
use function is_readable;
use function is_writable;
use function mkdir;
use function pathinfo;
use function round;
use function sprintf;
use function strtoupper;

use const PATHINFO_EXTENSION;

/**
 * Single-purpose ImageMagick-backed resizer used by storage backends.
 *
 * Prefers the native `imagick` PHP extension when it's loaded AND its
 * underlying ImageMagick build actually supports the destination format —
 * `extension_loaded('imagick')` alone doesn't guarantee that, since coders
 * like AVIF/WEBP (and delegates like SVG) vary by build. Verified per call
 * via {@see Imagick::queryFormats()} against the real installed formats,
 * not assumed from extension presence. Falls back to shelling out to a
 * `magick`/`convert` CLI binary when the extension can't handle the format,
 * e.g. some local dev setups without the extension at all.
 *
 * Resize semantics map to VariantFit:
 *  - Cover   → resize to cover, then crop centered to exact dimensions
 *  - Contain → fit inside the box, preserve aspect
 *  - Fill    → stretch to exact dimensions, ignore aspect
 *
 * @mago-expect lint:cyclomatic-complexity Holds both the imagick and CLI backends; splitting them is a follow-up.
 * @mago-expect lint:kan-defect Holds both the imagick and CLI backends; splitting them is a follow-up.
 */
final class ImageResizer implements ImageResizerInterface
{
    private readonly ?string $binaryPath;

    /** @var bool|null Explicit backend override for callers/tests; null defers to per-call format support. */
    private readonly ?bool $forcedUseExtension;

    /**
     * @param string|null $binaryPath   Absolute path to magick/convert, used as
     *                                  the fallback when the imagick extension
     *                                  can't handle a given format. When null,
     *                                  opportunistically discovered from PATH
     *                                  at construction time (never throws).
     *                                  An empty string disables the CLI.
     * @param bool|null   $useExtension Force the native-extension path (true) or
     *                                  the CLI-binary path (false) for every
     *                                  call. When null (the default), decided
     *                                  per call from real format support.
     */
    public function __construct(?string $binaryPath = null, ?bool $useExtension = null)
    {
        $this->forcedUseExtension = $useExtension;
        $this->binaryPath         = match ($binaryPath) {
            null    => self::tryDiscoverBinary(),
            ''      => null,
            default => $binaryPath,
        };
    }

    private static function extensionSupportsFormat(string $format): bool
    {
        return '' !== $format && in_array($format, (new Imagick())->queryFormats($format), strict: true);
    }

    private static function tryDiscoverBinary(): ?string
    {
        foreach (['magick', 'convert'] as $binary) {
            $found = exec("which {$binary}");
            if (false !== $found && '' !== $found) {
                return $found;
            }
        }

        $candidates = [
            '/usr/local/bin/magick',
            '/usr/bin/magick',
            '/opt/homebrew/bin/magick',
            '/usr/local/bin/convert',
            '/usr/bin/convert',
        ];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The ImageMagick CLI used when the extension cannot handle a format:
     * the configured path, the one discovered at construction, or null.
     */
    public function binaryPath(): ?string
    {
        return $this->binaryPath;
    }

    /**
     * Resize $sourcePath to $destPath at $width × $height honouring $fit.
     *
     * The destination directory is created if missing. Any existing file at
     * $destPath is overwritten.
     *
     * @throws WriteException If the source is unreadable, the destination
     *                             directory cannot be created/written, no
     *                             backend supports the destination format, or
     *                             ImageMagick fails.
     * @throws InvalidArgumentException If the dimensions do not suit $fit.
     *
     * @mago-expect lint:excessive-parameter-list Implements ImageResizerInterface::resize().
     */
    #[Override]
    public function resize(
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        VariantFit $fit = VariantFit::Cover,
        ?int $quality = null,
    ): void {
        if (! is_readable($sourcePath)) {
            throw new WriteException(sprintf('Source image "%s" is not readable.', $sourcePath));
        }
        $this->assertValidDimensions($width, $height, $fit);

        $destDir = dirname($destPath);
        if (
            ! is_dir($destDir)
            && ! Warnings::suppress(mkdir(...), $destDir, permissions: 0o777, recursive: true)
            && ! is_dir($destDir)
        ) {
            throw new WriteException(sprintf('Cannot create destination directory "%s".', $destDir));
        }
        if (! is_writable($destDir)) {
            throw new WriteException(sprintf('Destination directory "%s" is not writable.', $destDir));
        }

        $qualityValue = $quality ?? 85;
        $format       = strtoupper(pathinfo($destPath, PATHINFO_EXTENSION));
        $useExtension = $this->forcedUseExtension
            ?? extension_loaded('imagick') && self::extensionSupportsFormat($format);

        if ($useExtension) {
            $this->resizeWithExtension($sourcePath, $destPath, $width, $height, $fit, $qualityValue);
            return;
        }

        if (null === $this->binaryPath) {
            throw new WriteException(sprintf(
                'No ImageMagick backend available for "%s" output: the imagick extension doesn\'t support it '
                    . 'and no magick/convert CLI binary was found.',
                $format,
            ));
        }

        $this->resizeWithBinary($this->binaryPath, $sourcePath, $destPath, $width, $height, $fit, $qualityValue);
    }

    /**
     * @throws InvalidArgumentException If the dimensions do not suit $fit.
     */
    private function assertValidDimensions(int $width, int $height, VariantFit $fit): void
    {
        if ($width <= 0 && $height <= 0) {
            throw new InvalidArgumentException('At least one of width or height must be > 0.');
        }

        if (VariantFit::Contain !== $fit && ($width <= 0 || $height <= 0)) {
            throw new InvalidArgumentException(sprintf(
                'VariantFit::%s requires both width and height to be > 0.',
                $fit->name,
            ));
        }
    }

    private function geometryArgs(int $width, int $height, VariantFit $fit): string
    {
        $geom = match (true) {
            $width > 0 && $height > 0 => sprintf('%dx%d', $width, $height),
            $width > 0 => sprintf('%dx', $width),
            default => sprintf('x%d', $height),
        };

        return match ($fit) {
            VariantFit::Cover => sprintf(
                '-resize %s -gravity center -extent %s',
                escapeshellarg("{$geom}^"),
                escapeshellarg($geom),
            ),
            VariantFit::Contain => sprintf('-resize %s', escapeshellarg($geom)),
            VariantFit::Fill    => sprintf('-resize %s', escapeshellarg("{$geom}!")),
        };
    }

    /**
     * Imagick::resizeImage() rejects 0 for either dimension, unlike the CLI's
     * "Wx"/"xH" geometry strings — so when only one dimension is given, the
     * missing one is derived from the source's own aspect ratio first.
     */
    private function resizeContain(Imagick $image, int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            $sourceWidth  = $image->getImageWidth();
            $sourceHeight = $image->getImageHeight();
            $width        = $width > 0 ? $width : (int) round(($height * $sourceWidth) / $sourceHeight);
            $height       = $height > 0 ? $height : (int) round(($width * $sourceHeight) / $sourceWidth);
        }

        $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, blur: 1, bestfit: true);
    }

    /**
     * @throws WriteException
     *
     * @mago-expect lint:excessive-parameter-list Carries resize()'s arguments plus the resolved binary.
     */
    private function resizeWithBinary(
        string $binaryPath,
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        VariantFit $fit,
        int $quality,
    ): void {
        // `-background none` preserves alpha for source PNGs/AVIFs; `$quality`
        // is consumed by the encoder driven by $destPath's extension.
        $command = sprintf(
            '%s %s -background none -colorspace sRGB -strip %s -unsharp 0x0.75 -quality %d %s 2>/dev/null',
            escapeshellcmd($binaryPath),
            escapeshellarg($sourcePath),
            $this->geometryArgs($width, $height, $fit),
            $quality,
            escapeshellarg($destPath),
        );

        $exit   = 0;
        $output = [];
        exec($command, $output, $exit);

        if (0 !== $exit || ! file_exists($destPath)) {
            throw new WriteException(sprintf(
                'ImageMagick failed resizing "%s" → "%s" (exit %d).',
                $sourcePath,
                $destPath,
                $exit,
            ));
        }
    }

    /**
     * @throws WriteException
     *
     * @mago-expect lint:excessive-parameter-list Carries resize()'s arguments unchanged.
     */
    private function resizeWithExtension(
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        VariantFit $fit,
        int $quality,
    ): void {
        try {
            $image = new Imagick($sourcePath);
            $image->setBackgroundColor(new ImagickPixel(color: 'transparent'));
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->stripImage();

            match ($fit) {
                VariantFit::Cover   => $image->cropThumbnailImage($width, $height),
                VariantFit::Contain => $this->resizeContain($image, $width, $height),
                VariantFit::Fill => $image->resizeImage(
                    $width,
                    $height,
                    Imagick::FILTER_LANCZOS,
                    blur: 1,
                    bestfit: false,
                ),
            };

            $image->unsharpMaskImage(
                radius: 0,
                sigma: 0.75,
                amount: 1,
                threshold: 0.05,
            );
            $image->setImageCompressionQuality($quality);
            $image->writeImage($destPath);
            $image->clear();
        } catch (ImagickException $e) {
            throw new WriteException(
                sprintf('ImageMagick failed resizing "%s" → "%s": %s', $sourcePath, $destPath, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
