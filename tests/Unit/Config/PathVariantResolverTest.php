<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use Contenir\Storage\Config\PathVariantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class PathVariantResolverTest extends TestCase
{
    private const PATHS = [
        '*'                           => ['admin-thumb'],
        '/asset/library/news/lg'      => ['gallery'],
        '/asset/library/news/sm'      => ['tile'],
        '/asset/library/news/lg/hero' => ['gallery', 'mark'],
        '/asset/library/footer/lg'    => ['mark'],
    ];

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function familyCases(): array
    {
        return [
            'exact base'       => ['/asset/library/news/lg', ['gallery', 'admin-thumb']],
            'nested asset'     => ['/asset/library/news/lg/photo.jpg', ['gallery', 'admin-thumb']],
            'deeply nested'    => ['/asset/library/news/lg/2026/photo.jpg', ['gallery', 'admin-thumb']],
            'longest prefix'   => ['/asset/library/news/lg/hero/x.jpg', ['gallery', 'mark', 'admin-thumb']],
            'sibling base'     => ['/asset/library/news/sm/thumb.jpg', ['tile', 'admin-thumb']],
            'unmapped path'    => ['/asset/library/slide/lg/x.jpg', ['admin-thumb']],
            'no leading slash' => ['asset/library/footer/lg/logo.png', ['mark', 'admin-thumb']],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function familyNameCases(): array
    {
        return [
            'bare family'          => ['gallery', 'gallery'],
            'width rung'           => ['gallery-480', 'gallery'],
            'large width rung'     => ['gallery-1600', 'gallery'],
            'auto-height rung'     => ['gallery-x960', 'gallery'],
            'flat preview variant' => ['admin-thumb', 'admin-thumb'],
        ];
    }

    private static function resolver(): PathVariantResolver
    {
        return new PathVariantResolver(self::PATHS);
    }

    #[Test]
    public function allowsAcceptsFamilyRungAndUniversal(): void
    {
        $resolver = self::resolver();
        $path     = '/asset/library/news/lg/photo.jpg';

        static::assertTrue($resolver->allows($path, 'gallery')); // bare family
        static::assertTrue($resolver->allows($path, 'gallery-480')); // compiled rung
        static::assertTrue($resolver->allows($path, 'admin-thumb')); // universal
        static::assertFalse($resolver->allows($path, 'tile')); // not owned here
        static::assertFalse($resolver->allows($path, 'mark-160')); // family not owned here
    }

    #[Test]
    public function aRootEntryIsOwnedByEveryPathBelowIt(): void
    {
        $resolver = new PathVariantResolver(['/' => ['root'], '/news' => ['news']]);

        static::assertSame(['root'], $resolver->familiesFor('/blog/a.png'));
        static::assertSame(['news'], $resolver->familiesFor('news/a.png'));
        static::assertSame(['root'], $resolver->familiesFor('/'));
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('familyCases')]
    public function familiesForResolvesOwnership(string $path, array $expected): void
    {
        static::assertSame($expected, self::resolver()->familiesFor($path));
    }

    /**
     * @param non-empty-string $variant
     */
    #[Test]
    #[DataProvider('familyNameCases')]
    public function familyMapsVariantToOwningFamily(string $variant, string $expected): void
    {
        static::assertSame($expected, PathVariantResolver::family($variant));
    }

    #[Test]
    public function isConfiguredReflectsWhetherAnyOwnershipIsDeclared(): void
    {
        static::assertFalse((new PathVariantResolver([]))->isConfigured());
        static::assertTrue((new PathVariantResolver(['*' => ['admin-thumb']]))->isConfigured());
        static::assertTrue((new PathVariantResolver(['/asset/x' => ['gallery']]))->isConfigured());
    }

    #[Test]
    public function segmentBoundaryDoesNotMatchSiblingPrefix(): void
    {
        $resolver = new PathVariantResolver([
            '*'                   => ['admin-thumb'],
            '/asset/library/news' => ['gallery'],
        ]);

        // "news-archive" must NOT match the "news" base on a raw string prefix.
        static::assertSame(['admin-thumb'], $resolver->familiesFor('/asset/library/news-archive/x.jpg'));
        static::assertSame(['gallery', 'admin-thumb'], $resolver->familiesFor('/asset/library/news/x.jpg'));
    }
}
