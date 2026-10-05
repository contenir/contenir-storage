<?php

declare(strict_types=1);

namespace Contenir\Storage\Exception;

use function sprintf;

final class NotFoundException extends StorageException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('Asset "%s" does not exist.', $path));
    }
}
