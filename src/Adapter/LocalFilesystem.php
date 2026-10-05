<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\Internal\Warnings;
use Contenir\Storage\ListOptions;
use Contenir\Storage\MissingVariantsReporterInterface;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantRegistry;
use DateTimeImmutable;
use DirectoryIterator;
use InvalidArgumentException;
use Override;
use Throwable;

use function basename;
use function copy;
use function dirname;
use function file_exists;
use function filemtime;
use function filesize;
use function getimagesize;
use function in_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_writable;
use function md5;
use function mime_content_type;
use function mkdir;
use function preg_match;
use function realpath;
use function rename;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function stripos;
use function strrpos;
use function substr;
use function trim;
use function unlink;
use function usort;

use const DIRECTORY_SEPARATOR;

/**
 * Local-filesystem implementation of StorageInterface.
 *
 * Storage shape:
 *   $rootPath/<directory>/<filename>                     - the asset itself
 *   $rootPath/<directory>/_variant/<variant>/<filename>   - eager variants
 *
 * Entry::$id is md5(name) so the JS layer's existing delete/rename
 * payloads continue to round-trip without change.
 *
 * @mago-expect lint:too-many-methods Implements the full StorageInterface plus its path guard; splitting is a follow-up.
 * @mago-expect lint:cyclomatic-complexity Implements the full StorageInterface plus its path guard; splitting is a follow-up.
 * @mago-expect lint:kan-defect Implements the full StorageInterface plus its path guard; splitting is a follow-up.
 */
final class LocalFilesystem implements StorageInterface, MissingVariantsReporterInterface
{
    use Thumbnail;

    private const string VARIANT_DIR = '_variant';

    /**
     * Names list() never reports: OS metadata files and the variant directory.
     * Dot files (".DS_Store" included) are skipped separately.
     */
    private const array SKIPPED_NAMES = ['Thumbs.db', self::VARIANT_DIR];

    private const int COLLISION_MAX = 1000;

    private readonly UploadResolverInterface $resolver;

    /**
     * @mago-expect lint:excessive-parameter-list Published constructor, called with named arguments.
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly string $publicPath,
        private readonly VariantRegistry $variants,
        private readonly ImageResizerInterface $resizer,
        ?UploadResolverInterface $resolver = null,
        private readonly ?PathVariantResolver $paths = null,
    ) {
        $this->resolver = $resolver ?? new DefaultUploadResolver();
    }

    #[Override]
    public function delete(string $path): void
    {
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! file_exists($absolute)) {
            throw NotFoundException::forPath($path);
        }
        if (is_dir($absolute)) {
            throw new WriteException(sprintf('Cannot delete directory "%s" via delete().', $path));
        }

        foreach ($this->variants->all() as $variant) {
            $variantAbs = $this->resolveAbsolutePath($this->variantRelativePath($path, $variant->name));
            if (is_file($variantAbs)) {
                Warnings::suppress(unlink(...), $variantAbs);
            }
        }

        if (! Warnings::suppress(unlink(...), $absolute)) {
            throw new WriteException(sprintf('Failed deleting "%s".', $path));
        }
    }

    #[Override]
    public function exists(string $path): bool
    {
        $path = $this->normalisePath($path);
        return file_exists($this->resolveAbsolutePath($path));
    }

    #[Override]
    public function imageMeta(string $path): ImageMeta
    {
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_file($absolute)) {
            throw NotFoundException::forPath($path);
        }

        $info = Warnings::suppress(getimagesize(...), $absolute);
        if (false === $info) {
            throw NotFoundException::forPath($path);
        }

        return new ImageMeta($info[0], $info[1], $info['mime']);
    }

    #[Override]
    public function list(string $path, ?ListOptions $options = null): iterable
    {
        $options  ??= new ListOptions();
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_dir($absolute)) {
            throw NotFoundException::forPath($path);
        }

        $entries = [];
        foreach (new DirectoryIterator($absolute) as $info) {
            if ($info->isDot()) {
                continue;
            }
            $name = $info->getFilename();
            if (str_starts_with($name, '.') || in_array($name, self::SKIPPED_NAMES, strict: true)) {
                continue;
            }

            $entry = $this->buildEntry('' === $path ? $name : "{$path}/{$name}");

            if (! $options->includeDirectories && $entry->isDir) {
                continue;
            }
            if (
                null !== $options->keyword
                && '' !== $options->keyword
                && false === stripos($entry->name, $options->keyword)
            ) {
                continue;
            }

            $entries[] = $entry;
        }

        usort($entries, $this->comparator($options->sortField, $options->sortDirection));
        return $entries;
    }

    /**
     * Local-FS-only escape hatch: return the absolute filesystem path for a
     * stored asset. Callers that need to hand the file to a non-storage-aware
     * routine (e.g. mime sniffing via mime_content_type) use this instead of
     * concatenating the storage root directly.
     *
     * @throws InvalidPathException If $path is unsafe.
     */
    public function localPath(string $path): string
    {
        return $this->resolveAbsolutePath($this->normalisePath($path));
    }

    #[Override]
    public function missingVariants(string $path): array
    {
        $path = $this->normalisePath($path);
        if (! is_file($this->resolveAbsolutePath($path))) {
            throw NotFoundException::forPath($path);
        }

        $missing = [];
        foreach ($this->variants->allowedFor($this->paths, $path) as $variant) {
            $variantRel = $this->variantRelativePath($path, $variant->name);
            if (! is_file($this->resolveAbsolutePath($variantRel))) {
                $missing[] = $variantRel;
            }
        }

        return $missing;
    }

    #[Override]
    public function regenerateMissingVariants(string $path): array
    {
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_file($absolute)) {
            throw NotFoundException::forPath($path);
        }

        /**
         * The LocalFilesystem layout stores one file per variant under
         * `_variant/<variantName>/<basename>` — same extension as the source
         * (no per-format suffix). Format iteration is therefore a no-op
         * here: each variant materialises as a single file matching the
         * source extension.
         */
        $generated = [];
        foreach ($this->variants->allowedFor($this->paths, $path) as $variant) {
            $variantRel = $this->variantRelativePath($path, $variant->name);
            $variantAbs = $this->resolveAbsolutePath($variantRel);
            if (is_file($variantAbs)) {
                continue;
            }

            try {
                $this->generateVariant($path, $variant);
            } catch (Throwable $e) {
                throw new WriteException(
                    sprintf('Failed regenerating variant "%s" for "%s": %s', $variant->name, $path, $e->getMessage()),
                    previous: $e,
                );
            }

            $generated[] = $variantRel;
        }

        return $generated;
    }

    #[Override]
    public function rename(string $from, string $to): void
    {
        $from         = $this->normalisePath($from);
        $to           = $this->normalisePath($to);
        $absoluteFrom = $this->resolveAbsolutePath($from);
        $absoluteTo   = $this->resolveAbsolutePath($to);

        if (! file_exists($absoluteFrom)) {
            throw NotFoundException::forPath($from);
        }
        if (file_exists($absoluteTo)) {
            throw new WriteException(sprintf('Destination "%s" already exists.', $to));
        }

        $destDir = dirname($absoluteTo);
        if (! $this->ensureDirectory($destDir)) {
            throw new WriteException(sprintf('Cannot create directory "%s".', $destDir));
        }
        if (! Warnings::suppress(rename(...), $absoluteFrom, $absoluteTo)) {
            throw new WriteException(sprintf('Failed renaming "%s" to "%s".', $from, $to));
        }

        foreach ($this->variants->all() as $variant) {
            $varFrom = $this->resolveAbsolutePath($this->variantRelativePath($from, $variant->name));
            $varTo   = $this->resolveAbsolutePath($this->variantRelativePath($to, $variant->name));
            if (! is_file($varFrom)) {
                continue;
            }
            $this->ensureDirectory(dirname($varTo));
            Warnings::suppress(rename(...), $varFrom, $varTo);
        }
    }

    #[Override]
    public function store(UploadInput $upload, string $directory): Entry
    {
        $directory   = $this->normalisePath($directory);
        $absoluteDir = $this->resolveAbsolutePath($directory);

        if (! is_readable($upload->sourcePath)) {
            throw new WriteException(sprintf('Upload source "%s" is not readable.', $upload->sourcePath));
        }
        if (! $this->ensureDirectory($absoluteDir)) {
            throw new WriteException(sprintf('Cannot create directory "%s".', $absoluteDir));
        }
        if (! is_writable($absoluteDir)) {
            throw new WriteException(sprintf('Directory "%s" is not writable.', $absoluteDir));
        }

        $resolved  = $this->resolver->resolve($upload);
        $finalName = $this->resolveCollision($absoluteDir, $resolved->name);
        $destAbs   = $absoluteDir . DIRECTORY_SEPARATOR . $finalName;

        if (! Warnings::suppress(copy(...), $upload->sourcePath, $destAbs)) {
            throw new WriteException(sprintf('Failed copying upload to "%s".', $destAbs));
        }

        $relativePath = '' === $directory ? $finalName : "{$directory}/{$finalName}";

        if (null !== $resolved->image) {
            foreach ($this->variants->allowedFor($this->paths, $relativePath) as $variant) {
                $this->generateVariant($relativePath, $variant);
            }
        }

        return $this->buildEntry($relativePath, $resolved->image);
    }

    #[Override]
    public function url(string $path, ?string $variant = null): ?string
    {
        if (null !== $variant && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_file($absolute)) {
            return null;
        }

        if (null === $variant) {
            return $this->buildPublicUrl($path);
        }

        $variantRel = $this->variantRelativePath($path, $variant);
        $variantAbs = $this->resolveAbsolutePath($variantRel);

        /**
         * Lazy generation: if the variant hasn't been materialised but the
         * original is a real image, resize on demand. This covers files that
         * landed on disk by any route other than store() (legacy data, SCP,
         * rsync, manual drops). One-time cost per asset; subsequent requests
         * hit the cached sibling. A resizer failure (corrupt image, missing
         * ImageMagick, permissions) leaves the variant unmaterialised so the
         * caller falls back to the original URL.
         */
        if (! is_file($variantAbs) && false !== Warnings::suppress(getimagesize(...), $absolute)) {
            try {
                $this->generateVariant($path, $this->variants->get($variant));
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_file($variantAbs)) {
            return null;
        }

        return $this->buildPublicUrl($variantRel);
    }

    #[Override]
    public function urlsForKey(string $path): array
    {
        $path = $this->normalisePath($path);
        $urls = [$this->buildPublicUrl($path)];
        foreach ($this->variants->all() as $variant) {
            $urls[] = $this->buildPublicUrl($this->variantRelativePath($path, $variant->name));
        }
        return $urls;
    }

    #[Override]
    public function variantUrls(string $path, string $variantName): array
    {
        if (! $this->variants->has($variantName)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variantName));
        }
        $variant = $this->variants->get($variantName);
        $path    = $this->normalisePath($path);

        /**
         * LocalFilesystem stores a variant as a single sibling file under
         * _variant/<name>/ with the original extension — there is no per-format
         * suffix, so every declared format resolves to the same URL.
         */
        $url  = $this->buildPublicUrl($this->variantRelativePath($path, $variantName));
        $urls = [];
        foreach ($variant->targetFormats() as $format) {
            $urls[$format ?? 'source'] = $url;
        }
        return $urls;
    }

    /**
     * @throws InvalidPathException If $relativePath is unsafe.
     */
    private function buildEntry(string $relativePath, ?ImageMeta $image = null): Entry
    {
        $absolute = $this->resolveAbsolutePath($relativePath);
        $name     = basename($relativePath);
        $isDir    = is_dir($absolute);
        $mime     = $isDir ? 'inode/directory' : Warnings::suppress(mime_content_type(...), $absolute);

        return new Entry(
            id: md5($name),
            name: $name,
            path: $relativePath,
            isDir: $isDir,
            size: $isDir ? 0 : (int) Warnings::suppress(filesize(...), $absolute),
            mtime: (new DateTimeImmutable())->setTimestamp((int) Warnings::suppress(filemtime(...), $absolute)),
            mime: false === $mime ? 'application/octet-stream' : $mime,
            image: $image,
        );
    }

    private function buildPublicUrl(string $relativePath): string
    {
        return rtrim($this->publicPath, characters: '/') . '/' . $relativePath;
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
     * Create $directory and any missing parents. True when it exists afterwards,
     * including when a concurrent request created it first.
     */
    private function ensureDirectory(string $directory): bool
    {
        return (
            is_dir($directory)
                || Warnings::suppress(mkdir(...), $directory, permissions: 0o777, recursive: true)
                || is_dir($directory)
        );
    }

    /**
     * @throws InvalidPathException If $relativePath is unsafe.
     * @throws WriteException       If the resizer cannot write the variant.
     */
    private function generateVariant(string $relativePath, Variant $variant): void
    {
        $sourceAbs = $this->resolveAbsolutePath($relativePath);
        $destAbs   = $this->resolveAbsolutePath($this->variantRelativePath($relativePath, $variant->name));

        $this->resizer->resize(
            $sourceAbs,
            $destAbs,
            $variant->width,
            $variant->height,
            $variant->fit,
            $variant->quality,
        );
    }

    private function normalisePath(string $path): string
    {
        return trim(str_replace(
            search: '\\',
            replace: '/',
            subject: $path,
        ), characters: '/');
    }

    private function relativeDirname(string $relativePath): string
    {
        $dir = dirname($relativePath);

        return '.' === $dir ? '' : $dir;
    }

    /**
     * @throws InvalidPathException If $relative holds a null byte or traversal,
     *                              or resolves outside the storage root.
     */
    private function resolveAbsolutePath(string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw InvalidPathException::forNullByte($relative);
        }
        if (1 === preg_match('#(?:^|/)\.\.(?:/|$)#', $relative) || false !== stripos($relative, needle: '%2e%2e')) {
            throw InvalidPathException::forTraversal($relative);
        }

        $absolute = '' === $relative
            ? $this->rootPath
            : $this->rootPath . DIRECTORY_SEPARATOR . $relative;

        /**
         * Only an existing path can be resolved through symlinks; when it
         * exists, so does the root it sits under.
         */
        $real     = realpath($absolute);
        $realRoot = (string) realpath($this->rootPath);
        if (false !== $real && $real !== $realRoot && ! str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
            throw InvalidPathException::forEscape($relative);
        }

        return $absolute;
    }

    /**
     * @throws WriteException If no free name is found.
     */
    private function resolveCollision(string $absoluteDir, string $filename): string
    {
        if (! file_exists($absoluteDir . DIRECTORY_SEPARATOR . $filename)) {
            return $filename;
        }
        $dot  = strrpos($filename, needle: '.');
        $base = false === $dot ? $filename : substr($filename, offset: 0, length: $dot);
        $ext  = false === $dot ? '' : substr($filename, $dot);
        for ($i = 1; $i < self::COLLISION_MAX; ++$i) {
            $candidate = sprintf('%s_%d%s', $base, $i, $ext);
            if (! file_exists($absoluteDir . DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }
        throw new WriteException(sprintf('Cannot allocate unique filename in "%s".', $absoluteDir));
    }

    private function variantRelativePath(string $relativePath, string $variantName): string
    {
        $dir  = $this->relativeDirname($relativePath);
        $name = basename($relativePath);
        $base = '' === $dir ? '' : "{$dir}/";
        return sprintf('%s%s/%s/%s', $base, self::VARIANT_DIR, $variantName, $name);
    }
}
