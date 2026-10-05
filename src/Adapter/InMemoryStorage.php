<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
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
use Contenir\Storage\VariantRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;

use function array_key_exists;
use function array_keys;
use function basename;
use function file_get_contents;
use function is_readable;
use function md5;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcmp;
use function stripos;
use function strlen;
use function substr;
use function trim;
use function usort;

/**
 * Hand-rolled in-memory backend used by tests that need to inject the contract
 * without touching disk or network.
 *
 * The fake intentionally does not perform any image transformation — variant
 * URLs are emitted deterministically from the registered variant name so tests
 * can assert on them, but no derived bytes are produced. Real image processing
 * lives in the production backends and is verified by their integration tests.
 *
 * @mago-expect lint:too-many-methods Implements the full StorageInterface plus seeding helpers.
 * @mago-expect lint:cyclomatic-complexity Complexity is the sum of the interface methods it implements.
 * @mago-expect lint:kan-defect Complexity is the sum of the interface methods it implements.
 */
final class InMemoryStorage implements StorageInterface, MissingVariantsReporterInterface
{
    use Thumbnail;

    /** @var array<string, array{isDir: bool, bytes: string, mime: string, mtime: DateTimeImmutable, image: ?ImageMeta}> */
    private array $nodes = [];

    private readonly UploadResolverInterface $resolver;

    public function __construct(
        private readonly VariantRegistry $variants = new VariantRegistry(),
        private readonly string $urlScheme = 'memory://',
        ?UploadResolverInterface $resolver = null,
    ) {
        $this->resolver = $resolver ?? new DefaultUploadResolver();
    }

    #[Override]
    public function delete(string $path): void
    {
        $path = $this->normalise($path);
        if (! array_key_exists($path, $this->nodes)) {
            throw NotFoundException::forPath($path);
        }
        unset($this->nodes[$path]);
    }

    #[Override]
    public function exists(string $path): bool
    {
        return array_key_exists($this->normalise($path), $this->nodes);
    }

    #[Override]
    public function imageMeta(string $path): ImageMeta
    {
        $path  = $this->normalise($path);
        $image = ($this->nodes[$path] ?? null)['image'] ?? null;
        if (null === $image) {
            throw NotFoundException::forPath($path);
        }

        return $image;
    }

    #[Override]
    public function list(string $path, ?ListOptions $options = null): iterable
    {
        $path    = $this->normalise($path);
        $options ??= new ListOptions();
        $prefix  = '' === $path ? '' : "{$path}/";

        $directory = $this->nodes[$path] ?? null;
        if ('' !== $path && null !== $directory && ! $directory['isDir']) {
            throw NotFoundException::forPath($path);
        }
        if ('' !== $path && null === $directory && ! $this->hasDescendants($prefix)) {
            throw NotFoundException::forPath($path);
        }

        $entries = [];
        foreach ($this->nodes as $key => $node) {
            if ($key === $path) {
                continue;
            }
            if ('' !== $prefix && ! str_starts_with($key, $prefix)) {
                continue;
            }
            $remainder = substr($key, strlen($prefix));
            if (str_contains($remainder, '/')) {
                continue;
            }

            $entry = $this->entryFor($key, $node);

            if (! $options->includeDirectories && $entry->isDir) {
                continue;
            }
            if (null !== $options->keyword && false === stripos($entry->name, $options->keyword)) {
                continue;
            }

            $entries[] = $entry;
        }

        usort($entries, $this->comparator($options->sortField, $options->sortDirection));

        return $entries;
    }

    public function makeDirectory(string $path): void
    {
        $path               = $this->normalise($path);
        $this->nodes[$path] = [
            'isDir' => true,
            'bytes' => '',
            'mime'  => 'inode/directory',
            'mtime' => new DateTimeImmutable(),
            'image' => null,
        ];
    }

    #[Override]
    public function missingVariants(string $path): array
    {
        // Nothing is materialised here, so nothing is ever reported missing —
        // but an unknown original must still fail the same way as elsewhere.
        $path = $this->normalise($path);
        if (! $this->isFile($path)) {
            throw NotFoundException::forPath($path);
        }

        return [];
    }

    public function putFile(
        string $path,
        string $bytes,
        string $mime = 'application/octet-stream',
        ?int $width = null,
        ?int $height = null,
    ): void {
        $this->nodes[$this->normalise($path)] = $this->fileNode($bytes, $mime, $width, $height);
    }

    #[Override]
    public function regenerateMissingVariants(string $path): array
    {
        /**
         * The in-memory adapter doesn't materialise variants — they're only
         * tracked when callers explicitly `putFile()` them. Surface the
         * NotFoundException for missing originals so tests can assert the
         * same failure shape across adapters; otherwise return [].
         */
        $path = $this->normalise($path);
        if (! $this->isFile($path)) {
            throw NotFoundException::forPath($path);
        }
        return [];
    }

    #[Override]
    public function rename(string $from, string $to): void
    {
        $from = $this->normalise($from);
        $to   = $this->normalise($to);
        if (! array_key_exists($from, $this->nodes)) {
            throw NotFoundException::forPath($from);
        }
        $this->nodes[$to] = $this->nodes[$from];
        unset($this->nodes[$from]);
    }

    #[Override]
    public function store(UploadInput $upload, string $directory): Entry
    {
        if (! is_readable($upload->sourcePath)) {
            throw new WriteException(sprintf('Cannot read upload source "%s".', $upload->sourcePath));
        }

        $bytes = Warnings::suppress(file_get_contents(...), $upload->sourcePath);
        if (false === $bytes) {
            throw new WriteException(sprintf('Failed reading upload source "%s".', $upload->sourcePath));
        }

        $resolved = $this->resolver->resolve($upload);
        $path     = $this->normalise(rtrim($directory, characters: '/') . '/' . $resolved->name);

        $node = $this->fileNode(
            $bytes,
            $resolved->mime,
            $resolved->image?->width,
            $resolved->image?->height,
        );
        $this->nodes[$path] = $node;

        return $this->entryFor($path, $node, $resolved->image);
    }

    #[Override]
    public function url(string $path, ?string $variant = null): ?string
    {
        if (null !== $variant && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        $path = $this->normalise($path);
        if (! $this->isFile($path)) {
            return null;
        }

        return null === $variant
            ? $this->urlScheme . $path
            : sprintf('%s%s?v=%s', $this->urlScheme, $path, $variant);
    }

    #[Override]
    public function urlsForKey(string $path): array
    {
        $path = $this->normalise($path);
        $urls = [$this->urlScheme . $path];
        foreach ($this->variants->all() as $variant) {
            $urls[] = sprintf('%s%s?v=%s', $this->urlScheme, $path, $variant->name);
        }
        return $urls;
    }

    #[Override]
    public function variantUrls(string $path, string $variantName): array
    {
        $variant = $this->variants->get($variantName);
        $path    = $this->normalise($path);

        $urls = [];
        foreach ($variant->targetFormats() as $format) {
            $key        = $format ?? 'source';
            $urls[$key] = null === $format
                ? sprintf('%s%s?v=%s', $this->urlScheme, $path, $variantName)
                : sprintf('%s%s?v=%s&f=%s', $this->urlScheme, $path, $variantName, $format);
        }
        return $urls;
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
     * @param array{isDir: bool, bytes: string, mime: string, mtime: DateTimeImmutable, image: ?ImageMeta} $node
     */
    private function entryFor(string $path, array $node, ?ImageMeta $image = null): Entry
    {
        $name = basename($path);

        return new Entry(
            id: md5($name),
            name: $name,
            path: $path,
            isDir: $node['isDir'],
            size: strlen($node['bytes']),
            mtime: $node['mtime'],
            mime: $node['mime'],
            image: $image,
        );
    }

    /**
     * @return array{isDir: false, bytes: string, mime: string, mtime: DateTimeImmutable, image: ?ImageMeta}
     */
    private function fileNode(string $bytes, string $mime, ?int $width, ?int $height): array
    {
        return [
            'isDir' => false,
            'bytes' => $bytes,
            'mime'  => $mime,
            'mtime' => new DateTimeImmutable(),
            'image' => null === $width || null === $height ? null : new ImageMeta($width, $height, $mime),
        ];
    }

    private function hasDescendants(string $prefix): bool
    {
        foreach (array_keys($this->nodes) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isFile(string $path): bool
    {
        return array_key_exists($path, $this->nodes) && ! $this->nodes[$path]['isDir'];
    }

    private function normalise(string $path): string
    {
        return trim($path, characters: '/');
    }
}
