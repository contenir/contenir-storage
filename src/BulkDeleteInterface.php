<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Optional capability: remove an explicit list of keys, and nothing else.
 *
 * {@see StorageInterface::delete()} deletes an *asset* — the object plus every
 * variant sibling it could own. That is right when removing an asset and badly
 * wrong for a vetted key list, where the caller has already decided precisely
 * what goes: each key costs a delete call per registered variant and format,
 * so a large cleanup turns into millions of requests against objects that were
 * never there.
 *
 * Implementations delete exactly the keys given — no variant sweep, no
 * expansion — and batch where the backend supports it.
 *
 * @api
 */
interface BulkDeleteInterface
{
    /**
     * Delete $keys, returning the ones that could not be removed.
     *
     * Missing keys are not failures — deletion is idempotent, and a key already
     * gone satisfies the request. The result maps each failed key to the reason
     * the backend gave, so a caller can report or retry precisely.
     *
     * @param list<string> $keys
     *
     * @return array<string, string> Failed key => reason. Empty when all succeeded.
     */
    public function deleteMany(array $keys): array;
}
