<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class VariantTest extends TestCase
{
    #[Test]
    public function targetFormatsAppendsTheSourceExtensionToDeclaredFormats(): void
    {
        // The <img> fallback resolves against the source extension, so it must
        // be materialised even when modern formats are declared.
        $variant = new Variant('card', 600, 600, VariantFit::Cover, ['avif', 'webp']);

        static::assertSame(['avif', 'webp', null], $variant->targetFormats());
    }

    #[Test]
    public function targetFormatsIsSourceOnlyWhenNoFormatsAreDeclared(): void
    {
        $variant = new Variant('thumb', 100, 100);

        static::assertSame([null], $variant->targetFormats());
    }

    #[Test]
    public function targetFormatsKeepsADeclaredFormatFirstForRepresentativeUrls(): void
    {
        // StorageInterface::url() takes the first entry as the representative
        // format; it must stay a modern one rather than the source.
        $variant = new Variant('card', 600, 600, VariantFit::Cover, ['avif']);

        static::assertSame('avif', $variant->targetFormats()[0]);
    }
}
