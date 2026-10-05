<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Capability for backends that can materialise an individual variant on demand
 * from its sibling key, rather than only at upload or via a full backfill.
 *
 * Implemented by sibling-object backends (e.g. {@see Adapter\S3}) so an
 * edge/origin request for a not-yet-generated variant can trigger a single
 * resize-and-store. Backends that resolve variants through a URL transform, or
 * that have no sibling-object scheme, simply do not implement this interface.
 *
 * @api
 */
interface OnDemandVariantGeneratorInterface
{
    /**
     * Materialise the single variant identified by $variantKey
     * (`<base>__<variant>.<format>`) and return its public URL.
     *
     * Idempotent: returns the existing URL when the variant is already present.
     * Returns null when the variant name or requested format is not registered,
     * or when the source original for the key cannot be located.
     *
     * @throws Exception\WriteException If the variant cannot be generated or written.
     */
    public function generateForKey(string $variantKey): ?string;
}
