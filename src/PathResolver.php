<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\Internal\Warnings;
use SplFileInfo;

use function array_filter;
use function copy;
use function dirname;
use function implode;
use function in_array;
use function is_dir;
use function is_writable;
use function mkdir;
use function preg_replace;
use function rtrim;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Bridge between legacy field-config option-bag uploads and the new ImageResizer.
 *
 * The CMS field XML schema describes lookup-table file pyramids by listing
 * one option array per derived file (e.g. image_lg=1200x800, image_md=600x400,
 * image_sm=200x200), each of which becomes its own row in the asset table.
 * This class consumes those option arrays the same way the deprecated
 * `\PeptoCms\Filter\ImageResize` did, but routes the work through ImageResizer
 * and a single configured root.
 *
 * Output is byte-identical to the legacy filter so existing DB rows and
 * controller assertions keep working — see Filename + the mime→ext mapping.
 *
 * This class is a transitional artefact: when the field-config XML schema is
 * redesigned (post-Mezzio), it goes away.
 *
 * @mago-expect lint:cyclomatic-complexity Mirrors the legacy option-bag filter it replaces, and is slated for removal.
 */
final class PathResolver
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ImageResizerInterface $resizer,
    ) {}

    /**
     * A string option, or null when it is absent or null.
     *
     * @param array<string, mixed> $options
     *
     * @mago-expect analysis:mixed-assignment Field-config options are untyped input; cast to string here.
     */
    private static function option(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return null === $value ? null : (string) $value;
    }

    /**
     * Resize/copy the file described by $options and return the public-relative
     * path to be persisted in the database (always begins with a directory
     * separator, matching legacy behaviour).
     *
     * Recognised option keys (every key is optional):
     *   - path      : sub-directory under the storage root
     *   - prefix    : extra sub-directory below path (often the parent slug)
     *   - suffix    : appended to the basename before the extension
     *   - width     : target width (omit/0 to skip resize and just copy)
     *   - height    : target height
     *   - mimeType  : drives canonical extension selection
     *   - extension : fallback extension when mime is absent/unrecognised
     *
     * @param array<string, mixed> $options
     *
     * @throws WriteException If the destination cannot be written.
     */
    public function resolve(array $options, string $sourcePath, ?string $filename = null): string
    {
        $mime      = strtolower(self::option($options, 'mimeType') ?? '');
        $extension = $this->extensionFor($mime, self::option($options, 'extension') ?? '');

        $source    = new SplFileInfo($sourcePath);
        $basename  = $filename ?? $source->getBasename(".{$source->getExtension()}");
        $finalName = sprintf('%s%s.%s', $basename, self::option($options, 'suffix') ?? '', $extension);

        $segments = array_filter(
            [
                trim(self::option($options, 'path') ?? '', characters: '/'),
                trim(self::option($options, 'prefix') ?? '', characters: '/'),
                $finalName,
            ],
            static fn(string $segment): bool => '' !== $segment,
        );

        $relativePath = '/' . implode('/', $segments);
        $absolutePath = rtrim($this->rootPath, characters: '/') . $relativePath;
        $destDir      = dirname($absolutePath);

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

        $width  = (int) self::option($options, 'width');
        $height = (int) self::option($options, 'height');

        if (($width > 0 || $height > 0) && $this->isResizableMime($mime)) {
            $this->resizer->resize($sourcePath, $absolutePath, $width, $height, VariantFit::Contain);

            return $relativePath;
        }

        if (! Warnings::suppress(copy(...), $sourcePath, $absolutePath)) {
            throw new WriteException(sprintf('Failed copying "%s" to "%s".', $sourcePath, $absolutePath));
        }

        return $relativePath;
    }

    /**
     * Sanitise a basename to a slug-safe form: replace runs of non-word
     * characters with single hyphens and lower-case. Byte-identical to the
     * legacy Zend filter so existing DB rows keep matching.
     */
    public function sanitiseBasename(string $basename): string
    {
        $value =
            preg_replace(
                pattern: '/[^\w]+/',
                replacement: '-',
                subject: $basename,
            ) ?? $basename;
        return strtolower($value);
    }

    private function extensionFor(string $mime, string $fallback): string
    {
        return match ($mime) {
            'image/jpeg', 'image/pjpeg'  => 'jpg',
            'image/png', 'image/x-png'   => 'png',
            'image/gif'                  => 'gif',
            'image/webp'                 => 'webp',
            'image/svg+xml', 'image/svg' => 'svg',
            default                      => $fallback,
        };
    }

    /**
     * Mirror the legacy ImageResize::filter() switch — only the formats it knew
     * how to drive through ImageMagick are resized; everything else is copied.
     */
    private function isResizableMime(string $mime): bool
    {
        return in_array(
            $mime,
            [
                'image/jpeg',
                'image/pjpeg',
                'image/png',
                'image/x-png',
                'image/gif',
            ],
            strict: true,
        );
    }
}
