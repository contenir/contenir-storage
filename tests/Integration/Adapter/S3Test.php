<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Contenir\Storage\Adapter\S3;
use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\ResolvedUpload;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\Tests\TestAsset\Flysystem\FailingFilesystem;
use Contenir\Storage\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function md5;
use function sort;
use function str_repeat;
use function time;
use function unlink;

#[Group('integration')]
#[Group('storage')]
final class S3Test extends TestCase
{
    use TemporaryDirectoryTrait;

    private StubImageResizer $resizer;

    #[Test]
    public function clearKeyCacheForcesReExistenceProbes(): void
    {
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(),
            resizer: $this->resizer,
        );

        // Warm the cache by listing the prefix containing a known object.
        $source = $this->writeFile('hello.txt', 'data');
        $backend->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');
        [...$backend->list('docs')];
        static::assertNotNull($backend->url('docs/hello.txt'));

        // Manually nuke the object from the underlying fs (bypassing
        // delete() so the cache isn't invalidated as a side effect).
        $fs->delete('docs/hello.txt');

        // Cache says the key exists even though it doesn't (this is the
        // bug clearKeyCache exists to mitigate in long-running workers).
        static::assertNotNull($backend->url('docs/hello.txt'));

        $backend->clearKeyCache();

        // After clearing, url() falls through to a real fileExists() and
        // correctly reports the gone object.
        static::assertNull($backend->url('docs/hello.txt'));
    }

    #[Test]
    public function deleteRemovesFileAndVariants(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        static::assertTrue($backend->exists('docs/a.png'));
        static::assertTrue($backend->exists('docs/a__admin-thumb.png'));

        $backend->delete('docs/a.png');

        static::assertFalse($backend->exists('docs/a.png'));
        static::assertFalse($backend->exists('docs/a__admin-thumb.png'));
    }

    #[Test]
    public function deleteStillRemovesSiblingsOfFamiliesThePathNoLongerOwns(): void
    {
        // Cleanup must stay exhaustive: siblings written before an ownership
        // change would otherwise be stranded in the bucket forever.
        $source   = $this->writePng('stale.png', 30, 30);
        $fs       = new Filesystem(new InMemoryFilesystemAdapter());
        $variants = new VariantRegistry(
            new Variant('tile-480', 480, 480, VariantFit::Contain),
            new Variant('mark-240', 240, 240, VariantFit::Contain),
        );

        $permissive = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: $variants,
            resizer: $this->resizer,
        );
        $permissive->store(new UploadInput($source, 'stale.png', 'image/png'), 'gallery');
        static::assertTrue($fs->fileExists('gallery/stale__mark-240.png'));

        $restricted = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: $variants,
            resizer: $this->resizer,
            paths: new PathVariantResolver(['/gallery' => ['tile']]),
        );
        $restricted->delete('gallery/stale.png');

        static::assertFalse($fs->fileExists('gallery/stale__mark-240.png'));
    }

    #[Test]
    public function existsTrueForStoredFile(): void
    {
        $source  = $this->writeFile('a.txt', 'plain text body');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');

        static::assertTrue($backend->exists('docs/a.txt'));
    }

    #[Test]
    public function generateForKeyDeclinesAFamilyThePathDoesNotOwn(): void
    {
        // The edge miss-proxy must not be a way around the ownership map.
        $source  = $this->writePng('guarded.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('tile-480', 480, 480, VariantFit::Contain),
                new Variant('mark-240', 240, 240, VariantFit::Contain),
            ),
            resizer: $this->resizer,
            paths: new PathVariantResolver(['/gallery' => ['tile']]),
        );
        $backend->store(new UploadInput($source, 'guarded.png', 'image/png'), 'gallery');

        static::assertNull($backend->generateForKey('gallery/guarded__mark-240.png'));
        static::assertFalse($fs->fileExists('gallery/guarded__mark-240.png'));
    }

    #[Test]
    public function generateForKeyGeneratesOnlyTheRequestedFormat(): void
    {
        $source  = $this->writePng('hero.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('hero', 1600, 1200, VariantFit::Cover, ['avif', 'webp'], 80),
            ),
            resizer: $this->resizer,
        );
        $backend->store(new UploadInput($source, 'hero.png', 'image/png'), 'covers');
        $fs->delete('covers/hero__hero.avif');
        $fs->delete('covers/hero__hero.webp');
        $this->resizer->calls = [];

        $url = $backend->generateForKey('covers/hero__hero.avif');

        static::assertSame('https://cdn.test/covers/hero__hero.avif', $url);
        static::assertCount(1, $this->resizer->calls, 'Only the requested format should be generated.');
        static::assertTrue($fs->fileExists('covers/hero__hero.avif'));
        static::assertFalse($fs->fileExists('covers/hero__hero.webp'));
    }

    #[Test]
    public function generateForKeyMaterialisesAnOwnedFamily(): void
    {
        $fs = new FailingFilesystem();
        $fs->write('gallery/cat.jpeg', 'x');
        $backend = $this->backendOn(
            $fs,
            new VariantRegistry(new Variant('gallery-480', 480, 0, VariantFit::Contain)),
            paths: new PathVariantResolver(['/gallery' => ['gallery']]),
        );

        static::assertSame(
            'https://cdn.test/gallery/cat__gallery-480.webp',
            $backend->generateForKey('gallery/cat__gallery-480.webp'),
        );
        static::assertTrue($fs->fileExists('gallery/cat__gallery-480.webp'));
    }

    #[Test]
    public function generateForKeyMaterialisesMissingVariant(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer: $this->resizer,
        );

        $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $fs->delete('gallery/cat__admin-thumb.png');
        $this->resizer->calls = [];

        $url = $backend->generateForKey('gallery/cat__admin-thumb.png');

        static::assertSame('https://cdn.test/gallery/cat__admin-thumb.png', $url);
        static::assertCount(1, $this->resizer->calls);
        static::assertTrue($fs->fileExists('gallery/cat__admin-thumb.png'));
    }

    #[Test]
    public function generateForKeyProducesAnyRequestedFormatRegardlessOfDeclaredFormats(): void
    {
        // Mirrors the local on-demand resizer: the variant supplies only
        // dimensions/fit; the format comes from the requested key's extension,
        // so the <img> source-ext fallback and avif/webp <source>s all resolve.
        $source  = $this->writePng('cat.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('card', 600, 600, VariantFit::Cover, ['avif', 'webp'], 75),
            ),
            resizer: $this->resizer,
        );
        $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $this->resizer->calls = [];

        // The source-ext sibling the <img> fallback resolves against is
        // materialised at store time alongside the declared avif/webp.
        static::assertTrue($fs->fileExists('gallery/cat__card.png'));

        // A format that is neither declared nor the source is still produced
        // on demand from the original.
        $url = $backend->generateForKey('gallery/cat__card.jpg');

        static::assertSame('https://cdn.test/gallery/cat__card.jpg', $url);
        static::assertCount(1, $this->resizer->calls);
        static::assertTrue($fs->fileExists('gallery/cat__card.jpg'));
    }

    #[Test]
    public function generateForKeyReturnsExistingVariantWithoutResizing(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));
        $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $this->resizer->calls = [];

        $url = $backend->generateForKey('gallery/cat__admin-thumb.png');

        static::assertSame('https://cdn.test/gallery/cat__admin-thumb.png', $url);
        static::assertSame([], $this->resizer->calls, 'An already-present variant must not be regenerated.');
    }

    #[Test]
    public function generateForKeyReturnsNullForUnknownVariant(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));
        $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $this->resizer->calls = [];

        static::assertNull($backend->generateForKey('gallery/cat__no-such.png'));
        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function generateForKeyReturnsNullForUnsupportedOutputFormat(): void
    {
        $source  = $this->writePng('hero.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('hero', 1600, 1200, VariantFit::Cover, ['avif', 'webp'], 80),
        ));
        $backend->store(new UploadInput($source, 'hero.png', 'image/png'), 'covers');
        $this->resizer->calls = [];

        // .tiff is not a web-deliverable raster output the resizer will emit.
        static::assertNull($backend->generateForKey('covers/hero__hero.tiff'));
        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function imageMetaReturnsDimensionsForRealImage(): void
    {
        $source  = $this->writePng('a.png', 50, 30);
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        $meta = $backend->imageMeta('docs/a.png');

        static::assertSame(50, $meta->width);
        static::assertSame(30, $meta->height);
        static::assertSame('image/png', $meta->mime);
    }

    #[Test]
    public function imageMetaThrowsForNonImage(): void
    {
        $source  = $this->writeFile('notes.txt', 'data');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        $this->expectException(NotFoundException::class);

        $backend->imageMeta('docs/notes.txt');
    }

    #[Test]
    public function listFiltersByKeyword(): void
    {
        $source  = $this->writeFile('a.txt', 'plain text body');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'report.txt'), 'docs');
        $backend->store(new UploadInput($source, 'notes.txt'), 'docs');

        $entries = [...$backend->list('docs', new ListOptions(keyword: 'report'))];

        static::assertCount(1, $entries);
        static::assertSame('report.txt', $entries[0]->name);
    }

    #[Test]
    public function listInfersMimeFromExtensionWhenAdapterReportsNone(): void
    {
        $source  = $this->writePng('logo.png', 10, 10);
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'logo.png', 'image/png'), 'docs');

        // InMemoryFilesystemAdapter returns null mimeType in listings — same
        // shape as S3's ListObjectsV2 — so the entry's mime must come from
        // the extension fallback for isImage() to be correct.
        $entries = [...$backend->list('docs')];

        static::assertCount(1, $entries);
        static::assertSame('image/png', $entries[0]->mime);
        static::assertTrue($entries[0]->isImage());
    }

    #[Test]
    public function listReturnsFilesAndExcludesVariantSiblings(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');
        $backend->store(new UploadInput($source, 'b.png', 'image/png'), 'docs');

        $entries = [...$backend->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);
        sort($names);

        static::assertSame(['a.png', 'b.png'], $names);
    }

    #[Test]
    public function listSortsByNameDescending(): void
    {
        $source  = $this->writeFile('a.txt', 'plain text body');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'apple.txt'), 'docs');
        $backend->store(new UploadInput($source, 'zebra.txt'), 'docs');

        $entries = [...$backend->list('docs', new ListOptions(sortDirection: SortDirection::Desc))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['zebra.txt', 'apple.txt'], $names);
    }

    #[Test]
    public function listSortsBySize(): void
    {
        $big   = $this->writeFile('big.txt', str_repeat('a', times: 100));
        $small = $this->writeFile('small.txt', 'abc');

        $backend = $this->backend();
        $backend->store(new UploadInput($big, 'big.txt'), 'docs');
        $backend->store(new UploadInput($small, 'small.txt'), 'docs');

        $entries = [...$backend->list('docs', new ListOptions(sortField: SortField::Size))];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);

        static::assertSame(['small.txt', 'big.txt'], $names);
    }

    #[Test]
    public function missingVariantsIsEmptyOnceEverythingIsMaterialised(): void
    {
        $source  = $this->writePng('full.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(new Variant('card', 600, 600, VariantFit::Contain, ['avif'])));
        $backend->store(new UploadInput($source, 'full.png', 'image/png'), 'gallery');

        static::assertSame([], $backend->missingVariants('gallery/full.png'));
    }

    #[Test]
    public function missingVariantsReportsWhatABackfillWouldProduceWithoutWriting(): void
    {
        $source  = $this->writePng('audit.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(new Variant('card', 600, 600, VariantFit::Contain, ['avif'])),
            resizer: $this->resizer,
        );
        $fs->write('gallery/audit.png', (string) file_get_contents($source));
        $this->resizer->calls = [];

        $missing = $backend->missingVariants('gallery/audit.png');

        static::assertSame(['gallery/audit__card.avif', 'gallery/audit__card.png'], $missing);
        static::assertSame([], $this->resizer->calls);
        static::assertFalse($fs->fileExists('gallery/audit__card.avif'));
    }

    #[Test]
    public function regenerateMissingVariantsCreatesAbsentSiblings(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer: $this->resizer,
        );

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $fs->delete('gallery/cat__admin-thumb.png');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants($entry->path);

        static::assertSame(['gallery/cat__admin-thumb.png'], $regenerated);
        static::assertCount(1, $this->resizer->calls);
        static::assertTrue($fs->fileExists('gallery/cat__admin-thumb.png'));
    }

    #[Test]
    public function regenerateMissingVariantsGeneratesAllDeclaredFormats(): void
    {
        $source  = $this->writePng('hero.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('hero', 1600, 1200, VariantFit::Cover, ['avif', 'webp'], 80),
            ),
            resizer: $this->resizer,
        );

        $entry = $backend->store(new UploadInput($source, 'hero.png', 'image/png'), 'covers');
        $fs->delete('covers/hero__hero.avif');
        $fs->delete('covers/hero__hero.webp');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants($entry->path);

        sort($regenerated);
        static::assertSame(['covers/hero__hero.avif', 'covers/hero__hero.webp'], $regenerated);
        static::assertCount(2, $this->resizer->calls);
        static::assertTrue($fs->fileExists('covers/hero__hero.avif'));
        static::assertTrue($fs->fileExists('covers/hero__hero.webp'));
    }

    #[Test]
    public function regenerateMissingVariantsIsIdempotentWhenEverythingPresent(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        $entry                = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants($entry->path);

        static::assertSame([], $regenerated);
        static::assertSame([], $this->resizer->calls, 'No resizer calls expected when nothing is missing.');
    }

    #[Test]
    public function regenerateMissingVariantsReportsASourceThatCannotBeDownloaded(): void
    {
        $fs = new FailingFilesystem();
        $fs->write('a.png', 'x');
        $fs->failOn('readStream', 'a.png');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed opening source stream "a.png"');
        $this->backendOn($fs, new VariantRegistry(new Variant('thumb', 2, 2)))->regenerateMissingVariants('a.png');
    }

    #[Test]
    public function regenerateMissingVariantsStreamsSourceFromBackend(): void
    {
        // Sanity check that the new streaming path materialises the same
        // local temp file content the legacy slurping path produced. A
        // bespoke ImageResizer subclass captures the bytes the resizer
        // was actually fed (we can't query the regenerator's temp file
        // after the fact — its finally block unlinks it on return).
        $source      = $this->writePng('cat.png', 30, 30);
        $sourceBytes = (string) file_get_contents($source);
        $fs          = new Filesystem(new InMemoryFilesystemAdapter());

        $captured       = null;
        $captureResizer = $this->createStub(ImageResizerInterface::class);
        $captureResizer->method('resize')
            ->willReturnCallback(
                static function (string $sourcePath, string $destPath) use (&$captured): void {
                    $captured = (string) file_get_contents($sourcePath);
                    file_put_contents($destPath, data: 'STUB');
                },
            );

        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer: $captureResizer,
        );

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $fs->delete('gallery/cat__admin-thumb.png');
        $captured = null;

        $backend->regenerateMissingVariants($entry->path);

        static::assertSame(
            $sourceBytes,
            $captured,
            'Streaming download must reproduce source bytes exactly.',
        );
    }

    #[Test]
    public function regenerateMissingVariantsUsesATmpSuffixForAnExtensionlessOriginal(): void
    {
        $fs = new FailingFilesystem();
        $fs->write('blob', 'x');

        $generated = $this->backendOn($fs, new VariantRegistry(new Variant('thumb', 2, 2)))->regenerateMissingVariants(
            'blob',
        );

        static::assertSame(['blob__thumb'], $generated);
        static::assertStringEndsWith('.tmp', $this->resizer->calls[0]['dest']);
    }

    #[Test]
    public function renameMovesFileAndVariants(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        $backend->rename('docs/a.png', 'docs/renamed.png');

        static::assertFalse($backend->exists('docs/a.png'));
        static::assertTrue($backend->exists('docs/renamed.png'));
        static::assertFalse($backend->exists('docs/a__admin-thumb.png'));
        static::assertTrue($backend->exists('docs/renamed__admin-thumb.png'));
    }

    #[Test]
    public function renameThrowsWhenDestinationExists(): void
    {
        $source  = $this->writeFile('a.txt', 'plain text body');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');
        $backend->store(new UploadInput($source, 'b.txt'), 'docs');

        $this->expectException(WriteException::class);

        $backend->rename('docs/a.txt', 'docs/b.txt');
    }

    #[Test]
    public function storeDerivesNameAndExtensionFromDetectedType(): void
    {
        $source  = $this->writePng('test.bin', 10, 10);
        $backend = $this->backend();

        // Misleading .JPEG name + image/jpeg header; the detected PNG bytes win
        // and the human part is slugged to hyphens.
        $entry = $backend->store(new UploadInput($source, 'IMG_1234.JPEG', 'image/jpeg'), 'docs');

        static::assertSame('img-1234.png', $entry->name);
    }

    #[Test]
    public function storeFallsBackWhenObjectMetadataCannotBeRead(): void
    {
        $fs = (new FailingFilesystem())->failOn('fileSize', 'docs/a.txt')
            ->failOn('lastModified', 'docs/a.txt')
            ->failOn('mimeType', 'docs/a.txt');
        $before = time();

        $entry = $this->backendOn($fs)->store(new UploadInput($this->writeFile('a.txt', 'abc'), 'a.txt'), 'docs');

        static::assertSame([0, 'application/octet-stream'], [$entry->size, $entry->mime]);
        static::assertGreaterThanOrEqual($before, $entry->mtime->getTimestamp());
    }

    #[Test]
    public function storeGeneratesSiblingVariantsForImages(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');

        static::assertCount(1, $this->resizer->calls);
        static::assertSame('gallery/cat.png', $entry->path);
        // Variant lives at sibling key
        static::assertNotNull($backend->url($entry->path, 'admin-thumb'));
    }

    #[Test]
    public function storeGivesUpAfterTooManyNameCollisions(): void
    {
        $fs = new FailingFilesystem();
        $fs->write('a.txt', 'x');
        for ($i = 1; $i < 1000; ++$i) {
            $fs->write("a_{$i}.txt", 'x');
        }

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Cannot allocate unique filename in "".');
        $this->backendOn($fs)->store(new UploadInput($this->writeFile('a.txt', 'abc'), 'a.txt'), '');
    }

    #[Test]
    public function storeLeavesImageNullForNonImages(): void
    {
        $source  = $this->writeFile('notes.txt', 'just some plain text');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'notes.txt'), 'docs');

        static::assertNull($entry->image);
    }

    #[Test]
    public function storeOnlyMaterialisesTheFamiliesThePathOwns(): void
    {
        $source  = $this->writePng('owned.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(
                new Variant('tile-480', 480, 480, VariantFit::Contain),
                new Variant('mark-240', 240, 240, VariantFit::Contain),
            ),
            resizer: $this->resizer,
            paths: new PathVariantResolver(['/gallery' => ['tile']]),
        );

        $backend->store(new UploadInput($source, 'owned.png', 'image/png'), 'gallery');

        static::assertTrue($fs->fileExists('gallery/owned__tile-480.png'));
        static::assertFalse($fs->fileExists('gallery/owned__mark-240.png'));
    }

    #[Test]
    public function storePopulatesImageDimensionsAsProvenance(): void
    {
        $source  = $this->writePng('a.png', 42, 24);
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        static::assertInstanceOf(ImageMeta::class, $entry->image);
        static::assertSame(42, $entry->image->width);
        static::assertSame(24, $entry->image->height);
    }

    #[Test]
    public function storeReportsAnUploadThatCannotBeWritten(): void
    {
        $fs = (new FailingFilesystem())->failOn('writeStream', 'docs/a.txt');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed writing "docs/a.txt"');
        $this->backendOn($fs)->store(new UploadInput($this->writeFile('a.txt', 'abc'), 'a.txt'), 'docs');
    }

    #[Test]
    public function storeReportsAVariantFileThatTheResizerDidNotLeave(): void
    {
        $resizer = $this->createStub(ImageResizerInterface::class);
        $resizer->method('resize')
            ->willReturnCallback(static function (string $source, string $dest): void {
                unlink($dest);
            });
        $backend = new S3(
            fs: new FailingFilesystem(),
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(new Variant('thumb', 2, 2)),
            resizer: $resizer,
        );

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('for upload.');
        $backend->store(new UploadInput($this->writePng('a.png', 4, 4), 'a.png'), 'docs');
    }

    #[Test]
    public function storeReportsAVariantThatCannotBeWrittenAsAWriteFailure(): void
    {
        $fs = (new FailingFilesystem())->failOn('writeStream', 'docs/a__thumb.png');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed writing "docs/a__thumb.png"');
        $this->backendOn($fs, new VariantRegistry(new Variant('thumb', 2, 2)))
            ->store(new UploadInput($this->writePng('a.png', 4, 4), 'a.png'), 'docs');
    }

    #[Test]
    public function storeResolvesACollisionForANameWithoutAnExtension(): void
    {
        $fs = new FailingFilesystem();
        $fs->write('docs/readme', 'x');
        $resolver = $this->createStub(UploadResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedUpload('readme', 'text/plain'));

        $entry = $this->backendOn($fs, resolver: $resolver)->store(
            new UploadInput($this->writeFile('r', 'abc'), 'readme'),
            'docs',
        );

        static::assertSame('readme_1', $entry->name);
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
    public function storeSkipsVariantsForImagesWhenAutoGenerateIsOn(): void
    {
        $source  = $this->writePng('cat.png', 30, 30);
        $backend = $this->backend(
            new VariantRegistry(new Variant('admin-thumb', 180, 180, VariantFit::Contain)),
            autoGenerate: true,
        );

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');

        static::assertSame([], $this->resizer->calls);
        static::assertSame('gallery/cat.png', $entry->path);
        static::assertNull($backend->url($entry->path, 'admin-thumb'));
    }

    #[Test]
    public function storeSkipsVariantsForNonImages(): void
    {
        $source  = $this->writeFile('notes.txt', 'data');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        static::assertSame([], $this->resizer->calls);
    }

    #[Test]
    public function storeStoresAtTheBucketRootForAnEmptyDirectory(): void
    {
        $entry = $this->backendOn(new FailingFilesystem())->store(
            new UploadInput($this->writeFile('a.txt', 'abc'), 'a.txt'),
            '',
        );

        static::assertSame('a.txt', $entry->path);
    }

    #[Test]
    public function storeThrowsWhenSourceUnreadable(): void
    {
        $backend = $this->backend();

        $this->expectException(WriteException::class);

        $backend->store(new UploadInput('/no/such/file', 'a.txt'), 'docs');
    }

    #[Test]
    public function storeUploadsAndReturnsEntry(): void
    {
        $source  = $this->writeFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');

        static::assertInstanceOf(Entry::class, $entry);
        static::assertSame('hello.txt', $entry->name);
        static::assertSame('docs/hello.txt', $entry->path);
        static::assertSame(md5('hello.txt'), $entry->id);
    }

    #[Test]
    public function urlReturnsNullWhenVariantNotMaterialised(): void
    {
        $source  = $this->writeFile('notes.txt', 'data');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        static::assertNull($backend->url('docs/notes.txt', 'admin-thumb'));
    }

    #[Test]
    public function urlReturnsPublicUrlForExistingFile(): void
    {
        $source  = $this->writeFile('a.txt', 'data');
        $backend = $this->backend(publicUrlBase: 'https://cdn.example.com');
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');

        static::assertSame('https://cdn.example.com/docs/a.txt', $backend->url('docs/a.txt'));
    }

    #[Test]
    public function urlReturnsVariantUrlWhenMaterialised(): void
    {
        $source  = $this->writePng('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        static::assertStringContainsString('docs/a__admin-thumb.png', $backend->url('docs/a.png', 'admin-thumb'));
    }

    #[Test]
    public function urlThrowsForUnknownVariant(): void
    {
        $source  = $this->writeFile('a.txt', 'plain text body');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');

        $this->expectException(InvalidArgumentException::class);

        $backend->url('docs/a.txt', 'no-such-variant');
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

    private function backend(
        ?VariantRegistry $variants = null,
        string $publicUrlBase = 'https://cdn.test',
        bool $autoGenerate = false,
    ): S3 {
        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        return new S3(
            fs: $fs,
            publicUrlBase: $publicUrlBase,
            variants: $variants ?? new VariantRegistry(),
            resizer: $this->resizer,
            autoGenerate: $autoGenerate,
        );
    }

    private function backendOn(
        FailingFilesystem $fs,
        ?VariantRegistry $variants = null,
        ?PathVariantResolver $paths = null,
        ?UploadResolverInterface $resolver = null,
    ): S3 {
        return new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: $variants ?? new VariantRegistry(),
            resizer: $this->resizer,
            resolver: $resolver,
            paths: $paths,
        );
    }
}
