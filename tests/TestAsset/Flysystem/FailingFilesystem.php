<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\TestAsset\Flysystem;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

use function array_key_exists;

/**
 * An in-memory Flysystem whose individual operations can be made to fail
 * for one location, standing in for an S3 bucket that errors part-way.
 *
 * Like S3's ListObjectsV2, listings carry no MIME type unless
 * $listsMimeTypes is set.
 */
final class FailingFilesystem implements FilesystemOperator
{
    public readonly Filesystem $inner;

    public bool $listsMimeTypes = false;

    /** @var array<string, true> operation:location pairs that throw. */
    private array $failures = [];

    public function __construct()
    {
        $this->inner = new Filesystem(new InMemoryFilesystemAdapter());
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->inner->copy($source, $destination, $config);
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $this->inner->createDirectory($location, $config);
    }

    public function delete(string $location): void
    {
        if ($this->fails('delete', $location)) {
            throw UnableToDeleteFile::atLocation($location, 'delete refused');
        }
        $this->inner->delete($location);
    }

    public function deleteDirectory(string $location): void
    {
        $this->inner->deleteDirectory($location);
    }

    public function directoryExists(string $location): bool
    {
        return $this->inner->directoryExists($location);
    }

    public function failOn(string $operation, string $location): self
    {
        $this->failures["{$operation}:{$location}"] = true;

        return $this;
    }

    public function fileExists(string $location): bool
    {
        if ($this->fails('fileExists', $location)) {
            throw UnableToCheckFileExistence::forLocation($location);
        }

        return $this->inner->fileExists($location);
    }

    public function fileSize(string $path): int
    {
        if ($this->fails('fileSize', $path)) {
            throw UnableToRetrieveMetadata::fileSize($path, 'size refused');
        }

        return $this->inner->fileSize($path);
    }

    public function has(string $location): bool
    {
        return $this->inner->has($location);
    }

    public function lastModified(string $path): int
    {
        if ($this->fails('lastModified', $path)) {
            throw UnableToRetrieveMetadata::lastModified($path, 'mtime refused');
        }

        return $this->inner->lastModified($path);
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        if ($this->fails('listContents', $location)) {
            throw UnableToListContents::atLocation($location, $deep, new RuntimeException('list refused'));
        }

        $listing = $this->inner->listContents($location, $deep);
        if ($this->listsMimeTypes) {
            return $listing;
        }

        return $listing->map(static fn(StorageAttributes $attributes): StorageAttributes => $attributes
            instanceof FileAttributes
                ? new FileAttributes(
                    $attributes->path(),
                    $attributes->fileSize(),
                    $attributes->visibility(),
                    $attributes->lastModified(),
                )
                : $attributes);
    }

    public function mimeType(string $path): string
    {
        if ($this->fails('mimeType', $path)) {
            throw UnableToRetrieveMetadata::mimeType($path, 'mime refused');
        }

        return $this->inner->mimeType($path);
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        if ($this->fails('move', $source)) {
            throw UnableToMoveFile::because('move refused', $source, $destination);
        }
        $this->inner->move($source, $destination, $config);
    }

    public function read(string $location): string
    {
        if ($this->fails('read', $location)) {
            throw UnableToReadFile::fromLocation($location, 'read refused');
        }

        return $this->inner->read($location);
    }

    public function readStream(string $location)
    {
        if ($this->fails('readStream', $location)) {
            throw UnableToReadFile::fromLocation($location, 'read refused');
        }

        return $this->inner->readStream($location);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): string
    {
        return $this->inner->visibility($path);
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->inner->write($location, $contents, $config);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        if ($this->fails('writeStream', $location)) {
            throw UnableToWriteFile::atLocation($location, 'write refused');
        }
        $this->inner->writeStream($location, $contents, $config);
    }

    private function fails(string $operation, string $location): bool
    {
        return array_key_exists("{$operation}:{$location}", $this->failures);
    }
}
