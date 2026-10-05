<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[Group('unit')]
#[Group('storage')]
final class VariantRegistryTest extends TestCase
{
    #[Test]
    public function allowedForGivesAnUndeclaredPathOnlyTheUniversalFamilies(): void
    {
        $registry = new VariantRegistry(new Variant('tile-480', 480, 480), new Variant('admin-thumb', 180, 180));
        $paths    = new PathVariantResolver([
            '*'                      => ['admin-thumb'],
            '/asset/library/news/lg' => ['tile'],
        ]);

        $names = array_map(
            static fn(Variant $variant): string => $variant->name,
            $registry->allowedFor($paths, '/asset/library/other/photo.jpg'),
        );

        static::assertSame(['admin-thumb'], $names);
    }

    #[Test]
    public function allowedForKeepsOnlyTheFamiliesThePathOwns(): void
    {
        $registry = new VariantRegistry(
            new Variant('tile-480', 480, 480),
            new Variant('mark-240', 240, 240),
            new Variant('admin-thumb', 180, 180),
        );
        $paths = new PathVariantResolver([
            '*'                      => ['admin-thumb'],
            '/asset/library/news/lg' => ['tile'],
        ]);

        $names = array_map(
            static fn(Variant $variant): string => $variant->name,
            $registry->allowedFor($paths, '/asset/library/news/lg/photo.jpg'),
        );

        static::assertSame(['tile-480', 'admin-thumb'], $names);
    }

    #[Test]
    public function allowedForReturnsEveryVariantWhenNoResolverIsGiven(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100), new Variant('hero', 900, 600));

        static::assertCount(2, $registry->allowedFor(null, '/asset/library/news/lg/x.png'));
    }

    #[Test]
    public function allowedForReturnsEveryVariantWhenOwnershipIsUndeclared(): void
    {
        // An empty map means nothing is declared, so enforcing it would strip
        // every variant; the permissive default matches the render-time guard.
        $registry = new VariantRegistry(new Variant('thumb', 100, 100), new Variant('hero', 900, 600));

        static::assertCount(2, $registry->allowedFor(new PathVariantResolver([]), '/asset/library/news/lg/x.png'));
    }

    #[Test]
    public function allReturnsAllRegisteredVariants(): void
    {
        $thumb = new Variant('thumb', 100, 100);
        $hero  = new Variant('hero', 1200, 600);

        $registry = new VariantRegistry($thumb, $hero);

        static::assertSame([$thumb, $hero], $registry->all());
    }

    #[Test]
    public function emptyRegistryHasNoVariants(): void
    {
        $registry = new VariantRegistry();

        static::assertSame([], $registry->all());
    }

    #[Test]
    public function getReturnsRegisteredVariant(): void
    {
        $variant  = new Variant('thumb', 100, 100, VariantFit::Cover);
        $registry = new VariantRegistry($variant);

        static::assertSame($variant, $registry->get('thumb'));
    }

    #[Test]
    public function getThrowsForUnknownVariant(): void
    {
        $registry = new VariantRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown variant "thumb"');

        $registry->get('thumb');
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredVariant(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100));

        static::assertFalse($registry->has('hero'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredVariant(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100));

        static::assertTrue($registry->has('thumb'));
    }
}
