<?php

declare(strict_types=1);

namespace Contenir\Storage\Exception;

use RuntimeException;

/**
 * Base of every exception this package throws, so callers can catch them all
 * with one type. Abstract: it is only ever thrown as one of its subclasses.
 *
 * @api
 *
 * @mago-expect lint:class-name Keeps the 0.x name so existing `catch (StorageException)` blocks still work.
 */
abstract class StorageException extends RuntimeException {}
