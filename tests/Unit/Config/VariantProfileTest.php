<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use Contenir\Storage\Config\VariantProfile;
use Contenir\Storage\VariantFit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[Group('unit')]
#[Group('storage')]
final class VariantProfileTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedConfigProvider(): array
    {
        return [
            'no dimensions'      => [['fit' => 'cover'], 'non-empty "dimensions" ladder'],
            'empty dimensions'   => [['dimensions' => []], 'non-empty "dimensions" ladder'],
            'bad dimension form' => [['dimensions' => ['48Ox360']], 'must be <width>x<height>'],
            'zero both axes'     => [['dimensions' => ['x']], 'at least one of width or height'],
            'unknown fit'        => [['fit' => 'squish', 'dimensions' => ['10x10']], 'unknown fit "squish"'],
        ];
    }

    #[Test]
    public function autoHeightContainLadderKeepsHeightZeroAndWidthName(): void
    {
        $profile = VariantProfile::fromArray('image', [
            'fit'        => 'contain',
            'dimensions' => ['320x', '1920x'],
        ]);

        static::assertSame(['image-320', 'image-1920'], array_map(
            static fn($v): string => $v->name,
            $profile->variants,
        ));
        static::assertSame(320, $profile->variants[0]->width);
        static::assertSame(0, $profile->variants[0]->height);
        static::assertSame(VariantFit::Contain, $profile->variants[0]->fit);
    }

    #[Test]
    public function coverWithAutoAxisThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires both a width and a height');

        VariantProfile::fromArray('card', ['fit' => 'cover', 'dimensions' => ['480x']]);
    }

    #[Test]
    public function duplicateExpandedNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than once');

        VariantProfile::fromArray('card', [
            'fit'        => 'cover',
            'dimensions' => ['320x320', '320x480'],
        ]);
    }

    #[Test]
    public function expandsCoverLadderToWidthNamedVariants(): void
    {
        $profile = VariantProfile::fromArray('card', [
            'fit'        => 'cover',
            'quality'    => 75,
            'formats'    => ['avif', 'webp'],
            'sizes'      => '(min-width: 1024px) 33vw, 50vw, 100vw',
            'dimensions' => ['320x320', '480x480', '600x600', '768x768'],
        ]);

        static::assertSame('card', $profile->name);
        static::assertSame('(min-width: 1024px) 33vw, 50vw, 100vw', $profile->sizes);
        static::assertFalse($profile->isPreview);
        static::assertCount(4, $profile->variants);

        $first = $profile->variants[0];
        static::assertSame('card-320', $first->name);
        static::assertSame(320, $first->width);
        static::assertSame(320, $first->height);
        static::assertSame(VariantFit::Cover, $first->fit);
        static::assertSame(['avif', 'webp'], $first->formats);
        static::assertSame(75, $first->quality);

        static::assertSame(
            ['card-320', 'card-480', 'card-600', 'card-768'],
            array_map(static fn($v): string => $v->name, $profile->variants),
        );
    }

    #[Test]
    public function fillFitAndNonListFormatsAreAccepted(): void
    {
        $profile = VariantProfile::fromArray('banner', [
            'fit'        => 'fill',
            'formats'    => 'avif',
            'dimensions' => ['100x50'],
        ]);

        static::assertSame([VariantFit::Fill, []], [$profile->variants[0]->fit, $profile->variants[0]->formats]);
    }

    #[Test]
    public function frontEndProfileRejectsWidthlessRung(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a width for the srcset descriptor');

        VariantProfile::fromArray('hero', ['fit' => 'contain', 'dimensions' => ['x600']]);
    }

    #[Test]
    public function perRungOverrideWinsOverProfileDefaults(): void
    {
        $profile = VariantProfile::fromArray('hero', [
            'fit'        => 'contain',
            'quality'    => 80,
            'formats'    => ['avif'],
            'dimensions' => [
                '480x360',
                '1280x960' => ['fit' => 'cover', 'quality' => 70, 'formats' => ['webp']],
            ],
        ]);

        $base     = $profile->variants[0];
        $override = $profile->variants[1];

        static::assertSame(VariantFit::Contain, $base->fit);
        static::assertSame(80, $base->quality);
        static::assertSame(['avif'], $base->formats);

        static::assertSame('hero-1280', $override->name);
        static::assertSame(VariantFit::Cover, $override->fit);
        static::assertSame(70, $override->quality);
        static::assertSame(['webp'], $override->formats);
    }

    #[Test]
    public function previewRoleIsFlaggedAndExcludedFromWidthRule(): void
    {
        $profile = VariantProfile::fromArray('admin-thumb', [
            'fit'        => 'contain',
            'role'       => 'preview',
            'dimensions' => ['180x180'],
        ]);

        static::assertTrue($profile->isPreview);
        static::assertSame('admin-thumb-180', $profile->variants[0]->name);
    }

    #[Test]
    public function previewRungWithoutAWidthIsNamedAfterItsHeight(): void
    {
        $profile = VariantProfile::fromArray('strip', ['role' => 'preview', 'dimensions' => ['x300']]);

        static::assertSame('strip-x300', $profile->variants[0]->name);
    }

    #[Test]
    public function qualityDefaultsToNullWhenUnset(): void
    {
        $profile = VariantProfile::fromArray('plain', ['dimensions' => ['100x100']]);

        static::assertNull($profile->variants[0]->quality);
        static::assertSame([], $profile->variants[0]->formats);
    }

    /**
     * @param list<string> $dimensions
     */
    #[Test]
    #[DataProvider('malformedConfigProvider')]
    public function rejectsMalformedDeclarations(array $config, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        VariantProfile::fromArray('p', $config);
    }
}
