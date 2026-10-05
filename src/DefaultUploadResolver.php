<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\UnsupportedTypeException;
use Contenir\Storage\Internal\Warnings;
use Override;

use function finfo_file;
use function finfo_open;
use function getimagesize;
use function is_readable;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_FILENAME;

/**
 * Default {@see UploadResolverInterface}: detects the type from the source
 * bytes, maps it to one canonical extension, and slugs the client basename.
 *
 * The extension map IS the allowlist — a detected type absent from it is
 * rejected rather than guessed, so a stored key's extension is always
 * trustworthy. The original format is preserved (no transcoding); callers that
 * want format normalisation do it as an explicit variant/transform.
 */
final class DefaultUploadResolver implements UploadResolverInterface
{
    /**
     * Detected MIME → canonical lowercase extension.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_EXTENSION_MAP = [
        // Images
        'image/jpeg'     => 'jpg',
        'image/pjpeg'    => 'jpg',
        'image/png'      => 'png',
        'image/x-png'    => 'png',
        'image/gif'      => 'gif',
        'image/webp'     => 'webp',
        'image/avif'     => 'avif',
        'image/bmp'      => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff'     => 'tiff',
        'image/svg+xml'  => 'svg',
        'image/x-icon'   => 'ico',
        'image/heic'     => 'heic',
        // Documents
        'application/pdf'                                                           => 'pdf',
        'application/msword'                                                        => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.ms-excel'                                                  => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.ms-powerpoint'                                             => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text'                                   => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'                            => 'ods',
        'application/vnd.oasis.opendocument.presentation'                           => 'odp',
        'application/rtf'                                                           => 'rtf',
        'text/rtf'                                                                  => 'rtf',
        'text/plain'                                                                => 'txt',
        'text/csv'                                                                  => 'csv',
        'text/markdown'                                                             => 'md',
        'text/html'                                                                 => 'html',
        'text/xml'                                                                  => 'xml',
        'application/xml'                                                           => 'xml',
        'application/json'                                                          => 'json',
        // Archives
        'application/zip'              => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/gzip'             => 'gz',
        'application/x-tar'            => 'tar',
        'application/x-7z-compressed'  => '7z',
        'application/vnd.rar'          => 'rar',
        'application/x-rar-compressed' => 'rar',
        // Audio
        'audio/mpeg'  => 'mp3',
        'audio/wav'   => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg'   => 'ogg',
        'audio/aac'   => 'aac',
        'audio/flac'  => 'flac',
        // Video
        'video/mp4'       => 'mp4',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/webm'      => 'webm',
        'video/mpeg'      => 'mpeg',
    ];

    /** @var array<string, string> */
    private array $extensionMap;

    /**
     * @param array<string, string>|null $extensionMap Detected MIME → canonical
     *        extension. Pass a custom map to widen or narrow the storable set;
     *        defaults to the built-in allowlist.
     */
    public function __construct(?array $extensionMap = null)
    {
        $this->extensionMap = $extensionMap ?? self::DEFAULT_EXTENSION_MAP;
    }

    #[Override]
    public function resolve(UploadInput $upload): ResolvedUpload
    {
        [$mime, $image] = $this->detect($upload->sourcePath);

        $extension = $this->extensionMap[$mime] ?? null;
        if (null === $extension) {
            throw UnsupportedTypeException::forMime($mime, $upload->clientFilename);
        }

        return new ResolvedUpload(
            name: sprintf('%s.%s', $this->slug($upload->clientFilename), $extension),
            mime: $mime,
            image: $image,
        );
    }

    /**
     * Detect the source's type from its bytes.
     *
     * @return array{0: string, 1: ?ImageMeta} The normalised MIME and, for a
     *         raster image, its dimensions (null otherwise).
     *
     * @throws UnsupportedTypeException When the source is unreadable or its type cannot be detected.
     */
    private function detect(string $sourcePath): array
    {
        if ('' === $sourcePath || ! is_readable($sourcePath)) {
            throw UnsupportedTypeException::forUnreadable($sourcePath);
        }

        $size = Warnings::suppress(getimagesize(...), $sourcePath);
        if (false !== $size) {
            $mime = strtolower($size['mime']);

            return [$mime, new ImageMeta($size[0], $size[1], $mime)];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = false === $finfo ? false : finfo_file($finfo, $sourcePath);
        if (false === $mime || '' === $mime) {
            throw UnsupportedTypeException::forUndetectable($sourcePath);
        }

        return [strtolower($mime), null];
    }

    /**
     * @throws InvalidPathException When the name has no slug-safe characters.
     */
    private function slug(string $clientFilename): string
    {
        $slug = strtolower(pathinfo($clientFilename, PATHINFO_FILENAME));
        $slug = (string) preg_replace(
            pattern: '/[^a-z0-9]+/',
            replacement: '-',
            subject: $slug,
        );
        $slug = trim(
            (string) preg_replace(
                pattern: '/-{2,}/',
                replacement: '-',
                subject: $slug,
            ),
            characters: '-',
        );

        if ('' === $slug) {
            throw InvalidPathException::forEmptyName($clientFilename);
        }

        return $slug;
    }
}
