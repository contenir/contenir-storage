<?php

declare(strict_types=1);

namespace Contenir\Storage\Exception;

use function sprintf;

/**
 * Raised by storage backends when a caller-supplied path is unsafe — null bytes,
 * parent-directory traversal, leading slashes, or paths that resolve outside the
 * configured storage root. Distinct from NotFoundException because the
 * problem is the request, not the absence of the file.
 */
final class InvalidPathException extends StorageException
{
    public static function forAbsolutePath(string $path): self
    {
        return new self(sprintf('Path "%s" must be relative to the storage root.', $path));
    }

    public static function forEmptyName(string $clientFilename): self
    {
        return new self(sprintf('Filename "%s" has no slug-safe characters to store under.', $clientFilename));
    }

    public static function forEscape(string $path): self
    {
        return new self(sprintf('Path "%s" resolves outside the storage root.', $path));
    }

    public static function forNullByte(string $path): self
    {
        return new self(sprintf('Path "%s" contains a null byte.', $path));
    }

    public static function forTraversal(string $path): self
    {
        return new self(sprintf('Path "%s" contains parent-directory traversal.', $path));
    }
}
