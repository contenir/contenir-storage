<?php

declare(strict_types=1);

namespace Contenir\Storage\Config;

use function array_combine;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function ltrim;
use function preg_replace;
use function rtrim;
use function str_starts_with;
use function strlen;

/**
 * Resolves which variant families a stored path owns.
 *
 * The `storage.paths` map keys variant ownership by base path. An asset stored
 * below a base path (e.g. `/asset/library/news/lg/photo.jpg` under the base
 * `/asset/library/news/lg`) inherits that path's variants by longest-prefix
 * match at a segment boundary. The `'*'` wildcard entry declares families owned
 * by every path (e.g. `'*' => ['admin-thumb']` for the CMS preview).
 *
 * One instance is the single source consulted by both generation (which variants
 * to create for a key) and render-time validation (whether a requested variant
 * is allowed for a path).
 */
final class PathVariantResolver
{
    /** Path key declaring families owned by every path. */
    public const string WILDCARD = '*';

    /** @var array<string, list<string>> Normalised base path => owned family names. */
    private readonly array $paths;

    /** @var list<string> Families owned by every path (the '*' wildcard entry). */
    private readonly array $universal;

    /**
     * @param array<array-key, list<string>> $paths Base path => family names it owns.
     *                                               The '*' key declares families
     *                                               owned by every path.
     */
    public function __construct(array $paths)
    {
        $universal = $paths[self::WILDCARD] ?? [];
        unset($paths[self::WILDCARD]);

        $bases = array_map(
            static fn(int|string $base): string => self::normalise((string) $base),
            array_keys($paths),
        );

        $this->paths     = array_combine($bases, array_values($paths));
        $this->universal = array_values(array_unique($universal));
    }

    /**
     * Map a variant name to its owning family: a compiled rung `<family>-<width>`
     * or `<family>-x<height>` strips to `<family>`; anything else (a bare family,
     * or a flat variant like `admin-thumb`) is already its own family.
     */
    public static function family(string $variant): string
    {
        return (
            preg_replace(
                pattern: '/-(?:\d+|x\d+)$/',
                replacement: '',
                subject: $variant,
            ) ?? $variant
        );
    }

    private static function normalise(string $path): string
    {
        $path = '/' . ltrim($path, characters: '/');

        return '/' === $path ? '/' : rtrim($path, characters: '/');
    }

    /**
     * Whether $path owns the family behind $variant. $variant may be a bare
     * family (`gallery`), a compiled rung (`gallery-480`), or a universal
     * variant (`admin-thumb`).
     */
    public function allows(string $path, string $variant): bool
    {
        return in_array(self::family($variant), $this->familiesFor($path), strict: true);
    }

    /**
     * Family names owned by $path: the longest base entry that matches at a
     * segment boundary, unioned with the universal set. No match → universal only.
     *
     * @return list<string>
     */
    public function familiesFor(string $path): array
    {
        $path = self::normalise($path);

        $owned      = [];
        $bestLength = -1;
        foreach ($this->paths as $base => $families) {
            $prefix = '/' === $base ? '/' : "{$base}/";
            if ($path !== $base && ! str_starts_with($path, $prefix) || strlen($base) <= $bestLength) {
                continue;
            }

            $owned      = $families;
            $bestLength = strlen($base);
        }

        return array_values(array_unique([...$owned, ...$this->universal]));
    }

    /**
     * Whether any ownership is declared at all (a concrete path or the '*'
     * wildcard). When false the map is unconfigured, so callers should treat
     * every variant as permitted rather than enforce an empty map.
     */
    public function isConfigured(): bool
    {
        return [] !== $this->paths || [] !== $this->universal;
    }
}
