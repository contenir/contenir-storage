<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Entry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function md5;

#[Group('unit')]
#[Group('storage')]
final class EntryTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function imageMimeProvider(): array
    {
        return [
            'jpeg'    => ['image/jpeg'],
            'png'     => ['image/png'],
            'gif'     => ['image/gif'],
            'webp'    => ['image/webp'],
            'svg+xml' => ['image/svg+xml'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function nonImageMimeProvider(): array
    {
        return [
            'plain text'   => ['text/plain'],
            'pdf'          => ['application/pdf'],
            'octet stream' => ['application/octet-stream'],
            'html'         => ['text/html'],
        ];
    }

    #[Test]
    public function isFileReturnsFalseForDirectory(): void
    {
        $entry = $this->makeEntry(
            isDir: true,
            mime: 'inode/directory',
        );

        static::assertFalse($entry->isFile());
    }

    #[Test]
    public function isFileReturnsTrueForNonDirectory(): void
    {
        $entry = $this->makeEntry(
            isDir: false,
            mime: 'image/jpeg',
        );

        static::assertTrue($entry->isFile());
    }

    #[Test]
    public function isImageReturnsFalseForDirectoryEvenIfMimeIsImage(): void
    {
        $entry = $this->makeEntry(
            isDir: true,
            mime: 'image/jpeg',
        );

        static::assertFalse($entry->isImage());
    }

    #[Test]
    #[DataProvider('nonImageMimeProvider')]
    public function isImageReturnsFalseForNonImageMimeTypes(string $mime): void
    {
        $entry = $this->makeEntry(
            isDir: false,
            mime: $mime,
        );

        static::assertFalse($entry->isImage());
    }

    #[Test]
    #[DataProvider('imageMimeProvider')]
    public function isImageReturnsTrueForImageMimeTypes(string $mime): void
    {
        $entry = $this->makeEntry(
            isDir: false,
            mime: $mime,
        );

        static::assertTrue($entry->isImage());
    }

    private function makeEntry(bool $isDir, string $mime): Entry
    {
        return new Entry(
            id: md5('foo'),
            name: 'foo',
            path: 'dir/foo',
            isDir: $isDir,
            size: 0,
            mtime: new DateTimeImmutable('2026-01-01'),
            mime: $mime,
        );
    }
}
