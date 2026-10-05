<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Trait;

use Contenir\Storage\Tests\TestAsset\Image\PngFactory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_reverse;
use function chmod;
use function dirname;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function mkdir;
use function posix_geteuid;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * A private scratch directory per test, removed afterwards even when a test
 * left read-only directories or files behind.
 */
trait TemporaryDirectoryTrait
{
    private string $tmpDir;

    protected function setUpTemporaryDirectory(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/contenir-storage-' . uniqid(more_entropy: true);
        mkdir($this->tmpDir, permissions: 0o777, recursive: true);
    }

    protected function tearDownTemporaryDirectory(): void
    {
        if (! is_dir($this->tmpDir)) {
            return;
        }

        chmod($this->tmpDir, permissions: 0o755);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var list<SplFileInfo> $found */
        $found = [];
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if (! $item->isLink()) {
                chmod($item->getPathname(), permissions: $item->isDir() ? 0o755 : 0o644);
            }
            $found[] = $item;
        }

        foreach (array_reverse($found) as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->tmpDir);
    }

    private function path(string $relative = ''): string
    {
        return '' === $relative ? $this->tmpDir : "{$this->tmpDir}/{$relative}";
    }

    private function skipWhenRunningAsRoot(): void
    {
        if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('Running as root bypasses filesystem permission checks.');
        }
    }

    /**
     * Write $contents under the scratch directory, creating parent
     * directories, and return the absolute path.
     */
    private function writeFile(string $relative, string $contents): string
    {
        $path = $this->path($relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), permissions: 0o777, recursive: true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Write a black RGB PNG of the given size and return its absolute path.
     */
    private function writePng(string $relative, int $width, int $height): string
    {
        return $this->writeFile($relative, PngFactory::bytes($width, $height));
    }
}
