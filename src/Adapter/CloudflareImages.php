<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\MissingVariantsReporterInterface;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use Override;

use function ltrim;
use function rtrim;
use function sprintf;

/**
 * Wraps another storage backend and serves variants through Cloudflare's
 * URL-based image transforms instead of materialising sibling objects.
 *
 * For an asset stored at `products/hero.jpg`, requesting variant
 * `'admin-thumb'` (180×180 contain) produces:
 *
 *   https://cdn.example.com/cdn-cgi/image/width=180,height=180,fit=contain/products/hero.jpg
 *
 * The wrapped backend (typically an S3 instance pointed at R2) does *not*
 * pre-generate variants — construct it with an empty VariantRegistry. This
 * class owns the variant catalogue and resolves it at URL time. No second
 * upload, no Cloudflare Images API account required; just enable image
 * resizing on the Cloudflare zone fronting your R2 bucket.
 *
 * Every non-URL method (store/list/exists/delete/rename/imageMeta) delegates
 * to the wrapped backend unchanged.
 *
 * @mago-expect lint:too-many-methods Implements the full StorageInterface by delegation; it cannot have fewer methods.
 */
final class CloudflareImages implements StorageInterface, MissingVariantsReporterInterface
{
    use Thumbnail;

    public function __construct(
        private readonly StorageInterface $objectStore,
        private readonly string $deliveryBaseUrl,
        private readonly VariantRegistry $variants,
    ) {}

    #[Override]
    public function delete(string $path): void
    {
        $this->objectStore->delete($path);
    }

    #[Override]
    public function exists(string $path): bool
    {
        return $this->objectStore->exists($path);
    }

    #[Override]
    public function imageMeta(string $path): ImageMeta
    {
        return $this->objectStore->imageMeta($path);
    }

    #[Override]
    public function list(string $path, ?ListOptions $options = null): iterable
    {
        return $this->objectStore->list($path, $options);
    }

    #[Override]
    public function missingVariants(string $path): array
    {
        /**
         * URL-transform backend: variants never pre-materialise, so nothing is
         * outstanding. The wrapped store need not report missing variants
         * itself, so an unknown path is rejected here.
         */
        if (! $this->objectStore->exists($path)) {
            throw NotFoundException::forPath($path);
        }

        return [];
    }

    #[Override]
    public function regenerateMissingVariants(string $path): array
    {
        /**
         * Variants resolve via URL transforms at request time — there's
         * never anything to pre-materialise. The wrapped object-store
         * holds only the original; we just delegate so an unknown path
         * still surfaces a NotFoundException through the underlying call.
         */
        if (! $this->objectStore->exists($path)) {
            return $this->objectStore->regenerateMissingVariants($path);
        }
        return [];
    }

    #[Override]
    public function rename(string $from, string $to): void
    {
        $this->objectStore->rename($from, $to);
    }

    #[Override]
    public function store(UploadInput $upload, string $directory): Entry
    {
        return $this->objectStore->store($upload, $directory);
    }

    #[Override]
    public function url(string $path, ?string $variant = null): ?string
    {
        if (null !== $variant && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        if (null === $variant) {
            return $this->objectStore->url($path);
        }

        if (! $this->objectStore->exists($path)) {
            return null;
        }

        return $this->buildTransformUrl($path, $this->variants->get($variant));
    }

    #[Override]
    public function urlsForKey(string $path): array
    {
        $urls = $this->objectStore->urlsForKey($path);
        foreach ($this->variants->all() as $variant) {
            $urls[] = $this->buildTransformUrl($path, $variant);
        }
        return $urls;
    }

    #[Override]
    public function variantUrls(string $path, string $variantName): array
    {
        // Cloudflare resolves the resized image from the transform URL at
        // request time, so there is exactly one URL per variant and no object
        // to exist-check — build it deterministically from the key.
        return ['source' => $this->buildTransformUrl($path, $this->variants->get($variantName))];
    }

    private function buildTransformUrl(string $path, Variant $variant): string
    {
        $params = sprintf(
            'width=%d,height=%d,fit=%s',
            $variant->width,
            $variant->height,
            $this->fitParam($variant->fit),
        );

        return (
            rtrim($this->deliveryBaseUrl, characters: '/')
                . '/cdn-cgi/image/'
                . $params
                . '/'
                . ltrim($path, characters: '/')
        );
    }

    private function fitParam(VariantFit $fit): string
    {
        return match ($fit) {
            VariantFit::Cover   => 'cover',
            VariantFit::Contain => 'contain',
            VariantFit::Fill    => 'crop',
        };
    }
}
