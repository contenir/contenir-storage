<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\ListOptions;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;

#[Group('unit')]
#[Group('storage')]
final class CloudflareImagesTest extends TestCase
{
    /** @return array<string, array{0: VariantFit, 1: string}> */
    public static function fitParamProvider(): array
    {
        return [
            'Cover → cover'     => [VariantFit::Cover, 'cover'],
            'Contain → contain' => [VariantFit::Contain, 'contain'],
            'Fill → crop'       => [VariantFit::Fill, 'crop'],
        ];
    }

    #[Test]
    public function deleteDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.txt', 'data');
        $backend = $this->backend($inner);

        $backend->delete('a.txt');

        static::assertFalse($inner->exists('a.txt'));
    }

    #[Test]
    public function deleteThrowsForMissingFile(): void
    {
        $backend = $this->backend(new InMemoryStorage());

        $this->expectException(NotFoundException::class);

        $backend->delete('nope.txt');
    }

    #[Test]
    public function existsDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.txt', 'data');
        $backend = $this->backend($inner);

        static::assertTrue($backend->exists('a.txt'));
        static::assertFalse($backend->exists('nope.txt'));
    }

    #[Test]
    #[DataProvider('fitParamProvider')]
    public function fitMapsToCloudflareParam(VariantFit $fit, string $expected): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner, [new Variant('v', 100, 100, $fit)]);

        $url = $backend->url('a.jpg', 'v');

        static::assertNotNull($url);
        static::assertStringContainsString("fit={$expected}/", $url);
    }

    #[Test]
    public function imageMetaDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.png', 'bytes', 'image/png', 100, 50);
        $backend = $this->backend($inner);

        $meta = $backend->imageMeta('a.png');

        static::assertSame(100, $meta->width);
        static::assertSame(50, $meta->height);
    }

    #[Test]
    public function listDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('docs/a.txt', 'a');
        $inner->putFile('docs/b.txt', 'b');
        $backend = $this->backend($inner);

        $entries = [...$backend->list('docs')];
        $names   = array_map(static fn(Entry $e): string => $e->name, $entries);
        sort($names);

        static::assertSame(['a.txt', 'b.txt'], $names);
    }

    #[Test]
    public function listPassesOptionsThroughToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('docs/report.pdf', 'r');
        $inner->putFile('docs/notes.txt', 'n');
        $backend = $this->backend($inner);

        $entries = [...$backend->list('docs', new ListOptions(keyword: 'report'))];

        static::assertCount(1, $entries);
        static::assertSame('report.pdf', $entries[0]->name);
    }

    #[Test]
    public function missingVariantsIsAlwaysEmptyForAnExistingFile(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.png', 'x');

        static::assertSame([], $this->backend($inner, [new Variant('thumb', 1, 1)])->missingVariants('a.png'));
    }

    #[Test]
    public function missingVariantsRejectsAMissingFileEvenWhenTheStoreCannotReport(): void
    {
        $inner = $this->createStub(StorageInterface::class);
        $inner->method('exists')->willReturn(false);

        $this->expectException(NotFoundException::class);
        (new CloudflareImages($inner, 'https://cdn.example.com', new VariantRegistry()))->missingVariants('gone.png');
    }

    #[Test]
    public function regenerateMissingVariantsAlwaysEmptyForExistingFile(): void
    {
        // URL-transform variants don't need pre-materialising — backfill
        // is a no-op once the original is in place.
        $inner = new InMemoryStorage();
        $inner->putFile('a.png', 'bytes', 'image/png');
        $backend = $this->backend($inner, [
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            new Variant('hero', 1600, 1200, VariantFit::Cover, ['avif', 'webp'], 80),
        ]);

        static::assertSame([], $backend->regenerateMissingVariants('a.png'));
    }

    #[Test]
    public function regenerateMissingVariantsThrowsForMissingSource(): void
    {
        $inner   = new InMemoryStorage();
        $backend = $this->backend($inner, [new Variant('admin-thumb', 180, 180)]);

        $this->expectException(NotFoundException::class);
        $backend->regenerateMissingVariants('a.png');
    }

    #[Test]
    public function renameDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.txt', 'data');
        $backend = $this->backend($inner);

        $backend->rename('a.txt', 'renamed.txt');

        static::assertFalse($inner->exists('a.txt'));
        static::assertTrue($inner->exists('renamed.txt'));
    }

    #[Test]
    public function storeDelegatesToObjectStoreUnchanged(): void
    {
        $upload = new UploadInput('/tmp/upload', 'note.txt', 'text/plain');
        $entry  = new Entry('id', 'note.txt', 'docs/note.txt', false, 4, new DateTimeImmutable(), 'text/plain');
        $inner  = $this->createMock(StorageInterface::class);
        $inner->expects(self::once())->method('store')->with($upload, 'docs')->willReturn($entry);

        static::assertSame($entry, (new CloudflareImages(
            $inner,
            'https://cdn.example.com',
            new VariantRegistry(),
        ))->store($upload, 'docs'));
    }

    #[Test]
    public function urlReturnsNullForMissingFileEvenWithVariant(): void
    {
        $backend = $this->backend(new InMemoryStorage(), [
            new Variant('admin-thumb', 180, 180),
        ]);

        static::assertNull($backend->url('does-not-exist.jpg', 'admin-thumb'));
    }

    #[Test]
    public function urlsForKeyAddsATransformUrlPerVariantToTheStoresUrls(): void
    {
        $urls = $this->backend(new InMemoryStorage(), [new Variant(
            'thumb',
            180,
            120,
            VariantFit::Cover,
        )])->urlsForKey('a.png');

        static::assertSame(
            [
                'memory://a.png',
                'https://cdn.example.com/cdn-cgi/image/width=180,height=120,fit=cover/a.png',
            ],
            $urls,
        );
    }

    #[Test]
    public function urlStripsLeadingSlashesAndDoubleSlashes(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('products/hero.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner, [new Variant('thumb', 100, 100, VariantFit::Contain)]);

        static::assertSame(
            'https://cdn.example.com/cdn-cgi/image/width=100,height=100,fit=contain/products/hero.jpg',
            $backend->url('products/hero.jpg', 'thumb'),
        );
    }

    #[Test]
    public function urlThrowsForUnknownVariant(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner);

        $this->expectException(InvalidArgumentException::class);

        $backend->url('a.jpg', 'no-such-variant');
    }

    #[Test]
    public function urlWithoutVariantDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('products/hero.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner);

        static::assertSame('memory://products/hero.jpg', $backend->url('products/hero.jpg'));
    }

    #[Test]
    public function urlWithVariantBuildsCloudflareTransformUrl(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('products/hero.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner, [
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ]);

        static::assertSame(
            'https://cdn.example.com/cdn-cgi/image/width=180,height=180,fit=contain/products/hero.jpg',
            $backend->url('products/hero.jpg', 'admin-thumb'),
        );
    }

    #[Test]
    public function variantUrlsAreDeterministicWithoutExistenceCheck(): void
    {
        // The object is NOT in the store. url() probes and returns null, but
        // variantUrls() trusts the key and builds the transform URL anyway —
        // no exists() round-trip on the render path.
        $backend = $this->backend(new InMemoryStorage(), [
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ]);

        static::assertNull($backend->url('missing.jpg', 'admin-thumb'));
        static::assertSame(
            ['source' => 'https://cdn.example.com/cdn-cgi/image/width=180,height=180,fit=contain/missing.jpg'],
            $backend->variantUrls('missing.jpg', 'admin-thumb'),
        );
    }

    #[Test]
    public function variantUrlsThrowsForUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->backend(new InMemoryStorage())->variantUrls('a.jpg', 'no-such-variant');
    }

    /**
     * @param list<Variant> $variants
     */
    private function backend(InMemoryStorage $inner, array $variants = []): CloudflareImages
    {
        return new CloudflareImages(
            objectStore: $inner,
            deliveryBaseUrl: 'https://cdn.example.com',
            variants: new VariantRegistry(...$variants),
        );
    }
}
