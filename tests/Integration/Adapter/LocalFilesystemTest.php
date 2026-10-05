<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\ListOptions;
use Contenir\Storage\ResolvedUpload;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_map;
use function array_search;
use function chmod;
use function explode;
use function file_get_contents;
use function is_int;
use function md5;
use function mkdir;
use function sort;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function touch;
use function unlink;

#[Group('integration')]
#[Group('storage')]
final class LocalFilesystemTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private StubImageResizer $resizer;

    /** @return array<string, array{string}> */
    public static function unsafePathProvider(): array
    {
        return [
            'parent traversal'      => ['../escape'],
            'mid-path traversal'    => ['docs/../../etc'],
            'url-encoded traversal' => ['docs/%2e%2e/etc'],
            'null byte'             => ["docs\0/etc"],
        ];
    }

    #[Test]
    public function deleteRemovesFileAndItsVariants(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        static::assertFileExists("{$this->tmpDir}/docs/_variant/admin-thumb/a.png");

        $backend->delete('docs/a.png');

        static::assertFileDoesNotExist("{$this->tmpDir}/docs/a.png");
        static::assertFileDoesNotExist("{$this->tmpDir}/docs/_variant/admin-thumb/a.png");
    }

    #[Test]
    public function deleteReportsAFileThatCannotBeRemoved(): void
    {
        $this->skipWhenRunningAsRoot();
        $this->writeFile('docs/a.txt', 'a');
        chmod($this->path('docs'), permissions: 0o555);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed deleting "docs/a.txt".');
        $this->backend()->delete('docs/a.txt');
    }

    #[Test]
    public function deleteThrowsForDirectory(): void
    {
        mkdir($this->path('docs'));

        $this->expectException(WriteException::class);

        $this->backend()->delete('docs');
    }

    #[Test]
    public function deleteThrowsForMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->delete('nope.txt');
    }

    #[Test]
    public function existsFalseForMissingFile(): void
    {
        static::assertFalse($this->backend()->exists('a.txt'));
    }

    #[Test]
    public function existsTrueForFile(): void
    {
        $this->writeFile('a.txt', 'a');

        static::assertTrue($this->backend()->exists('a.txt'));
    }

    #[Test]
    public function generatedVariantsUseTheVariantQuality(): void
    {
        $this->backend(new VariantRegistry(new Variant('thumb', 1, 1, quality: 42)))
            ->store(new UploadInput($this->writePng('src.png', 2, 2), 'a.png'), 'docs');

        static::assertSame(42, $this->resizer->calls[0]['quality']);
    }

    #[Test]
    public function imageMetaReturnsDimensions(): void
    {
        $this->writePng('a.png', 50, 30);

        $meta = $this->backend()->imageMeta('a.png');

        static::assertSame(50, $meta->width);
        static::assertSame(30, $meta->height);
        static::assertSame('image/png', $meta->mime);
    }

    #[Test]
    public function imageMetaThrowsForMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->imageMeta('nope.png');
    }

    #[Test]
    public function imageMetaThrowsForNonImage(): void
    {
        $this->writeFile('a.txt', 'data');

        $this->expectException(NotFoundException::class);

        $this->backend()->imageMeta('a.txt');
    }

    #[Test]
    public function listDescribesAnUnreadableEntryAsAnEmptyBinaryFile(): void
    {
        mkdir($this->path('docs'));
        symlink($this->path('nowhere'), $this->path('docs/broken'));

        $entries = [...$this->backend()->list('docs')];

        static::assertSame([0, 'application/octet-stream'], [$entries[0]->size, $entries[0]->mime]);
    }

    #[Test]
    public function listExcludesHiddenFiles(): void
    {
        mkdir($this->path('docs'));
        $this->writeFile('docs/a.txt', 'a');
        $this->writeFile('docs/.DS_Store', '');
        $this->writeFile('docs/.hidden', 'h');

        $entries = [...$this->backend()->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['a.txt'], $names);
    }

    #[Test]
    public function listExcludesThumbsDirectory(): void
    {
        mkdir($this->path('docs/_variant'), permissions: 0o777, recursive: true);
        $this->writeFile('docs/a.txt', 'a');

        $entries = [...$this->backend()->list('docs', new ListOptions(includeDirectories: true))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['a.txt'], $names);
    }

    #[Test]
    public function listFiltersByKeyword(): void
    {
        mkdir($this->path('docs'));
        $this->writeFile('docs/report.pdf', 'r');
        $this->writeFile('docs/notes.txt', 'n');

        $entries = [...$this->backend()->list('docs', new ListOptions(keyword: 'report'))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['report.pdf'], $names);
    }

    #[Test]
    public function listHidesThumbsDbAndSubdirectoriesByDefault(): void
    {
        $this->writeFile('docs/a.txt', 'a');
        $this->writeFile('docs/Thumbs.db', 't');
        mkdir($this->path('docs/sub'));

        static::assertSame(['a.txt'], $this->names($this->backend()->list('docs')));
    }

    #[Test]
    public function listIncludesDirectoriesWithZeroSize(): void
    {
        mkdir($this->path('sub'));

        $entries = [...$this->backend()->list('', new ListOptions(includeDirectories: true))];

        static::assertSame(['sub', true, 0, 'inode/directory'], [
            $entries[0]->name,
            $entries[0]->isDir,
            $entries[0]->size,
            $entries[0]->mime,
        ]);
    }

    #[Test]
    public function listReturnsFilesInDirectory(): void
    {
        mkdir($this->path('docs'));
        $this->writeFile('docs/a.txt', 'a');
        $this->writeFile('docs/b.txt', 'b');

        $entries = [...$this->backend()->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);
        sort($names);

        static::assertSame(['a.txt', 'b.txt'], $names);
    }

    #[Test]
    public function listSortsByModificationTime(): void
    {
        $this->writeFile('docs/new.txt', 'n');
        $this->writeFile('docs/old.txt', 'o');
        touch($this->path('docs/old.txt'), mtime: 1_000_000);

        static::assertSame(
            ['old.txt', 'new.txt'],
            $this->names($this->backend()->list('docs', new ListOptions(sortField: SortField::Time))),
        );
    }

    #[Test]
    public function listSortsByNameDescending(): void
    {
        mkdir($this->path('docs'));
        $this->writeFile('docs/apple.txt', 'a');
        $this->writeFile('docs/zebra.txt', 'z');

        $entries = [...$this->backend()->list('docs', new ListOptions(sortDirection: SortDirection::Desc))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['zebra.txt', 'apple.txt'], $names);
    }

    #[Test]
    public function listSortsBySize(): void
    {
        mkdir($this->path('docs'));
        $this->writeFile('docs/big.txt', str_repeat('x', times: 100));
        $this->writeFile('docs/small.txt', 'x');

        $entries = [...$this->backend()->list('docs', new ListOptions(sortField: SortField::Size))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['small.txt', 'big.txt'], $names);
    }

    #[Test]
    public function listSortsByType(): void
    {
        $this->writeFile('docs/a.txt', 'text');
        $this->writePng('docs/b.png', 1, 1);

        static::assertSame(
            ['b.png', 'a.txt'],
            $this->names($this->backend()->list('docs', new ListOptions(sortField: SortField::Type))),
        );
    }

    #[Test]
    public function listThrowsForMissingDirectory(): void
    {
        $this->expectException(NotFoundException::class);

        [...$this->backend()->list('nope')];
    }

    #[Test]
    public function listThrowsWhenPathIsAFile(): void
    {
        $this->writeFile('a.txt', 'a');

        $this->expectException(NotFoundException::class);

        [...$this->backend()->list('a.txt')];
    }

    #[Test]
    public function missingVariantsListsTheOwnedVariantsNotYetOnDisk(): void
    {
        $this->writePng('gallery/a.png', 2, 2);
        $this->writeFile('gallery/_variant/thumb/a.png', 't');
        $backend = new LocalFilesystem(
            $this->path(),
            '',
            new VariantRegistry(new Variant('thumb', 1, 1), new Variant('card', 1, 1), new Variant('hero', 1, 1)),
            $this->resizer,
            paths: new PathVariantResolver(['/gallery' => ['thumb', 'card']]),
        );

        static::assertSame(['gallery/_variant/card/a.png'], $backend->missingVariants('gallery/a.png'));
        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function missingVariantsRejectsAMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->missingVariants('nope.png');
    }

    #[Test]
    public function regenerateMissingVariantsCreatesAbsentSibling(): void
    {
        $this->writePng('gallery/cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        // Prime the variant by calling url() (lazy materialisation) then
        // delete it so regenerate has something to do.
        $backend->url('gallery/cat.png', 'admin-thumb');
        unlink("{$this->tmpDir}/gallery/_variant/admin-thumb/cat.png");
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants('gallery/cat.png');

        static::assertSame(['gallery/_variant/admin-thumb/cat.png'], $regenerated);
        static::assertCount(1, $this->resizer->calls);
        static::assertFileExists("{$this->tmpDir}/gallery/_variant/admin-thumb/cat.png");
    }

    #[Test]
    public function regenerateMissingVariantsIsIdempotent(): void
    {
        $this->writePng('gallery/cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        // Materialise the variant once (lazy via url()), then assert that
        // a second regenerate call finds nothing to do.
        $backend->url('gallery/cat.png', 'admin-thumb');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants('gallery/cat.png');

        static::assertSame([], $regenerated);
        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function regenerateMissingVariantsThrowsWhenSourceMissing(): void
    {
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $this->expectException(NotFoundException::class);
        $backend->regenerateMissingVariants('gallery/missing.png');
    }

    #[Test]
    public function regenerateMissingVariantsWrapsAResizerFailure(): void
    {
        $this->writePng('a.png', 2, 2);
        $resizer = $this->createStub(ImageResizerInterface::class);
        $resizer->method('resize')->willThrowException(new WriteException('boom'));
        $backend = new LocalFilesystem($this->path(), '', new VariantRegistry(new Variant('thumb', 1, 1)), $resizer);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed regenerating variant "thumb" for "a.png": boom');
        $backend->regenerateMissingVariants('a.png');
    }

    #[Test]
    public function renameMovesFileAndVariants(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        $backend->rename('docs/a.png', 'docs/renamed.png');

        static::assertFileExists("{$this->tmpDir}/docs/renamed.png");
        static::assertFileDoesNotExist("{$this->tmpDir}/docs/a.png");
        static::assertFileExists("{$this->tmpDir}/docs/_variant/admin-thumb/renamed.png");
        static::assertFileDoesNotExist("{$this->tmpDir}/docs/_variant/admin-thumb/a.png");
    }

    #[Test]
    public function renameReportsADestinationDirectoryThatCannotBeCreated(): void
    {
        $this->writeFile('a.txt', 'a');
        $this->writeFile('blocker', 'b');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot create directory');
        $this->backend()->rename('a.txt', 'blocker/sub/a.txt');
    }

    #[Test]
    public function renameReportsAMoveThatFails(): void
    {
        $this->skipWhenRunningAsRoot();
        $this->writeFile('docs/a.txt', 'a');
        chmod($this->path('docs'), permissions: 0o555);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed renaming "docs/a.txt" to "a.txt".');
        $this->backend()->rename('docs/a.txt', 'a.txt');
    }

    #[Test]
    public function renameSkipsVariantsThatWereNeverMaterialised(): void
    {
        $this->writeFile('a.png', 'a');

        $this->backend(new VariantRegistry(new Variant('thumb', 1, 1)))->rename('a.png', 'b.png');

        static::assertFileExists($this->path('b.png'));
        static::assertDirectoryDoesNotExist($this->path('_variant'));
    }

    #[Test]
    public function renameThrowsWhenDestinationExists(): void
    {
        $this->writeFile('a.txt', 'a');
        $this->writeFile('b.txt', 'b');

        $this->expectException(WriteException::class);

        $this->backend()->rename('a.txt', 'b.txt');
    }

    #[Test]
    public function renameThrowsWhenSourceMissing(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->rename('does-not-exist', 'somewhere');
    }

    #[Test]
    public function storeAtTheRootReturnsARootRelativePath(): void
    {
        $entry = $this->backend()->store(new UploadInput($this->writeFile('src/a.txt', 'plain text'), 'a.txt'), '');

        static::assertSame('a.txt', $entry->path);
    }

    #[Test]
    public function storeCopiesUploadAndReturnsEntry(): void
    {
        $source  = $this->writeFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');

        static::assertInstanceOf(Entry::class, $entry);
        static::assertSame('hello.txt', $entry->name);
        static::assertSame('docs/hello.txt', $entry->path);
        static::assertFileExists("{$this->tmpDir}/docs/hello.txt");
        static::assertSame('data', file_get_contents("{$this->tmpDir}/docs/hello.txt"));
    }

    #[Test]
    public function storeCreatesTargetDirectory(): void
    {
        $source  = $this->writeFile('a.txt', 'plain text body');
        $backend = $this->backend();

        $backend->store(new UploadInput($source, 'a.txt'), 'docs/2026/april');

        static::assertDirectoryExists("{$this->tmpDir}/docs/2026/april");
    }

    #[Test]
    public function storeDerivesExtensionFromDetectedTypeNotClientData(): void
    {
        $source  = $this->writePng('source.png', 10, 10);
        $backend = $this->backend();

        // Real PNG bytes, but the client lies with a .JPEG name and an
        // image/jpeg header. The detected type wins, every time.
        $entry = $backend->store(new UploadInput($source, 'IMG_1234.JPEG', 'image/jpeg'), 'docs');

        static::assertSame('img-1234.png', $entry->name);
    }

    #[Test]
    public function storeGeneratesVariantsForImageUploads(): void
    {
        $source  = $this->writePng('cat.png', 50, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            new Variant('hero', 1200, 600, VariantFit::Cover),
        ));

        $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');

        static::assertCount(2, $this->resizer->calls);
        static::assertSame('admin-thumb', $this->variantNameForCall(0));
        static::assertSame(180, $this->resizer->calls[0]['width']);
        static::assertSame(VariantFit::Contain, $this->resizer->calls[0]['fit']);
    }

    #[Test]
    public function storeGivesUpAfterTooManyNameCollisions(): void
    {
        $this->writeFile('docs/a.txt', 'x');
        for ($i = 1; $i < 1000; ++$i) {
            $this->writeFile("docs/a_{$i}.txt", 'x');
        }

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot allocate unique filename');
        $this->backend()->store(new UploadInput($this->writeFile('src.txt', 'plain text'), 'a.txt'), 'docs');
    }

    #[Test]
    public function storeIgnoresClientExtensionAndUsesDetectedType(): void
    {
        $source  = $this->writeFile('a.txt', 'just some plain text');
        $backend = $this->backend();

        // Client claims ".MD" + octet-stream; the bytes are plain text, so the
        // stored key carries the detected ".txt" extension, never the client's.
        $entry = $backend->store(new UploadInput($source, 'note.MD', 'application/octet-stream'), 'docs');

        static::assertSame('note.txt', $entry->name);
    }

    #[Test]
    #[DataProvider('unsafePathProvider')]
    public function storeRejectsUnsafePath(string $unsafe): void
    {
        $source = $this->writeFile('a.txt', 'd');

        $this->expectException(InvalidPathException::class);

        $this->backend()->store(new UploadInput($source, 'a.txt'), $unsafe);
    }

    #[Test]
    public function storeReportsACopyThatFails(): void
    {
        mkdir($this->path('source-dir'));
        $resolver = $this->createStub(UploadResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedUpload('a.txt', 'text/plain'));
        $backend = new LocalFilesystem($this->path(), '', new VariantRegistry(), $this->resizer, $resolver);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed copying upload');
        $backend->store(new UploadInput($this->path('source-dir'), 'a.txt'), 'docs');
    }

    #[Test]
    public function storeReportsADirectoryThatCannotBeCreated(): void
    {
        $this->writeFile('blocker', 'b');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot create directory');
        $this->backend()->store(new UploadInput($this->writeFile('src.txt', 'x'), 'a.txt'), 'blocker/sub');
    }

    #[Test]
    public function storeReportsADirectoryThatIsNotWritable(): void
    {
        $this->skipWhenRunningAsRoot();
        mkdir($this->path('locked'), permissions: 0o555);

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('is not writable');
        $this->backend()->store(new UploadInput($this->writeFile('src.txt', 'x'), 'a.txt'), 'locked');
    }

    #[Test]
    public function storeResolvesCollisionWithSuffix(): void
    {
        $source  = $this->writeFile('a.txt', 'data');
        $backend = $this->backend();

        $first  = $backend->store(new UploadInput($source, 'note.txt'), 'docs');
        $second = $backend->store(new UploadInput($source, 'note.txt'), 'docs');

        static::assertSame('note.txt', $first->name);
        static::assertSame('note_1.txt', $second->name);
    }

    #[Test]
    public function storeSanitisesFilename(): void
    {
        $source  = $this->writeFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'My File!.txt'), 'docs');

        static::assertSame('my-file.txt', $entry->name);
    }

    #[Test]
    public function storeSkipsVariantGenerationForNonImages(): void
    {
        $source  = $this->writeFile('notes.txt', 'data');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function storeSuffixesACollidingNameWithoutAnExtension(): void
    {
        $this->writeFile('docs/readme', 'x');
        $resolver = $this->createStub(UploadResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedUpload('readme', 'text/plain'));
        $backend = new LocalFilesystem($this->path(), '', new VariantRegistry(), $this->resizer, $resolver);

        static::assertSame(
            'readme_1',
            $backend->store(new UploadInput($this->writeFile('src', 'y'), 'readme'), 'docs')->name,
        );
    }

    #[Test]
    public function storeThrowsWhenSourceUnreadable(): void
    {
        $backend = $this->backend();

        $this->expectException(WriteException::class);

        $backend->store(new UploadInput('/no/such/file', 'a.txt'), 'docs');
    }

    #[Test]
    public function storeUsesMd5OfFilenameAsId(): void
    {
        $source  = $this->writeFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'hello.txt'), 'docs');

        static::assertSame(md5('hello.txt'), $entry->id);
    }

    #[Test]
    public function urlLazilyMaterialisesVariantWhenOriginalIsImage(): void
    {
        // Pre-existing image dropped on disk via SCP/legacy upload — never
        // went through store(), so no variant materialised. url() should
        // generate it on demand and return the variant URL.
        $this->writePng('uploads/a.png', 50, 30);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $url = $backend->url('uploads/a.png', 'admin-thumb');

        static::assertSame('/uploads/_variant/admin-thumb/a.png', $url);
        static::assertCount(1, $this->resizer->calls, 'resizer should run exactly once');
        static::assertFileExists(
            "{$this->tmpDir}/uploads/_variant/admin-thumb/a.png",
            'variant should be materialised on disk',
        );
    }

    #[Test]
    public function urlRejectsASymlinkThatEscapesTheRoot(): void
    {
        $outside = sys_get_temp_dir();
        symlink($outside, $this->path('escape'));

        $this->expectException(InvalidPathException::class);
        $this->expectExceptionMessage('resolves outside the storage root');
        $this->backend()->url('escape');
    }

    #[Test]
    public function urlReturnsNullForMissingFile(): void
    {
        static::assertNull($this->backend()->url('nope.txt'));
    }

    #[Test]
    public function urlReturnsNullWhenLazyGenerationFails(): void
    {
        $this->writePng('uploads/a.png', 10, 10);

        $resizer = $this->createStub(ImageResizerInterface::class);
        $resizer->method('resize')->willThrowException(new RuntimeException('resizer is on fire'));
        $backend = new LocalFilesystem(
            $this->tmpDir,
            '',
            new VariantRegistry(new Variant('admin-thumb', 180, 180)),
            $resizer,
        );

        static::assertNull($backend->url('uploads/a.png', 'admin-thumb'));
    }

    #[Test]
    public function urlReturnsNullWhenVariantMissing(): void
    {
        // 'x' is not a real image, so getimagesize() returns false and
        // lazy generation skips it — fall back to the documented null.
        $this->writeFile('a.png', 'x');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        static::assertNull($backend->url('a.png', 'admin-thumb'));
    }

    #[Test]
    public function urlReturnsPublicPathPrefixedRelativePath(): void
    {
        $this->writeFile('a.txt', 'x');
        $backend = new LocalFilesystem(
            $this->tmpDir,
            '/uploads',
            new VariantRegistry(),
            $this->resizer,
        );

        static::assertSame('/uploads/a.txt', $backend->url('a.txt'));
    }

    #[Test]
    public function urlReturnsVariantUrlWhenMaterialised(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        static::assertSame('/docs/_variant/admin-thumb/a.png', $backend->url('docs/a.png', 'admin-thumb'));
    }

    #[Test]
    public function urlReusesPreviouslyMaterialisedVariantWithoutRegenerating(): void
    {
        $this->writePng('uploads/a.png', 50, 30);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->url('uploads/a.png', 'admin-thumb');
        $backend->url('uploads/a.png', 'admin-thumb');
        $backend->url('uploads/a.png', 'admin-thumb');

        static::assertCount(
            1,
            $this->resizer->calls,
            'lazy generation should only fire on the first url() call per variant',
        );
    }

    #[Test]
    public function urlsForKeyListsTheOriginalAndEveryVariantWithoutChecking(): void
    {
        static::assertSame(
            ['/docs/a.png', '/docs/_variant/thumb/a.png'],
            $this->backend(new VariantRegistry(new Variant('thumb', 1, 1)))->urlsForKey('docs/a.png'),
        );
    }

    #[Test]
    public function urlThrowsForUnknownVariant(): void
    {
        $this->writeFile('a.png', 'x');
        $backend = $this->backend();

        $this->expectException(InvalidArgumentException::class);

        $backend->url('a.png', 'no-such-variant');
    }

    #[Test]
    public function urlWithEmptyPublicPathReturnsRootRelativePath(): void
    {
        $this->writeFile('a.txt', 'x');
        $backend = $this->backend();

        static::assertSame('/a.txt', $backend->url('a.txt'));
    }

    #[Test]
    public function variantUrlsAreDeterministicWithoutTouchingDisk(): void
    {
        // Nothing on disk: url() returns null (it stats the file), but
        // variantUrls() builds the path from the key alone — the trusted,
        // no-I/O render path.
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        static::assertNull($backend->url('docs/ghost.png', 'admin-thumb'));
        static::assertSame(
            ['source' => '/docs/_variant/admin-thumb/ghost.png'],
            $backend->variantUrls('docs/ghost.png', 'admin-thumb'),
        );
    }

    #[Test]
    public function variantUrlsThrowsForUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->backend()->variantUrls('docs/a.png', 'no-such-variant');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
        $this->resizer = new StubImageResizer();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }

    private function backend(?VariantRegistry $variants = null): LocalFilesystem
    {
        return new LocalFilesystem(
            $this->tmpDir,
            '',
            $variants ?? new VariantRegistry(),
            $this->resizer,
        );
    }

    /**
     * @param iterable<Entry> $entries
     *
     * @return list<string>
     */
    private function names(iterable $entries): array
    {
        return array_map(static fn(Entry $entry): string => $entry->name, [...$entries]);
    }

    private function variantNameForCall(int $index): string
    {
        $destPath = $this->resizer->calls[$index]['dest'];
        $parts    = explode(
            separator: '/',
            string: $destPath,
        );
        $idx = array_search('_variant', $parts, strict: true);
        return is_int($idx) ? $parts[$idx + 1] ?? '' : '';
    }
}
