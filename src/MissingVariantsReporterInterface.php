<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\NotFoundException;

/**
 * Optional capability: report what a backfill would produce, without producing it.
 *
 * The read-only counterpart to {@see StorageInterface::regenerateMissingVariants()},
 * for tooling that needs to audit before it writes. Implemented separately from
 * StorageInterface so backends outside this package are not forced to grow a
 * method they have no use for.
 *
 * @api
 */
interface MissingVariantsReporterInterface
{
    /**
     * The variant keys $path is entitled to but has not materialised.
     *
     * Applies the same ownership and format rules as generation, but performs
     * no writes and does not download the source.
     *
     * @return list<string>
     *
     * @throws NotFoundException    If $path itself does not exist.
     * @throws InvalidPathException If $path is unsafe (null byte, traversal, escapes the root).
     */
    public function missingVariants(string $path): array;
}
