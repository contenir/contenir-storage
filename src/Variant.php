<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Named image variant (e.g. an admin thumbnail).
 *
 * Variants are declared by the application up-front and consumed by storage
 * backends — local backends pre-generate them at store time, cloud backends
 * with native transform support resolve them at URL time.
 *
 * Each variant may be materialised in one or more output formats (e.g. AVIF,
 * WebP). When `$formats` is empty the source extension is preserved (legacy
 * behaviour). When multiple formats are declared, the backend generates one
 * sibling object per format and consumers can pick via `<picture>` markup.
 */
final class Variant
{
    /**
     * @param array<int, string> $formats Output extensions without leading dot, e.g. ['avif', 'webp'].
     *
     * @mago-expect lint:excessive-parameter-list Published value-object constructor, called with named arguments.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly int $height,
        public readonly VariantFit $fit = VariantFit::Cover,
        public readonly array $formats = [],
        public readonly ?int $quality = null,
    ) {}

    /**
     * Formats to materialise, always including the source extension.
     *
     * A null entry means "use the source's extension". It is present in every
     * case — declared formats are the modern <source> candidates, but the
     * <img> fallback resolves against the source format, so a variant that
     * declares ['avif'] must still materialise the source sibling or the
     * fallback 404s. Declared formats come first so callers taking the first
     * entry as representative keep getting a modern format.
     *
     * @return array<int, ?string>
     */
    public function targetFormats(): array
    {
        return [] === $this->formats ? [null] : [...$this->formats, null];
    }
}
