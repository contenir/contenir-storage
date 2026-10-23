<?php

declare(strict_types=1);

namespace Contenir\Storage\Internal;

use function fread;
use function strlen;

/**
 * Reads a stream forward by byte counts, noticing when it runs out.
 *
 * Skipping reads rather than seeks, so a stream that cannot seek works too.
 *
 * @internal
 */
final readonly class StreamReader
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
    ) {}

    /** Up to $length bytes, fewer only at the end of the stream. */
    public function read(int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($this->stream, $length - strlen($bytes));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** Exactly $length bytes, or null when the stream ends first. */
    public function readExactly(int $length): ?string
    {
        $bytes = $this->read($length);

        return strlen($bytes) === $length ? $bytes : null;
    }

    /** Skip $length bytes; false when the stream ends first. */
    public function skip(int $length): bool
    {
        return 0 === $length || null !== $this->readExactly($length);
    }
}
