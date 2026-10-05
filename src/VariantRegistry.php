<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Config\PathVariantResolver;
use InvalidArgumentException;

use function array_filter;
use function array_key_exists;
use function array_values;
use function sprintf;

/**
 * Application-level catalogue of named variants known to storage backends.
 *
 * The registry is constructed once at boot and injected into backends so they
 * know which variants to materialise (local) or expose (cloud).
 */
final class VariantRegistry
{
    /** @var array<string, Variant> */
    private readonly array $byName;

    public function __construct(Variant ...$variants)
    {
        $byName = [];
        foreach ($variants as $variant) {
            $byName[$variant->name] = $variant;
        }
        $this->byName = $byName;
    }

    /** @return list<Variant> */
    public function all(): array
    {
        return array_values($this->byName);
    }

    /**
     * The variants $path is entitled to, per the `storage.paths` map.
     *
     * Generation consults this so an upload only materialises the families its
     * path actually owns; cleanup (delete/rename) deliberately does not, since
     * it must account for siblings written before an ownership change.
     *
     * An absent or unconfigured resolver means no ownership is declared, so
     * every variant applies — the same permissive default the render-time
     * guard uses.
     *
     * @return list<Variant>
     */
    public function allowedFor(?PathVariantResolver $paths, string $path): array
    {
        if (null === $paths || ! $paths->isConfigured()) {
            return $this->all();
        }

        return array_values(array_filter(
            $this->all(),
            static fn(Variant $variant): bool => $paths->allows($path, $variant->name),
        ));
    }

    /** @throws InvalidArgumentException If $name is not registered. */
    public function get(string $name): Variant
    {
        return (
            $this->byName[$name]
                ?? throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $name))
        );
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->byName);
    }
}
