<?php

declare(strict_types=1);

namespace Contenir\Storage;

use DateTimeImmutable;

use function str_starts_with;

/**
 * A single item returned by StorageInterface::list() or ::store().
 *
 * $id is a stable identifier the JS layer round-trips on delete/rename
 * operations. Local backends compute it as md5(name) to preserve the existing
 * FileBrowser contract; cloud backends derive it from the object key.
 *
 * $path is relative to the storage root (e.g. "products/hero.jpg"), never
 * absolute. The mapping to a public URL is the backend's responsibility — see
 * StorageInterface::url().
 *
 * For directories, $mime is "inode/directory" so isImage() correctly returns
 * false without a separate type check.
 *
 * $image carries the dimensions detected at store() time for raster images;
 * it is null for directories, non-images, and entries produced by list() (which
 * does not sniff dimensions). Callers persist it as provenance metadata.
 */
final class Entry
{
    /**
     * @mago-expect lint:excessive-parameter-list Published value-object constructor, called with named arguments.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $path,
        public readonly bool $isDir,
        public readonly int $size,
        public readonly DateTimeImmutable $mtime,
        public readonly string $mime,
        public readonly ?ImageMeta $image = null,
    ) {}

    public function isFile(): bool
    {
        return ! $this->isDir;
    }

    public function isImage(): bool
    {
        return ! $this->isDir && str_starts_with($this->mime, 'image/');
    }
}
