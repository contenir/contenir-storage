<?php

declare(strict_types=1);

namespace Contenir\Storage;

use InvalidArgumentException;

/**
 * Default implementation of {@see StorageInterface::thumbnailUrl()} as a
 * thin wrapper over `url($path, StorageInterface::THUMBNAIL_VARIANT)`. Mixed
 * into each adapter so the convenience is uniformly available without each
 * adapter having to repeat the same try/catch.
 *
 * Adapters with custom thumbnail resolution (e.g. URL transforms instead of
 * materialised siblings) can simply not use the trait and implement the
 * method directly.
 *
 * @api
 *
 * @mago-expect lint:trait-name Published name used by consumer adapters; renaming it is a BC break left for 3.0.
 */
trait Thumbnail
{
    /**
     * @throws InvalidArgumentException When $variant is not a registered variant.
     */
    abstract public function url(string $path, ?string $variant = null): ?string;

    final public function thumbnailUrl(string $path): ?string
    {
        try {
            return $this->url($path, StorageInterface::THUMBNAIL_VARIANT);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
