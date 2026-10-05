<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use Contenir\Storage\BulkDeleteInterface;
use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\UnsupportedTypeException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\Internal\Warnings;
use Contenir\Storage\ListOptions;
use Contenir\Storage\MissingVariantsReporterInterface;
use Contenir\Storage\OnDemandVariantGeneratorInterface;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Override;

use function array_column;
use function array_key_exists;
use function array_map;
use function basename;
use function fclose;
use function fopen;
use function getimagesizefromstring;
use function in_array;
use function is_readable;
use function is_resource;
use function md5;
use function mime_content_type;
use function pathinfo;
use function rename;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function stream_copy_to_stream;
use function stripos;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usort;

use const PATHINFO_EXTENSION;

/**
 * S3-compatible storage backend (AWS S3, Cloudflare R2, MinIO, etc).
 *
 * Variants are stored as sibling objects at "<base>__<variant>.<ext>" rather
 * than under a directory, so the variant is fetched as a sibling of the
 * original key with no prefix-listing penalty.
 *
 * imageMeta() reads the full object body to resolve dimensions — callers
 * iterating over many entries should expect a network round-trip per call.
 * Future optimisation: store width/height in custom object metadata at upload
 * time and resolve via HEAD.
 *
 * Calls that only read the bucket (existence checks, listings) let
 * League\Flysystem\FilesystemException through; writes are reported as
 * WriteException.
 *
 * @mago-expect lint:too-many-methods Implements four storage contracts plus the sibling-key scheme; splitting is a follow-up.
 * @mago-expect lint:cyclomatic-complexity Implements four storage contracts plus the sibling-key scheme; splitting is a follow-up.
 * @mago-expect lint:kan-defect Implements four storage contracts plus the sibling-key scheme; splitting is a follow-up.
 */
final class S3 implements
    StorageInterface,
    BulkDeleteInterface,
    MissingVariantsReporterInterface,
    OnDemandVariantGeneratorInterface
{
    use Thumbnail;

    private const string VARIANT_SEPARATOR = '__';
    private const int COLLISION_MAX     = 1000;

    /**
     * Extensions probed (in order) when reconstructing an original key from a
     * variant key — the variant key carries the target format, not the source's.
     *
     * @var list<string>
     */
    private const array SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * Output formats an on-demand request may ask for. A variant carries only
     * dimensions/fit; the format comes from the requested key's extension (the
     * front-end asks for the source extension for the <img> fallback and avif/
     * webp for <picture> <source>s), mirroring the local on-demand resizer.
     *
     * @var list<string>
     */
    private const array GENERATABLE_FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * Per-request positive cache of keys known to exist in the bucket.
     * Populated as a side effect of list(): every key returned by
     * Flysystem::listContents() (originals and variants alike) is recorded
     * here, letting subsequent url() calls answer locally instead of paying
     * for a HEAD round-trip per asset. Cache lifetime is the PHP request —
     * long enough to cover the common "list a directory then build URLs for
     * every entry's variants in the template" loop, short enough that
     * mutations from other processes don't go unnoticed across requests.
     *
     * Keys are stored as map keys (bool true) for O(1) membership checks.
     *
     * @var array<string, true>
     */
    private array $knownKeys = [];

    private readonly UploadResolverInterface $resolver;

    /**
     * @mago-expect lint:excessive-parameter-list Published constructor, called with named arguments.
     */
    public function __construct(
        private readonly FilesystemOperator $fs,
        private readonly string $publicUrlBase,
        private readonly VariantRegistry $variants,
        private readonly ImageResizerInterface $resizer,
        ?UploadResolverInterface $resolver = null,
        private readonly ?PathVariantResolver $paths = null,
        private readonly bool $autoGenerate = false,
    ) {
        $this->resolver = $resolver ?? new DefaultUploadResolver();
    }

    /**
     * Close a stream unless the filesystem adapter already has.
     */
    private static function close(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private static function guessMimeFromName(string $name): ?string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'svg'         => 'image/svg+xml',
            'avif'        => 'image/avif',
            'mp3'         => 'audio/mpeg',
            'm4a'         => 'audio/mp4',
            'ogg', 'oga'  => 'audio/ogg',
            'wav'         => 'audio/wav',
            'mp4', 'm4v'  => 'video/mp4',
            'mov'         => 'video/quicktime',
            'webm'        => 'video/webm',
            'pdf'         => 'application/pdf',
            default       => null,
        };
    }

    private static function matchesKeyword(Entry $entry, ListOptions $options): bool
    {
        return (
            null === $options->keyword
                || '' === $options->keyword
                || false !== stripos($entry->name, $options->keyword)
        );
    }

    /**
     * Drop the in-memory cache of observed object keys. Long-running
     * workers (e.g. asset-index backfill walking thousands of rows) will
     * otherwise accumulate one entry per touched key + variant for the
     * lifetime of the process. The cache is purely an optimisation —
     * it's safe to clear at any point; subsequent `keyExists()` calls
     * just fall through to a `fileExists()` round-trip until the cache
     * is re-warmed by `list()` or `store()`.
     */
    public function clearKeyCache(): void
    {
        $this->knownKeys = [];
    }

    /**
     * @throws NotFoundException   If $path does not exist.
     * @throws WriteException      If the object cannot be deleted.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function delete(string $path): void
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        try {
            $this->fs->delete($path);
        } catch (FilesystemException $e) {
            throw new WriteException(sprintf('Failed deleting "%s": %s', $path, $e->getMessage()), previous: $e);
        }
        unset($this->knownKeys[$path]);

        /**
         * Variant cleanup is best-effort — the primary delete already
         * succeeded, and deleteMany() collects rather than throws failures.
         */
        $this->deleteMany($this->allVariantKeys($path));
    }

    #[Override]
    public function deleteMany(array $keys): array
    {
        $failed = [];
        foreach ($keys as $key) {
            $key = $this->normalisePath($key);
            try {
                /**
                 * Flysystem's delete() is idempotent, so an absent key is a
                 * satisfied request rather than something to check for first.
                 */
                $this->fs->delete($key);
                unset($this->knownKeys[$key]);
            } catch (FilesystemException $e) {
                $failed[$key] = $e->getMessage();
            }
        }

        return $failed;
    }

    /**
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function exists(string $path): bool
    {
        return $this->fs->fileExists($this->normalisePath($path));
    }

    /**
     * @throws WriteException      If the source cannot be downloaded or the variant written.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function generateForKey(string $variantKey): ?string
    {
        $variantKey = $this->normalisePath($variantKey);

        $parsed = $this->parseVariantKey($variantKey);
        if (null === $parsed) {
            return null;
        }
        [$base, $variantName, $format] = $parsed;
        $format = strtolower($format);

        if (! $this->variants->has($variantName) || ! in_array($format, self::GENERATABLE_FORMATS, strict: true)) {
            return null;
        }

        /**
         * Already materialised (or warmed by a sibling list()) — nothing to do.
         */
        if ($this->keyExists($variantKey)) {
            return $this->buildPublicUrl($variantKey);
        }

        /**
         * Honour the ownership map: the miss-proxy must not materialise a
         * family the original's path does not own.
         */
        $originalKey = $this->resolveOriginalForBase($base);
        if (null === $originalKey || ! $this->owns($originalKey, $variantName)) {
            return null;
        }

        $sourcePath = $this->copySourceToTemp($originalKey);
        try {
            $this->generateVariantFormat($sourcePath, $originalKey, $this->variants->get($variantName), $format);
        } finally {
            Warnings::suppress(unlink(...), $sourcePath);
        }
        $this->knownKeys[$variantKey] = true;

        return $this->buildPublicUrl($variantKey);
    }

    /**
     * @throws NotFoundException   If $path does not exist or is not an image.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function imageMeta(string $path): ImageMeta
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        try {
            $bytes = $this->fs->read($path);
        } catch (FilesystemException) {
            throw NotFoundException::forPath($path);
        }

        $info = Warnings::suppress(getimagesizefromstring(...), $bytes);
        if (false === $info) {
            throw NotFoundException::forPath($path);
        }

        return new ImageMeta($info[0], $info[1], $info['mime']);
    }

    #[Override]
    public function list(string $path, ?ListOptions $options = null): iterable
    {
        $options ??= new ListOptions();
        $path    = $this->normalisePath($path);

        $entries = [];
        try {
            /** @var StorageAttributes $attrs */
            foreach ($this->fs->listContents($path, deep: false) as $attrs) {
                /**
                 * Record every key the bucket is reporting at this prefix —
                 * originals AND variant siblings — before any filtering. The
                 * template's per-entry url() calls hit this cache for free
                 * instead of paying for a HEAD per asset.
                 */
                if (! $attrs->isDir()) {
                    $this->knownKeys[$attrs->path()] = true;
                }

                $name = basename($attrs->path());
                if (str_starts_with($name, '.') || $this->isVariantKey($name)) {
                    continue;
                }

                $entry = $this->buildAttributesEntry($attrs);
                if (! $options->includeDirectories && $entry->isDir || ! self::matchesKeyword($entry, $options)) {
                    continue;
                }

                $entries[] = $entry;
            }
        } catch (FilesystemException) {
            throw NotFoundException::forPath($path);
        }

        usort($entries, $this->comparator($options->sortField, $options->sortDirection));
        return $entries;
    }

    /**
     * @throws NotFoundException   If $path does not exist.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function missingVariants(string $path): array
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        return array_column($this->missingVariantEntries($path), column_key: 'key');
    }

    /**
     * @throws NotFoundException   If $path does not exist.
     * @throws WriteException      If a variant cannot be generated or written.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function regenerateMissingVariants(string $path): array
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        /**
         * Pre-pass, so we don't spill the source object to disk for an asset
         * that is already fully materialised.
         */
        $missing = $this->missingVariantEntries($path);
        if ([] === $missing) {
            return [];
        }

        $sourcePath = $this->copySourceToTemp($path);

        try {
            $generated = [];
            foreach ($missing as $entry) {
                $this->generateVariantFormat($sourcePath, $path, $entry['variant'], $entry['format']);
                $this->knownKeys[$entry['key']] = true;
                $generated[]                    = $entry['key'];
            }

            return $generated;
        } finally {
            Warnings::suppress(unlink(...), $sourcePath);
        }
    }

    /**
     * @throws NotFoundException   If $from does not exist.
     * @throws WriteException      If $to exists or the move fails.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function rename(string $from, string $to): void
    {
        $from = $this->normalisePath($from);
        $to   = $this->normalisePath($to);

        if (! $this->fs->fileExists($from)) {
            throw NotFoundException::forPath($from);
        }
        if ($this->fs->fileExists($to)) {
            throw new WriteException(sprintf('Destination "%s" already exists.', $to));
        }

        try {
            $this->fs->move($from, $to);
        } catch (FilesystemException $e) {
            throw new WriteException(
                sprintf('Failed renaming "%s" to "%s": %s', $from, $to, $e->getMessage()),
                previous: $e,
            );
        }
        unset($this->knownKeys[$from]);
        $this->knownKeys[$to] = true;

        foreach ($this->variants->all() as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $this->moveVariant(
                    $this->variantKey($from, $variant->name, $format),
                    $this->variantKey($to, $variant->name, $format),
                );
            }
        }
    }

    /**
     * @throws WriteException           If the upload or a variant cannot be written.
     * @throws UnsupportedTypeException If the upload's type cannot be detected or is not storable.
     * @throws InvalidPathException     If the client filename has no slug-safe characters.
     * @throws FilesystemException      If the bucket cannot be queried.
     */
    #[Override]
    public function store(UploadInput $upload, string $directory): Entry
    {
        if (! is_readable($upload->sourcePath)) {
            throw new WriteException(sprintf('Upload source "%s" is not readable.', $upload->sourcePath));
        }

        $directory = $this->normalisePath($directory);
        $resolved  = $this->resolver->resolve($upload);
        $finalName = $this->resolveCollision($directory, $resolved->name);
        $key       = '' === $directory ? $finalName : "{$directory}/{$finalName}";

        /**
         * ContentType comes from the resolver's DETECTED mime, never the
         * client-supplied header — the stored object advertises what it
         * actually is.
         */
        $this->upload($upload->sourcePath, $key, $resolved->mime);

        /**
         * With autoGenerate the edge (e.g. the c-d.media Worker) materialises
         * variants lazily on first request via regenerateMissingVariants(), so
         * the upload request stores only the original and returns immediately.
         */
        if (null !== $resolved->image && ! $this->autoGenerate) {
            foreach ($this->variants->allowedFor($this->paths, $key) as $variant) {
                foreach ($variant->targetFormats() as $format) {
                    $this->generateVariantFormat($upload->sourcePath, $key, $variant, $format);
                }
            }
        }

        return $this->buildEntry($key, image: $resolved->image);
    }

    /**
     * @throws FilesystemException If the bucket cannot be queried.
     */
    #[Override]
    public function url(string $path, ?string $variant = null): ?string
    {
        if (null !== $variant && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        $path = $this->normalisePath($path);
        if (! $this->keyExists($path)) {
            return null;
        }

        if (null === $variant) {
            return $this->buildPublicUrl($path);
        }

        /**
         * For multi-format variants, url() returns the first declared format
         * (callers needing a specific format use variantUrls()).
         */
        $format     = $this->variants->get($variant)->targetFormats()[0] ?? null;
        $variantKey = $this->variantKey($path, $variant, $format);

        return $this->keyExists($variantKey) ? $this->buildPublicUrl($variantKey) : null;
    }

    #[Override]
    public function urlsForKey(string $path): array
    {
        $path = $this->normalisePath($path);

        return [$this->buildPublicUrl($path), ...array_map($this->buildPublicUrl(...), $this->allVariantKeys($path))];
    }

    /**
     * URLs for every materialised format of a single variant, keyed by format
     * (e.g. ['avif' => '…', 'webp' => '…']). When the variant declares no
     * formats, the only key is 'source' and the value uses the original
     * extension. URLs are computed without existence checks so callers can
     * build `<picture>` markup without paying for HEAD round-trips.
     *
     * @return array<string, string>
     */
    #[Override]
    public function variantUrls(string $path, string $variantName): array
    {
        $variant = $this->variants->get($variantName);
        $path    = $this->normalisePath($path);

        $urls = [];
        foreach ($variant->targetFormats() as $format) {
            $urls[$format ?? 'source'] = $this->buildPublicUrl($this->variantKey($path, $variantName, $format));
        }
        return $urls;
    }

    /**
     * Every sibling key a variant of $path could occupy, materialised or not.
     *
     * @return list<string>
     */
    private function allVariantKeys(string $path): array
    {
        $keys = [];
        foreach ($this->variants->all() as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $keys[] = $this->variantKey($path, $variant->name, $format);
            }
        }

        return $keys;
    }

    private function buildAttributesEntry(StorageAttributes $attrs): Entry
    {
        $name         = basename($attrs->path());
        $isDir        = $attrs->isDir();
        $size         = 0;
        $fallbackMime = $isDir ? 'inode/directory' : 'application/octet-stream';
        $mime         = $fallbackMime;
        $mtime        = new DateTimeImmutable();

        if ($attrs instanceof FileAttributes) {
            $size = $attrs->fileSize() ?? 0;
            /**
             * S3 ListObjectsV2 doesn't return Content-Type per object, so
             * FileAttributes::mimeType() is typically null after a list call.
             * Fall back to extension-based inference so isImage()/isAudio()
             * tests in templates work without paying for a HEAD per entry.
             */
            $mime         = $attrs->mimeType() ?? self::guessMimeFromName($name) ?? $fallbackMime;
            $lastModified = $attrs->lastModified();
            if (null !== $lastModified) {
                $mtime = $mtime->setTimestamp($lastModified);
            }
        }

        return new Entry(
            id: md5($name),
            name: $name,
            path: $attrs->path(),
            isDir: $isDir,
            size: $size,
            mtime: $mtime,
            mime: $mime,
        );
    }

    private function buildEntry(string $key, ?ImageMeta $image = null): Entry
    {
        $name = basename($key);
        try {
            $size = $this->fs->fileSize($key);
        } catch (FilesystemException) {
            $size = 0;
        }
        try {
            $mtime = (new DateTimeImmutable())->setTimestamp($this->fs->lastModified($key));
        } catch (FilesystemException) {
            $mtime = new DateTimeImmutable();
        }
        try {
            $mime = $this->fs->mimeType($key);
        } catch (FilesystemException) {
            $mime = 'application/octet-stream';
        }

        return new Entry(
            id: md5($name),
            name: $name,
            path: $key,
            isDir: false,
            size: $size,
            mtime: $mtime,
            mime: $mime,
            image: $image,
        );
    }

    private function buildPublicUrl(string $key): string
    {
        return rtrim($this->publicUrlBase, characters: '/') . '/' . $key;
    }

    /** @return callable(Entry, Entry): int */
    private function comparator(SortField $field, SortDirection $dir): callable
    {
        $sign = SortDirection::Asc === $dir ? 1 : -1;

        return static function (Entry $a, Entry $b) use ($field, $sign): int {
            $cmp = match ($field) {
                SortField::Name => strcmp($a->name, $b->name),
                SortField::Time => $a->mtime <=> $b->mtime,
                SortField::Size => $a->size <=> $b->size,
                SortField::Type => strcmp($a->mime, $b->mime),
            };
            return $cmp * $sign;
        };
    }

    /**
     * Stream an object from the backend into a local temp file carrying the
     * object's extension, returning the temp path. The caller owns the file and
     * must unlink it. Reading the body once and resizing from disk is far
     * cheaper than streaming the original through ImageMagick per variant.
     *
     * @throws WriteException If the source cannot be opened or copied.
     */
    private function copySourceToTemp(string $key): string
    {
        $sourcePath = $this->tempFile('cms_s3_source_', pathinfo($key, PATHINFO_EXTENSION));

        try {
            $remote = $this->fs->readStream($key);
        } catch (FilesystemException $e) {
            Warnings::suppress(unlink(...), $sourcePath);
            throw new WriteException(
                sprintf('Failed opening source stream "%s": %s', $key, $e->getMessage()),
                previous: $e,
            );
        }

        $local = Warnings::suppress(fopen(...), $sourcePath, mode: 'wb');
        if (false === $local) {
            self::close($remote);
            Warnings::suppress(unlink(...), $sourcePath);
            throw new WriteException(sprintf('Cannot open temp file "%s" for writing.', $sourcePath));
        }

        /**
         * The temp file is removed whenever the copy did not complete, whether
         * stream_copy_to_stream() reported failure or threw, and only after
         * both streams are closed.
         */
        $copied = false;
        try {
            $copied = false !== stream_copy_to_stream($remote, $local);
        } finally {
            self::close($remote);
            self::close($local);
            if (! $copied) {
                Warnings::suppress(unlink(...), $sourcePath);
            }
        }

        if (! $copied) {
            throw new WriteException(sprintf('Failed copying source "%s" to local temp.', $key));
        }

        return $sourcePath;
    }

    /**
     * Resize $sourcePath into one variant format and upload it as a sibling of
     * $originalKey.
     *
     * @throws WriteException If the variant cannot be generated or written.
     */
    private function generateVariantFormat(
        string $sourcePath,
        string $originalKey,
        Variant $variant,
        ?string $format,
    ): void {
        /**
         * ImageMagick infers the output encoder from the destination's
         * extension, so the temp file MUST carry the target format's suffix.
         */
        $tmpPath = $this->tempFile('cms_s3_variant_', $format ?? pathinfo($originalKey, PATHINFO_EXTENSION));

        try {
            $this->resizer->resize(
                $sourcePath,
                $tmpPath,
                $variant->width,
                $variant->height,
                $variant->fit,
                $variant->quality,
            );

            $mime = Warnings::suppress(mime_content_type(...), $tmpPath);
            $this->upload(
                $tmpPath,
                $this->variantKey($originalKey, $variant->name, $format),
                false === $mime ? 'application/octet-stream' : $mime,
            );
        } finally {
            Warnings::suppress(unlink(...), $tmpPath);
        }
    }

    private function isVariantKey(string $name): bool
    {
        $dot  = strrpos($name, needle: '.');
        $base = false === $dot ? $name : substr($name, offset: 0, length: $dot);
        $sep  = strrpos($base, self::VARIANT_SEPARATOR);

        return false !== $sep && $this->variants->has(substr($base, $sep + strlen(self::VARIANT_SEPARATOR)));
    }

    /**
     * Resolve key existence, preferring the in-memory cache populated by
     * earlier list() calls. Falls back to a fileExists() round-trip when the
     * key hasn't been observed yet — preserves correctness when url() is
     * called for paths the caller didn't list first.
     *
     * @throws FilesystemException If the bucket cannot be queried.
     */
    private function keyExists(string $key): bool
    {
        if (array_key_exists($key, $this->knownKeys)) {
            return true;
        }
        if ($this->fs->fileExists($key)) {
            $this->knownKeys[$key] = true;
            return true;
        }
        return false;
    }

    /**
     * Variant/format combinations $path owns but has not materialised.
     *
     * @return list<array{variant: Variant, format: ?string, key: string}>
     *
     * @throws FilesystemException If the bucket cannot be queried.
     */
    private function missingVariantEntries(string $path): array
    {
        $missing = [];
        foreach ($this->variants->allowedFor($this->paths, $path) as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $variantKey = $this->variantKey($path, $variant->name, $format);
                if (! $this->keyExists($variantKey)) {
                    $missing[] = ['variant' => $variant, 'format' => $format, 'key' => $variantKey];
                }
            }
        }

        return $missing;
    }

    /**
     * Move one variant sibling, if it exists. Best-effort — the primary
     * rename has already succeeded, so a failure here leaves the sibling
     * behind rather than failing the call.
     */
    private function moveVariant(string $from, string $to): void
    {
        try {
            if (! $this->fs->fileExists($from)) {
                return;
            }
            $this->fs->move($from, $to);
        } catch (FilesystemException) {
            return;
        }

        unset($this->knownKeys[$from]);
        $this->knownKeys[$to] = true;
    }

    private function normalisePath(string $path): string
    {
        return trim(str_replace(
            search: '\\',
            replace: '/',
            subject: $path,
        ), characters: '/');
    }

    /**
     * Whether the ownership map lets $originalKey materialise $variantName.
     * An absent or unconfigured map allows every variant.
     */
    private function owns(string $originalKey, string $variantName): bool
    {
        return (
            null === $this->paths
                || ! $this->paths->isConfigured()
                || $this->paths->allows($originalKey, $variantName)
        );
    }

    /**
     * Split a variant sibling key into [baseWithoutExtension, variantName, format].
     * Returns null when the key carries no extension or no variant separator.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function parseVariantKey(string $key): ?array
    {
        $dot = strrpos($key, needle: '.');
        if (false === $dot) {
            return null;
        }
        $format = substr($key, $dot + 1);
        $stem   = substr($key, offset: 0, length: $dot);

        $sep = strrpos($stem, self::VARIANT_SEPARATOR);
        if (false === $sep) {
            return null;
        }
        $base        = substr($stem, offset: 0, length: $sep);
        $variantName = substr($stem, $sep + strlen(self::VARIANT_SEPARATOR));
        if ('' === $base || '' === $variantName) {
            return null;
        }

        return [$base, $variantName, $format];
    }

    /**
     * @throws WriteException      If no free name is found.
     * @throws FilesystemException If the bucket cannot be queried.
     */
    private function resolveCollision(string $directory, string $filename): string
    {
        $prefix = '' === $directory ? '' : "{$directory}/";
        if (! $this->fs->fileExists($prefix . $filename)) {
            return $filename;
        }
        $dot  = strrpos($filename, needle: '.');
        $base = false === $dot ? $filename : substr($filename, offset: 0, length: $dot);
        $ext  = false === $dot ? '' : substr($filename, $dot);
        for ($i = 1; $i < self::COLLISION_MAX; ++$i) {
            $candidate = sprintf('%s_%d%s', $base, $i, $ext);
            if (! $this->fs->fileExists($prefix . $candidate)) {
                return $candidate;
            }
        }
        throw new WriteException(sprintf('Cannot allocate unique filename in "%s".', $directory));
    }

    /**
     * Locate the original object for a variant key's base (extension stripped)
     * by probing the known source extensions.
     *
     * @throws FilesystemException If the bucket cannot be queried.
     */
    private function resolveOriginalForBase(string $base): ?string
    {
        foreach (self::SOURCE_EXTENSIONS as $ext) {
            $candidate = "{$base}.{$ext}";
            if ($this->keyExists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Allocate a temp file whose name ends in ".$extension" (".tmp" when the
     * extension is empty).
     *
     * @throws WriteException If no temp file can be created.
     */
    private function tempFile(string $prefix, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if (false === $base) {
            throw new WriteException('Cannot allocate a temp file.');
        }

        $path = $base . '.' . ('' === $extension ? 'tmp' : $extension);
        Warnings::suppress(rename(...), $base, $path);

        return $path;
    }

    /**
     * Upload a local file to $key.
     *
     * @throws WriteException If the file cannot be opened or written.
     */
    private function upload(string $localPath, string $key, string $mime): void
    {
        $stream = Warnings::suppress(fopen(...), $localPath, mode: 'rb');
        if (false === $stream) {
            throw new WriteException(sprintf('Failed opening "%s" for upload.', $localPath));
        }

        try {
            $this->fs->writeStream($key, $stream, ['ContentType' => $mime]);
        } catch (FilesystemException $e) {
            throw new WriteException(sprintf('Failed writing "%s": %s', $key, $e->getMessage()), previous: $e);
        } finally {
            self::close($stream);
        }
    }

    private function variantKey(string $key, string $variantName, ?string $format = null): string
    {
        $dot  = strrpos($key, needle: '.');
        $base = false === $dot ? $key : substr($key, offset: 0, length: $dot);
        $ext  = match (true) {
            null !== $format => ".{$format}",
            false !== $dot => substr($key, $dot),
            default => '',
        };

        return $base . self::VARIANT_SEPARATOR . $variantName . $ext;
    }
}
