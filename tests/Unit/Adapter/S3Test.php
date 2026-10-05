<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use Contenir\Storage\Adapter\S3;
use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\Tests\TestAsset\Flysystem\FailingFilesystem;
use Contenir\Storage\Tests\TestAsset\Image\PngFactory;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function md5;
use function sort;

/**
 * The bucket is an in-memory Flysystem (FailingFilesystem), so nothing here
 * touches the network or the local disk. Flows that resize through temp files
 * live in the integration suite.
 */
#[Group('unit')]
#[Group('storage')]
final class S3Test extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function extensionMimeProvider(): array
    {
        return [
            'jpg'     => ['a.jpg', 'image/jpeg'],
            'jpeg'    => ['a.jpeg', 'image/jpeg'],
            'png'     => ['a.png', 'image/png'],
            'gif'     => ['a.gif', 'image/gif'],
            'webp'    => ['a.webp', 'image/webp'],
            'svg'     => ['a.svg', 'image/svg+xml'],
            'avif'    => ['a.avif', 'image/avif'],
            'mp3'     => ['a.mp3', 'audio/mpeg'],
            'm4a'     => ['a.m4a', 'audio/mp4'],
            'ogg'     => ['a.ogg', 'audio/ogg'],
            'oga'     => ['a.oga', 'audio/ogg'],
            'wav'     => ['a.wav', 'audio/wav'],
            'mp4'     => ['a.mp4', 'video/mp4'],
            'm4v'     => ['a.m4v', 'video/mp4'],
            'mov'     => ['a.mov', 'video/quicktime'],
            'webm'    => ['a.webm', 'video/webm'],
            'pdf'     => ['a.pdf', 'application/pdf'],
            'unknown' => ['a.xyz', 'application/octet-stream'],
            'upper'   => ['A.PNG', 'image/png'],
        ];
    }

    /**
     * @return array<string, array{ListOptions, list<string>}>
     */
    public static function sortProvider(): array
    {
        return [
            'name ascending'  => [new ListOptions(), ['a.txt', 'b.pdf', 'c.png']],
            'name descending' => [new ListOptions(sortDirection: SortDirection::Desc), ['c.png', 'b.pdf', 'a.txt']],
            'size'            => [new ListOptions(sortField: SortField::Size), ['c.png', 'a.txt', 'b.pdf']],
            'type'            => [new ListOptions(sortField: SortField::Type), ['a.txt', 'b.pdf', 'c.png']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unparseableVariantKeyProvider(): array
    {
        return [
            'no extension'       => ['gallery/cat__thumb'],
            'no separator'       => ['gallery/cat.png'],
            'empty base'         => ['__thumb.png'],
            'empty variant name' => ['gallery/cat__.png'],
            'unknown variant'    => ['gallery/cat__nope.png'],
            'unsupported format' => ['gallery/cat__thumb.tiff'],
        ];
    }

    #[Test]
    public function clearKeyCacheForcesExistenceToBeProbedAgain(): void
    {
        $this->fs->write('docs/a.txt', 'a');
        $backend = $this->backend();
        [...$backend->list('docs')];
        $this->fs->inner->delete('docs/a.txt');

        static::assertSame('https://cdn.test/docs/a.txt', $backend->url('docs/a.txt'));

        $backend->clearKeyCache();

        static::assertNull($backend->url('docs/a.txt'));
    }

    #[Test]
    public function deleteManyRemovesExactlyTheKeysGiven(): void
    {
        $this->fs->write('junk/a.png', 'a');
        $this->fs->write('junk/a__card.png', 'v');
        $this->fs->write('keep/c.png', 'c');

        $failed = $this->backend($this->variants(new Variant('card', 600, 600)))->deleteMany([
            'junk/a.png',
            '/keep/c.png',
        ]);

        static::assertSame([], $failed);
        static::assertSame(['junk/a__card.png'], $this->keys());
    }

    #[Test]
    public function deleteManyReportsEachKeyThatCouldNotBeRemoved(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('b.png', 'b');
        $this->fs->failOn('delete', 'a.png');

        $failed = $this->backend()->deleteMany(['a.png', 'b.png']);

        static::assertSame(['a.png' => 'Unable to delete file located at: a.png. delete refused'], $failed);
        static::assertSame(['a.png'], $this->keys());
    }

    #[Test]
    public function deleteManyReportsEveryKeyThatCouldNotBeRemoved(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('b.png', 'b');
        $this->fs->failOn('delete', 'a.png')->failOn('delete', 'b.png');

        static::assertSame(['a.png', 'b.png'], array_keys($this->backend()->deleteMany(['a.png', 'b.png'])));
    }

    #[Test]
    public function deleteManyTreatsAnAbsentKeyAsAlreadySatisfied(): void
    {
        static::assertSame([], $this->backend()->deleteMany(['never/existed.png']));
    }

    #[Test]
    public function deleteRejectsAMissingObject(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->delete('nope.txt');
    }

    #[Test]
    public function deleteRemovesTheObjectAndEveryVariantSibling(): void
    {
        $this->fs->write('docs/a.png', 'a');
        $this->fs->write('docs/a__thumb.png', 't');
        $this->fs->write('docs/a__card.avif', 'c');
        $this->fs->write('docs/a__card.png', 'c');
        $this->fs->write('docs/b.png', 'b');

        $this->backend($this->variants(
            new Variant('thumb', 10, 10),
            new Variant('card', 20, 20, formats: ['avif']),
        ))->delete('docs/a.png');

        static::assertSame(['docs/b.png'], $this->keys());
    }

    #[Test]
    public function deleteReportsAnObjectThatCannotBeRemoved(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->failOn('delete', 'a.png');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed deleting "a.png"');
        $this->backend()->delete('a.png');
    }

    #[Test]
    public function deleteToleratesAVariantThatCannotBeRemoved(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('a__thumb.png', 't');
        $this->fs->failOn('delete', 'a__thumb.png');

        $this->backend($this->variants(new Variant('thumb', 10, 10)))->delete('a.png');

        static::assertSame(['a__thumb.png'], $this->keys());
    }

    #[Test]
    public function existsReflectsTheBucket(): void
    {
        $this->fs->write('docs/a.txt', 'a');
        $backend = $this->backend();

        static::assertTrue($backend->exists('\\docs\\a.txt'));
        static::assertFalse($backend->exists('docs/b.txt'));
    }

    #[Test]
    public function generateForKeyDeclinesAFamilyTheOriginalDoesNotOwn(): void
    {
        $this->fs->write('news/cat.png', PngFactory::bytes(4, 4));
        $backend = $this->backend(
            $this->variants(new Variant('gallery-480', 480, 0, VariantFit::Contain)),
            new PathVariantResolver(['/gallery' => ['gallery']]),
        );

        static::assertNull($backend->generateForKey('news/cat__gallery-480.png'));
    }

    #[Test]
    public function generateForKeyDeclinesAnEmptyBaseEvenWhenADotFileOriginalExists(): void
    {
        $this->fs->write('.png', PngFactory::bytes(4, 4));

        static::assertNull($this->backend($this->variants(new Variant('thumb', 2, 2)))->generateForKey('__thumb.png'));
    }

    #[Test]
    #[DataProvider('unparseableVariantKeyProvider')]
    public function generateForKeyDeclinesKeysItCannotGenerate(string $key): void
    {
        $this->fs->write('gallery/cat.png', PngFactory::bytes(4, 4));

        static::assertNull($this->backend($this->variants(new Variant('thumb', 2, 2)))->generateForKey($key));
    }

    #[Test]
    public function generateForKeyDeclinesWhenNoOriginalExists(): void
    {
        $backend = $this->backend($this->variants(new Variant('thumb', 2, 2)));

        static::assertNull($backend->generateForKey('gallery/ghost__thumb.png'));
    }

    #[Test]
    public function generateForKeyReturnsAnExistingVariantWithoutResizing(): void
    {
        $this->fs->write('gallery/cat.png', 'x');
        $this->fs->write('gallery/cat__thumb.webp', 'v');

        $url = $this->backend($this->variants(new Variant('thumb', 2, 2)))->generateForKey('gallery/cat__thumb.WEBP');

        static::assertSame('https://cdn.test/gallery/cat__thumb.WEBP', $url);
    }

    #[Test]
    public function imageMetaReadsDimensionsFromTheObjectBytes(): void
    {
        $this->fs->write('a.png', PngFactory::bytes(7, 3));

        $meta = $this->backend()->imageMeta('a.png');

        static::assertSame([7, 3, 'image/png'], [$meta->width, $meta->height, $meta->mime]);
    }

    #[Test]
    public function imageMetaRejectsAMissingObject(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->imageMeta('nope.png');
    }

    #[Test]
    public function imageMetaRejectsAnObjectThatCannotBeRead(): void
    {
        $this->fs->write('a.png', PngFactory::bytes(1, 1));
        $this->fs->failOn('read', 'a.png');

        $this->expectException(NotFoundException::class);
        $this->backend()->imageMeta('a.png');
    }

    #[Test]
    public function imageMetaRejectsAnObjectThatIsNotAnImage(): void
    {
        $this->fs->write('a.txt', 'text');

        $this->expectException(NotFoundException::class);
        $this->backend()->imageMeta('a.txt');
    }

    #[Test]
    public function imageMetaTrustsTheExistenceCheckOverAReadableBody(): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('fileExists')->willReturn(false);
        $fs->method('read')->willReturn(PngFactory::bytes(4, 4));
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(),
            resizer: $this->createStub(ImageResizerInterface::class),
        );

        $this->expectException(NotFoundException::class);
        $backend->imageMeta('a.png');
    }

    #[Test]
    public function listDoesNotDescendIntoSubdirectories(): void
    {
        $this->fs->write('docs/a.txt', 'a');
        $this->fs->write('docs/sub/b.txt', 'b');

        static::assertSame(['a.txt'], $this->names($this->backend()->list('docs')));
    }

    #[Test]
    public function listFiltersByKeyword(): void
    {
        $this->fs->write('docs/report.pdf', 'r');
        $this->fs->write('docs/notes.txt', 'n');

        static::assertSame(
            ['report.pdf'],
            $this->names($this->backend()->list('docs', new ListOptions(keyword: 'REP'))),
        );
    }

    #[Test]
    public function listHidesDotFilesVariantSiblingsAndDirectoriesByDefault(): void
    {
        $this->fs->write('docs/a.png', 'a');
        $this->fs->write('docs/a__thumb.png', 't');
        $this->fs->write('docs/b__other.png', 'b');
        $this->fs->write('docs/.hidden', 'h');
        $this->fs->createDirectory('docs/sub');

        $names = $this->names($this->backend($this->variants(new Variant('thumb', 1, 1)))->list('/docs/'));

        static::assertSame(['a.png', 'b__other.png'], $names);
    }

    #[Test]
    public function listIncludesDirectoriesWhenAsked(): void
    {
        $this->fs->createDirectory('docs/sub');

        $entries = [...$this->backend()->list('docs', new ListOptions(includeDirectories: true))];

        static::assertCount(1, $entries);
        static::assertSame(['sub', true, 0, 'inode/directory'], [
            $entries[0]->name,
            $entries[0]->isDir,
            $entries[0]->size,
            $entries[0]->mime,
        ]);
    }

    #[Test]
    #[DataProvider('extensionMimeProvider')]
    public function listInfersTheMimeTypeFromTheExtension(string $name, string $mime): void
    {
        $this->fs->write("docs/{$name}", 'x');

        $entries = [...$this->backend()->list('docs')];

        static::assertSame($mime, $entries[0]->mime);
    }

    #[Test]
    public function listKeepsScanningPastAnEntryTheKeywordFiltersOut(): void
    {
        $this->fs->write('docs/a-notes.txt', 'n');
        $this->fs->write('docs/b-report.pdf', 'r');

        static::assertSame(
            ['b-report.pdf'],
            $this->names($this->backend()->list('docs', new ListOptions(keyword: 'report'))),
        );
    }

    #[Test]
    public function listPrefersTheMimeTypeTheBucketReports(): void
    {
        $this->fs->write('docs/a.png', 'plain text');
        $this->fs->listsMimeTypes = true;

        static::assertSame('text/plain', [...$this->backend()->list('docs')][0]->mime);
    }

    #[Test]
    public function listRejectsAPrefixThatCannotBeListed(): void
    {
        $this->fs->failOn('listContents', 'docs');

        $this->expectException(NotFoundException::class);
        [...$this->backend()->list('docs')];
    }

    #[Test]
    public function listReportsAZeroSizeWhenTheBucketOmitsIt(): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('listContents')->willReturn(new DirectoryListing([new FileAttributes('docs/a.txt')]));
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: new VariantRegistry(),
            resizer: $this->createStub(ImageResizerInterface::class),
        );

        static::assertSame([0], array_map(static fn(Entry $entry): int => $entry->size, [...$backend->list('docs')]));
    }

    #[Test]
    public function listReportsSizeAndModificationTime(): void
    {
        $this->fs->write('docs/a.txt', 'abc');

        $entry = [...$this->backend()->list('docs')][0];

        static::assertSame(3, $entry->size);
        static::assertSame($this->fs->lastModified('docs/a.txt'), $entry->mtime->getTimestamp());
        static::assertSame(md5('a.txt'), $entry->id);
    }

    #[Test]
    public function listSortsByModificationTime(): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('listContents')->willReturn(new DirectoryListing([
            new FileAttributes('docs/b.pdf', 1, null, 200),
            new FileAttributes('docs/a.txt', 1, null, 100),
            new FileAttributes('docs/c.png', 1, null, 300),
        ]));
        $backend = new S3(
            $fs,
            'https://cdn.test',
            new VariantRegistry(),
            $this->createStub(ImageResizerInterface::class),
        );

        static::assertSame(
            ['c.png', 'b.pdf', 'a.txt'],
            $this->names($backend->list('docs', new ListOptions(
                sortField: SortField::Time,
                sortDirection: SortDirection::Desc,
            ))),
        );
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('sortProvider')]
    public function listSortsByTheRequestedField(ListOptions $options, array $expected): void
    {
        $this->fs->write('docs/b.pdf', 'bbb');
        $this->fs->write('docs/a.txt', 'aa');
        $this->fs->write('docs/c.png', 'c');

        static::assertSame($expected, $this->names($this->backend()->list('docs', $options)));
    }

    private FailingFilesystem $fs;

    #[Test]
    public function listTreatsAnEmptyKeywordAsNoFilter(): void
    {
        $this->fs->write('docs/a.txt', 'a');

        static::assertSame(['a.txt'], $this->names($this->backend()->list('docs', new ListOptions(keyword: ''))));
    }

    #[Test]
    public function missingVariantsListsUnmaterialisedSiblingsWithoutWriting(): void
    {
        $this->fs->write('gallery/cat.png', 'x');
        $this->fs->write('gallery/cat__thumb.png', 't');

        $missing = $this->backend($this->variants(
            new Variant('thumb', 1, 1),
            new Variant('card', 2, 2, formats: ['avif']),
        ))->missingVariants('gallery/cat.png');

        static::assertSame(['gallery/cat__card.avif', 'gallery/cat__card.png'], $missing);
        static::assertCount(2, $this->keys());
    }

    #[Test]
    public function missingVariantsRejectsAMissingOriginal(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->missingVariants('nope.png');
    }

    #[Test]
    public function regenerateMissingVariantsIsANoOpWhenEverythingExists(): void
    {
        $this->fs->write('gallery/cat.png', 'x');
        $this->fs->write('gallery/cat__thumb.png', 't');

        static::assertSame(
            [],
            $this->backend($this->variants(new Variant('thumb', 1, 1)))->regenerateMissingVariants('gallery/cat.png'),
        );
    }

    #[Test]
    public function regenerateMissingVariantsRejectsAMissingOriginal(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->regenerateMissingVariants('gallery/does-not-exist.png');
    }

    #[Test]
    public function regenerateMissingVariantsSkipsTheDownloadWhenNothingIsMissing(): void
    {
        $this->fs->write('gallery/cat.png', 'x');
        $this->fs->write('gallery/cat__thumb.png', 't');
        $this->fs->failOn('readStream', 'gallery/cat.png');

        static::assertSame(
            [],
            $this->backend($this->variants(new Variant('thumb', 1, 1)))->regenerateMissingVariants('gallery/cat.png'),
        );
    }

    #[Test]
    public function renameDoesNotAdvertiseAVariantThatCannotBeMoved(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('a__thumb.png', 't');
        $this->fs->failOn('move', 'a__thumb.png');
        $backend = $this->backend($this->variants(new Variant('thumb', 1, 1)));

        $backend->rename('a.png', 'b.png');

        static::assertNull($backend->url('b.png', 'thumb'));
    }

    #[Test]
    public function renameDoesNotAdvertiseAVariantThatNeverExisted(): void
    {
        $fs = $this->createStub(FilesystemOperator::class);
        $fs->method('fileExists')->willReturnCallback(static fn(string $key): bool => 'a.png' === $key);
        $backend = new S3(
            fs: $fs,
            publicUrlBase: 'https://cdn.test',
            variants: $this->variants(new Variant('thumb', 1, 1)),
            resizer: $this->createStub(ImageResizerInterface::class),
        );

        $backend->rename('a.png', 'b.png');

        static::assertNull($backend->url('b.png', 'thumb'));
    }

    #[Test]
    public function renameLeavesAVariantBehindWhenItCannotBeMoved(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('a__thumb.png', 't');
        $this->fs->failOn('move', 'a__thumb.png');

        $this->backend($this->variants(new Variant('thumb', 1, 1)))->rename('a.png', 'b.png');

        static::assertSame(['a__thumb.png', 'b.png'], $this->keys());
    }

    #[Test]
    public function renameMovesTheObjectAndItsVariantSiblings(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('a__thumb.png', 't');
        $backend = $this->backend($this->variants(new Variant('thumb', 1, 1), new Variant('card', 2, 2)));

        $backend->rename('a.png', 'b.png');

        static::assertSame(['b.png', 'b__thumb.png'], $this->keys());
        static::assertSame('https://cdn.test/b__thumb.png', $backend->url('b.png', 'thumb'));
    }

    #[Test]
    public function renameRefusesToOverwriteAnExistingObject(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('b.png', 'b');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Destination "b.png" already exists.');
        $this->backend()->rename('a.png', 'b.png');
    }

    #[Test]
    public function renameRejectsAMissingSource(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->rename('nope.txt', 'somewhere.txt');
    }

    #[Test]
    public function renameReportsAMoveFailure(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->failOn('move', 'a.png');

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed renaming "a.png" to "b.png"');
        $this->backend()->rename('a.png', 'b.png');
    }

    #[Test]
    public function renameSkipsAVariantWhoseExistenceCannotBeChecked(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->failOn('fileExists', 'a__thumb.png');

        $this->backend($this->variants(new Variant('thumb', 1, 1)))->rename('a.png', 'b.png');

        static::assertSame(['b.png'], $this->keys());
    }

    #[Test]
    public function urlNormalisesBackslashesInThePath(): void
    {
        $this->fs->write('docs/a.txt', 'a');

        static::assertSame('https://cdn.test/docs/a.txt', $this->backend()->url('\\docs\\a.txt'));
    }

    #[Test]
    public function urlRejectsAnUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->backend()->url('a.png', 'nope');
    }

    #[Test]
    public function urlReturnsNullForAMissingObject(): void
    {
        static::assertNull($this->backend()->url('nope.txt'));
    }

    #[Test]
    public function urlReturnsNullForAVariantThatIsNotMaterialised(): void
    {
        $this->fs->write('a.png', 'a');

        static::assertNull($this->backend($this->variants(new Variant('thumb', 1, 1)))->url('a.png', 'thumb'));
    }

    #[Test]
    public function urlReturnsTheFirstDeclaredFormatOfAMaterialisedVariant(): void
    {
        $this->fs->write('a.png', 'a');
        $this->fs->write('a__card.avif', 'v');

        static::assertSame(
            'https://cdn.test/a__card.avif',
            $this->backend($this->variants(new Variant('card', 1, 1, formats: ['avif', 'webp'])))->url('a.png', 'card'),
        );
    }

    #[Test]
    public function urlReturnsThePublicUrlOfAnExistingObject(): void
    {
        $this->fs->write('docs/a.txt', 'a');

        static::assertSame(
            'https://cdn.test/docs/a.txt',
            $this->backend(publicUrlBase: 'https://cdn.test/')->url('/docs/a.txt'),
        );
    }

    #[Test]
    public function urlsForKeyKeepsAnExtensionlessKeyExtensionless(): void
    {
        static::assertSame(
            ['https://cdn.test/docs/logo', 'https://cdn.test/docs/logo__thumb'],
            $this->backend($this->variants(new Variant('thumb', 1, 1)))->urlsForKey('docs/logo'),
        );
    }

    #[Test]
    public function urlsForKeyReturnsTheOriginalAndEveryDeclaredSiblingWithoutChecking(): void
    {
        $urls = $this->backend($this->variants(
            new Variant('thumb', 1, 1),
            new Variant('card', 2, 2, formats: ['avif']),
        ))->urlsForKey('docs/logo.png');

        static::assertSame(
            [
                'https://cdn.test/docs/logo.png',
                'https://cdn.test/docs/logo__thumb.png',
                'https://cdn.test/docs/logo__card.avif',
                'https://cdn.test/docs/logo__card.png',
            ],
            $urls,
        );
    }

    #[Test]
    public function variantUrlsAreKeyedByFormatWithoutCheckingExistence(): void
    {
        static::assertSame(
            [
                'avif'   => 'https://cdn.test/docs/ghost__card.avif',
                'source' => 'https://cdn.test/docs/ghost__card.png',
            ],
            $this->backend($this->variants(new Variant('card', 1, 1, formats: ['avif'])))->variantUrls(
                'docs/ghost.png',
                'card',
            ),
        );
    }

    #[Test]
    public function variantUrlsRejectAnUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->backend()->variantUrls('docs/a.png', 'no-such-variant');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fs = new FailingFilesystem();
    }

    private function backend(
        ?VariantRegistry $variants = null,
        ?PathVariantResolver $paths = null,
        string $publicUrlBase = 'https://cdn.test',
    ): S3 {
        return new S3(
            fs: $this->fs,
            publicUrlBase: $publicUrlBase,
            variants: $variants ?? new VariantRegistry(),
            resizer: $this->createStub(ImageResizerInterface::class),
            paths: $paths,
        );
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = array_map(
            static fn($attributes): string => $attributes->path(),
            $this->fs
                ->inner
                ->listContents('', deep: true)
                ->filter(static fn($attributes): bool => $attributes->isFile())
                ->toArray(),
        );
        sort($keys);

        return $keys;
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

    private function variants(Variant ...$variants): VariantRegistry
    {
        return new VariantRegistry(...$variants);
    }
}
