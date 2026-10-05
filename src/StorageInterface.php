<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\UnsupportedTypeException;
use Contenir\Storage\Exception\WriteException;
use InvalidArgumentException;

/**
 * Storage backend for CMS assets (images, files, and directories).
 *
 * Backends own the full lifecycle of an asset including any derived variants:
 * a local-filesystem backend pre-generates thumbnails on store(), a Cloudflare
 * Images-backed R2 backend resolves variants at url() time. Callers should not
 * assume a particular materialisation strategy.
 *
 * Paths are always relative to the storage root, use forward slashes, and do
 * not begin with a slash. Backends are responsible for rejecting traversal
 * attempts.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods The storage contract is published API; splitting it is a 3.0 decision.
 */
interface StorageInterface
{
    /**
     * Canonical variant name for the small-square admin preview. Profiles that
     * want admin-side previews register a variant under this name; CMS UIs ask
     * for it via {@see thumbnailUrl()} without hard-coding the string.
     */
    public const string THUMBNAIL_VARIANT = 'admin-thumb';

    /**
     * Delete an asset and every variant sibling it could own.
     *
     * @throws NotFoundException    If $path does not exist.
     * @throws WriteException       If $path cannot be deleted.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function delete(string $path): void;

    /**
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function exists(string $path): bool;

    /**
     * @throws NotFoundException    If $path does not exist or is not an image.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function imageMeta(string $path): ImageMeta;

    /**
     * Browse a directory.
     *
     * @return iterable<Entry>
     *
     * @throws NotFoundException    If $path does not exist.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function list(string $path, ?ListOptions $options = null): iterable;

    /**
     * Materialise registered variants that are missing on the backend for
     * the given asset. Idempotent — variants that already exist are left
     * alone. Returns the list of variant keys (sibling object paths) that
     * were newly generated; an empty list means everything was already in
     * place.
     *
     * Intended for backfill tooling — e.g. an "Asset Indexes" admin
     * utility — that needs to bring storage in sync with the variant
     * registry after a config change adds new variants, a package upgrade
     * extends an existing variant's format set, or an upload arrived
     * through a code path that bypassed store().
     *
     * Backends that resolve variants on demand (e.g. URL-transform
     * services) return an empty list since there's nothing to
     * pre-materialise.
     *
     * @return list<string>
     *
     * @throws NotFoundException    If $path itself does not exist.
     * @throws WriteException       If a variant cannot be generated/written.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function regenerateMissingVariants(string $path): array;

    /**
     * Move an asset, and the variant siblings it has, to $to.
     *
     * @throws NotFoundException    If $from does not exist.
     * @throws WriteException       If $to already exists or the move fails.
     * @throws InvalidPathException If either path is unsafe.
     */
    public function rename(string $from, string $to): void;

    /**
     * Persist an upload at $directory under its (sanitised) client filename.
     *
     * Backends generate any registered variants as part of this call where the
     * underlying storage requires eager materialisation.
     *
     * @throws WriteException            If the file cannot be written.
     * @throws UnsupportedTypeException When the upload's type cannot be detected or is not storable.
     * @throws InvalidPathException     When the client filename has no slug-safe characters, or the
     *                                  directory is unsafe.
     */
    public function store(UploadInput $upload, string $directory): Entry;

    /**
     * Convenience for `url($path, self::THUMBNAIL_VARIANT)` that swallows the
     * "unknown variant" case so CMS UIs can call it on any profile without
     * caring whether the thumbnail variant is registered.
     *
     * Returns null when the profile doesn't declare the thumbnail variant,
     * when $path doesn't exist, or when the variant hasn't been materialised
     * for the asset. Callers fall back to whatever URL they have on hand.
     *
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function thumbnailUrl(string $path): ?string;

    /**
     * Public URL for an asset, optionally for a named variant.
     *
     * Returns null when $path does not exist or when the requested variant has
     * not been materialised for this asset. Passing an unknown variant name is
     * a programming error and raises InvalidArgumentException.
     *
     * @throws InvalidArgumentException If $variant is not a declared variant.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function url(string $path, ?string $variant = null): ?string;

    /**
     * Public URLs for the original plus every declared variant.
     *
     * Used by CDN cache invalidation: the caller needs every URL that an
     * upstream cache might be holding for this asset, regardless of whether
     * each variant has actually been materialised. URLs are returned without
     * existence checks — the consumer (typically a purge call) can safely
     * include URLs that were never cached.
     *
     * @return list<string>
     *
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function urlsForKey(string $path): array;

    /**
     * Deterministic public URLs for a single named variant, keyed by output
     * format (e.g. `['avif' => '…', 'webp' => '…']`). When the variant declares
     * no formats the only key is `'source'`.
     *
     * URLs are built purely from the key plus the variant config — **no
     * existence checks, no network round-trips**. This is the trusted read
     * path: a caller that holds a stored key (e.g. from a database row) gets
     * its variant URLs without paying a HEAD per asset. The asset is assumed to
     * exist; if it doesn't, the URL simply 404s at fetch time.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException If $variantName is not a declared variant.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function variantUrls(string $path, string $variantName): array;
}
