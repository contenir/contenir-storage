<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\UnsupportedTypeException;

/**
 * Turns an UploadInput into a canonical, guarded ResolvedUpload.
 *
 * This is the single place where a stored object's name and extension are
 * decided. Adapters MUST delegate to it rather than deriving names themselves,
 * so every backend produces clean, extension-bearing, detected-type-backed
 * keys uniformly. The resolver fails loudly: it never falls back to
 * client-supplied data and never yields an ambiguous name.
 *
 * @api
 */
interface UploadResolverInterface
{
    /**
     * @throws UnsupportedTypeException When the type cannot be detected from the
     *         source bytes, or the detected type is not storable.
     * @throws InvalidPathException When the client filename has no slug-safe
     *         characters to store under.
     */
    public function resolve(UploadInput $upload): ResolvedUpload;
}
