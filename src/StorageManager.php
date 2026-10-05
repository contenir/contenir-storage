<?php

declare(strict_types=1);

namespace Contenir\Storage;

use InvalidArgumentException;

use function array_key_exists;
use function array_keys;
use function sprintf;

/**
 * Registry mapping profile names (e.g. "local", "r2-cdn") to backend
 * instances. Field XML config declares a profile name; controllers and view
 * helpers resolve to a backend through this manager.
 *
 * The first registration for a given profile wins; re-registering the same
 * profile name throws so accidental shadowing fails loudly.
 */
final class StorageManager
{
    /**
     * Backend key for the implicit local backend when none is declared.
     */
    public const string DEFAULT_PROFILE = 'local';

    /** @var array<string, StorageInterface> */
    private array $backends = [];

    /** Key of the primary backend: holds originals + variants that don't pin their own. */
    private ?string $primary = null;

    /** @throws InvalidArgumentException If $profile is not registered. */
    public function get(string $profile): StorageInterface
    {
        return (
            $this->backends[$profile]
                ?? throw new InvalidArgumentException(sprintf('Unknown storage profile "%s".', $profile))
        );
    }

    public function has(string $profile): bool
    {
        return array_key_exists($profile, $this->backends);
    }

    /** @throws InvalidArgumentException If no backend is registered. */
    public function primary(): StorageInterface
    {
        return $this->get($this->primaryKey());
    }

    /** @throws InvalidArgumentException If no backend is registered. */
    public function primaryKey(): string
    {
        return (
            $this->primary
                ?? throw new InvalidArgumentException('No storage backend registered.')
        );
    }

    /** @return list<string> */
    public function profiles(): array
    {
        return array_keys($this->backends);
    }

    /**
     * @mago-expect lint:no-boolean-flag-parameter Published signature; the flag marks the primary backend.
     */
    public function register(string $profile, StorageInterface $backend, bool $isPrimary = false): void
    {
        if ('' === $profile) {
            throw new InvalidArgumentException('Storage profile name cannot be empty.');
        }
        if (array_key_exists($profile, $this->backends)) {
            throw new InvalidArgumentException(sprintf('Storage profile "%s" is already registered.', $profile));
        }
        $this->backends[$profile] = $backend;

        if ($isPrimary) {
            $this->primary = $profile;
        }
        // Fall back to the first registered backend if none is explicitly primary.
        $this->primary ??= $profile;
    }
}
