<?php

declare(strict_types=1);

namespace Contenir\Storage\Internal;

use function restore_error_handler;
use function set_error_handler;

/**
 * Runs filesystem and image calls whose failure is reported through their
 * return value, discarding the warning PHP raises alongside it. Replaces the
 * `@` operator with an error handler scoped to the one call.
 *
 * @internal
 */
final class Warnings
{
    /**
     * @template T
     *
     * @param callable(mixed...): T $operation
     *
     * @return T
     */
    public static function suppress(callable $operation, mixed ...$arguments): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation(...$arguments);
        } finally {
            restore_error_handler();
        }
    }
}
