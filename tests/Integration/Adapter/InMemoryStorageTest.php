<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\ResolvedUpload;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function md5;

/**
 * store() reads the upload from disk, even on the in-memory backend.
 */
#[Group('integration')]
#[Group('storage')]
final class InMemoryStorageTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[Test]
    public function storeAtTheRootKeepsTheKeyRelative(): void
    {
        $entry = (new InMemoryStorage())->store(new UploadInput($this->writeFile('a.txt', 'abc'), 'a.txt'), '/');

        static::assertSame('a.txt', $entry->path);
    }

    #[Test]
    public function storedFileIsRetrievableViaUrl(): void
    {
        $source  = $this->writeFile('a.txt', 'data');
        $storage = new InMemoryStorage();
        $storage->store(new UploadInput($source, 'a.txt'), 'docs');

        static::assertSame('memory://docs/a.txt', $storage->url('docs/a.txt'));
    }

    #[Test]
    public function storeExtractsImageDimensionsFromUploadedImage(): void
    {
        $source = $this->writePng('test.png', 50, 30);

        $storage = new InMemoryStorage();
        $storage->store(new UploadInput($source, 'test.png', 'image/png'), 'images');

        $meta = $storage->imageMeta('images/test.png');
        static::assertSame(50, $meta->width);
        static::assertSame(30, $meta->height);
    }

    #[Test]
    public function storeIgnoresATrailingSlashOnTheDirectory(): void
    {
        $source = $this->writeFile('hello.txt', 'hello');

        $entry = (new InMemoryStorage())->store(new UploadInput($source, 'hello.txt'), 'docs/');

        static::assertSame('docs/hello.txt', $entry->path);
    }

    #[Test]
    public function storeNamesTheFileWithTheInjectedResolver(): void
    {
        $source   = $this->writeFile('hello.txt', 'hello');
        $resolver = $this->createStub(UploadResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedUpload('renamed.txt', 'text/plain'));

        $entry = (new InMemoryStorage(resolver: $resolver))->store(new UploadInput($source, 'hello.txt'), 'docs');

        static::assertSame('docs/renamed.txt', $entry->path);
    }

    #[Test]
    public function storeReturnsEntryForUploadedFile(): void
    {
        $source  = $this->writeFile('hello.txt', 'hello world');
        $storage = new InMemoryStorage();

        $entry = $storage->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');

        static::assertInstanceOf(Entry::class, $entry);
        static::assertSame('hello.txt', $entry->name);
        static::assertSame('docs/hello.txt', $entry->path);
        static::assertSame('text/plain', $entry->mime);
        static::assertSame(11, $entry->size);
        static::assertFalse($entry->isDir);
    }

    #[Test]
    public function storeThrowsWhenSourceUnreadable(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot read upload source "/no/such/file".');

        $storage->store(new UploadInput('/no/such/file', 'foo.txt'), 'docs');
    }

    #[Test]
    public function storeUsesMd5OfFilenameAsId(): void
    {
        $source  = $this->writeFile('hello.txt', 'hello');
        $storage = new InMemoryStorage();

        $entry = $storage->store(new UploadInput($source, 'hello.txt'), 'docs');

        static::assertSame(md5('hello.txt'), $entry->id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }
}
