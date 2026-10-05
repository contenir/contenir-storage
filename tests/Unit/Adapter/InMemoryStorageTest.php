<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;
use function str_repeat;
use function usleep;

#[Group('unit')]
#[Group('storage')]
final class InMemoryStorageTest extends TestCase
{
    /**
     * @return array<string, array{ListOptions, list<string>}>
     */
    public static function sortProvider(): array
    {
        return [
            'type' => [new ListOptions(sortField: SortField::Type), ['b.pdf', 'a.png']],
            'time' => [
                new ListOptions(
                    sortField: SortField::Time,
                    sortDirection: SortDirection::Desc,
                ),
                ['a.png', 'b.pdf'],
            ],
        ];
    }

    #[Test]
    public function deleteRemovesStoredFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'a');

        $storage->delete('a.txt');

        static::assertFalse($storage->exists('a.txt'));
    }

    #[Test]
    public function deleteThrowsForMissingFile(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        $storage->delete('a.txt');
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        $storage = new InMemoryStorage();

        static::assertFalse($storage->exists('a.txt'));
    }

    #[Test]
    public function existsReturnsTrueForStoredFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'a');

        static::assertTrue($storage->exists('a.txt'));
    }

    #[Test]
    public function imageMetaRejectsAFileWithOnlyOneDimension(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.png', 'x', 'image/png', 4);

        $this->expectException(NotFoundException::class);
        $storage->imageMeta('a.png');
    }

    #[Test]
    public function imageMetaReportsTheDimensionsGivenToPutFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.png', 'x', 'image/png', 4, 3);

        static::assertEquals(new ImageMeta(4, 3, 'image/png'), $storage->imageMeta('a.png'));
    }

    #[Test]
    public function imageMetaReturnsDimensionsForImage(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.jpg', 'bytes', 'image/jpeg', 320, 240);

        $meta = $storage->imageMeta('a.jpg');

        static::assertSame(320, $meta->width);
        static::assertSame(240, $meta->height);
        static::assertSame('image/jpeg', $meta->mime);
    }

    #[Test]
    public function imageMetaThrowsForMissingFile(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        $storage->imageMeta('nope.jpg');
    }

    #[Test]
    public function imageMetaThrowsForNonImageFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'data', 'text/plain');

        $this->expectException(NotFoundException::class);

        $storage->imageMeta('a.txt');
    }

    #[Test]
    public function listAtTheRootOfAnEmptyStoreReturnsNothing(): void
    {
        static::assertSame([], [...(new InMemoryStorage())->list('')]);
    }

    #[Test]
    public function listAtTheRootReturnsTopLevelEntriesOnly(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'a');
        $storage->putFile('docs/b.txt', 'b');

        static::assertSame(['a.txt'], array_map(static fn(Entry $e): string => $e->name, [...$storage->list('')]));
    }

    #[Test]
    public function listExcludesDirectoriesByDefault(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->makeDirectory('docs/sub');

        $entries = [...$storage->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['a.txt'], $names);
    }

    #[Test]
    public function listExcludesEntriesInSubdirectories(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->putFile('docs/sub/b.txt', 'b');

        $entries = [...$storage->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['a.txt'], $names);
    }

    #[Test]
    public function listFiltersByKeyword(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/report.pdf', 'r');
        $storage->putFile('docs/notes.txt', 'n');
        $storage->putFile('docs/report-2026.pdf', 'r2');

        $entries = [...$storage->list('docs', new ListOptions(keyword: 'report'))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        sort($names);
        static::assertSame(['report-2026.pdf', 'report.pdf'], $names);
    }

    #[Test]
    public function listIncludesDirectoriesWhenRequested(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->makeDirectory('docs/sub');

        $entries = [...$storage->list('docs', new ListOptions(includeDirectories: true))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        sort($names);
        static::assertSame(['a.txt', 'sub'], $names);
    }

    #[Test]
    public function listKeepsScanningPastEntriesStoredBeforeTheDirectChildren(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('other.txt', 'o');
        $storage->makeDirectory('docs/sub');
        $storage->putFile('docs/sub/deep.txt', 'd');
        $storage->putFile('docs/a.txt', 'a');

        static::assertSame(['a.txt'], array_map(static fn(Entry $e): string => $e->name, [...$storage->list('docs')]));
    }

    #[Test]
    public function listNormalisesLeadingAndTrailingSlashesInPath(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');

        $entries = [...$storage->list('/docs/')];

        static::assertCount(1, $entries);
    }

    #[Test]
    public function listOfADeclaredDirectorySkipsTheDirectoryAndItsSiblings(): void
    {
        $storage = new InMemoryStorage();
        $storage->makeDirectory('docs');
        $storage->putFile('docs/a.txt', 'a');
        $storage->putFile('other/b.txt', 'b');

        static::assertSame(['a.txt'], array_map(static fn(Entry $e): string => $e->name, [...$storage->list('docs')]));
    }

    #[Test]
    public function listReturnsFilesInDirectory(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->putFile('docs/b.txt', 'b');

        $entries = [...$storage->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        sort($names);
        static::assertSame(['a.txt', 'b.txt'], $names);
    }

    #[Test]
    public function listSortsByNameAscendingByDefault(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/zebra.txt', 'z');
        $storage->putFile('docs/apple.txt', 'a');
        $storage->putFile('docs/mango.txt', 'm');

        $entries = [...$storage->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['apple.txt', 'mango.txt', 'zebra.txt'], $names);
    }

    #[Test]
    public function listSortsByNameDescending(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/apple.txt', 'a');
        $storage->putFile('docs/zebra.txt', 'z');

        $entries = [...$storage->list('docs', new ListOptions(sortDirection: SortDirection::Desc))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['zebra.txt', 'apple.txt'], $names);
    }

    #[Test]
    public function listSortsBySize(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/big.txt', str_repeat('x', times: 100));
        $storage->putFile('docs/tiny.txt', 'x');
        $storage->putFile('docs/medium.txt', str_repeat('x', times: 10));

        $entries = [...$storage->list('docs', new ListOptions(sortField: SortField::Size))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['tiny.txt', 'medium.txt', 'big.txt'], $names);
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('sortProvider')]
    public function listSortsByTypeAndTime(ListOptions $options, array $expected): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('b.pdf', 'b', 'application/pdf');
        usleep(1000);
        $storage->putFile('a.png', 'a', 'image/png');

        static::assertSame($expected, array_map(static fn(Entry $e): string => $e->name, [...$storage->list(
            '',
            $options,
        )]));
    }

    #[Test]
    public function listThrowsForMissingDirectory(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        [...$storage->list('nope')];
    }

    #[Test]
    public function listThrowsWhenPathIsAFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');

        $this->expectException(NotFoundException::class);

        [...$storage->list('docs/a.txt')];
    }

    #[Test]
    public function missingVariantsIsAlwaysEmptyForAnExistingFile(): void
    {
        $storage = new InMemoryStorage(new VariantRegistry(new Variant('thumb', 1, 1)));
        $storage->putFile('a.png', 'a');

        static::assertSame([], $storage->missingVariants('/a.png'));
    }

    #[Test]
    public function missingVariantsRejectsAMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        (new InMemoryStorage())->missingVariants('a.png');
    }

    #[Test]
    public function regenerateMissingVariantsReturnsEmptyListForExistingFile(): void
    {
        // InMemoryStorage doesn't track a variant catalogue — the
        // regenerate hook is a no-op that surfaces NotFound for missing
        // sources and otherwise returns [] so tests can assert the
        // contract uniformly across adapters.
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');

        static::assertSame([], $storage->regenerateMissingVariants('docs/a.txt'));
    }

    #[Test]
    public function regenerateMissingVariantsThrowsForMissingPath(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);
        $storage->regenerateMissingVariants('docs/missing.txt');
    }

    #[Test]
    public function renameMovesFileToNewPath(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/old.txt', 'data');

        $storage->rename('docs/old.txt', 'docs/new.txt');

        static::assertFalse($storage->exists('docs/old.txt'));
        static::assertTrue($storage->exists('docs/new.txt'));
    }

    #[Test]
    public function renameThrowsWhenSourceMissing(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        $storage->rename('does-not-exist', 'somewhere');
    }

    #[Test]
    public function thumbnailUrlReturnsNullWhenAdminThumbVariantNotRegistered(): void
    {
        // No admin-thumb in the registry — convenience swallows the
        // InvalidArgumentException so callers don't need a try/catch.
        $registry = new VariantRegistry(new Variant('something-else', 100, 100));
        $storage  = new InMemoryStorage($registry);
        $storage->putFile('docs/a.jpg', 'bytes', 'image/jpeg');

        static::assertNull($storage->thumbnailUrl('docs/a.jpg'));
    }

    #[Test]
    public function thumbnailUrlReturnsNullWhenKeyMissing(): void
    {
        $registry = new VariantRegistry(new Variant('admin-thumb', 180, 180));
        $storage  = new InMemoryStorage($registry);

        static::assertNull($storage->thumbnailUrl('nope.jpg'));
    }

    #[Test]
    public function thumbnailUrlReturnsTheRegisteredAdminThumbVariantUrl(): void
    {
        $registry = new VariantRegistry(new Variant('admin-thumb', 180, 180));
        $storage  = new InMemoryStorage($registry);
        $storage->putFile('docs/a.jpg', 'bytes', 'image/jpeg');

        static::assertSame(
            'memory://docs/a.jpg?v=admin-thumb',
            $storage->thumbnailUrl('docs/a.jpg'),
        );
    }

    #[Test]
    public function thumbnailVariantConstantExposesCanonicalName(): void
    {
        static::assertSame('admin-thumb', StorageInterface::THUMBNAIL_VARIANT);
    }

    #[Test]
    public function urlIncludesVariantNameWhenRequested(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100));
        $storage  = new InMemoryStorage($registry);
        $storage->putFile('docs/a.jpg', 'bytes', 'image/jpeg');

        static::assertSame('memory://docs/a.jpg?v=thumb', $storage->url('docs/a.jpg', 'thumb'));
    }

    #[Test]
    public function urlReturnsNullForDirectory(): void
    {
        $storage = new InMemoryStorage();
        $storage->makeDirectory('docs');

        static::assertNull($storage->url('docs'));
    }

    #[Test]
    public function urlReturnsNullForMissingPath(): void
    {
        $storage = new InMemoryStorage();

        static::assertNull($storage->url('nope.txt'));
    }

    #[Test]
    public function urlsForKeyListsTheOriginalAndEveryVariant(): void
    {
        $storage = new InMemoryStorage(new VariantRegistry(new Variant('thumb', 1, 1), new Variant('card', 2, 2)));

        static::assertSame(
            ['memory://a.png', 'memory://a.png?v=thumb', 'memory://a.png?v=card'],
            $storage->urlsForKey('/a.png'),
        );
    }

    #[Test]
    public function urlThrowsForUnknownVariant(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.jpg', 'bytes', 'image/jpeg');

        $this->expectException(InvalidArgumentException::class);

        $storage->url('a.jpg', 'no-such-variant');
    }

    #[Test]
    public function variantUrlsAreKeyedByFormat(): void
    {
        $storage = new InMemoryStorage(new VariantRegistry(new Variant('card', 2, 2, formats: ['avif'])));

        static::assertSame(
            ['avif' => 'memory://a.png?v=card&f=avif', 'source' => 'memory://a.png?v=card'],
            $storage->variantUrls('a.png', 'card'),
        );
    }

    #[Test]
    public function variantUrlsRejectAnUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InMemoryStorage())->variantUrls('a.png', 'nope');
    }
}
