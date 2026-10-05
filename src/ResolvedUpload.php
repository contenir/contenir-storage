<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * The canonical, guarded result of resolving an UploadInput: a clean slug name
 * carrying an extension derived from the file's *detected* type, the detected
 * (normalised) MIME, and — for raster images — the dimensions read while
 * detecting. Adapters store under $name and persist $mime/$image rather than
 * re-deriving any of it from the client-supplied upload.
 */
final class ResolvedUpload
{
    public function __construct(
        public readonly string $name,
        public readonly string $mime,
        public readonly ?ImageMeta $image = null,
    ) {}
}
