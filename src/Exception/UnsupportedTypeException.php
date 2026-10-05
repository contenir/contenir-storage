<?php

declare(strict_types=1);

namespace Contenir\Storage\Exception;

use function sprintf;

/**
 * Raised when an upload's type cannot be detected from its bytes, or when the
 * detected type is not in the resolver's canonical allowlist. The store path
 * refuses to write an asset whose extension it cannot derive with confidence —
 * a stored key's extension must always be trustworthy, never guessed from the
 * client-supplied filename.
 */
final class UnsupportedTypeException extends StorageException
{
    public static function forMime(string $mime, string $clientFilename): self
    {
        return new self(sprintf(
            'Detected type "%s" of upload "%s" is not a supported storage type.',
            $mime,
            $clientFilename,
        ));
    }

    public static function forUndetectable(string $sourcePath): self
    {
        return new self(sprintf('Could not detect the type of upload source "%s".', $sourcePath));
    }

    public static function forUnreadable(string $sourcePath): self
    {
        return new self(sprintf('Upload source "%s" is not readable.', $sourcePath));
    }
}
