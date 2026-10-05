<?php

declare(strict_types=1);

namespace Contenir\Storage\Config;

use Aws\S3\S3Client;
use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\Image\ImageResizerInterface;
use Contenir\Storage\StorageManager;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_scalar;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Builds a StorageManager from the flat `storage` config.
 *
 * Expected shape — three sibling keys, nothing else:
 *
 *   [
 *     // WHERE bytes live. A `local` backend (rooted at the web root) is ALWAYS
 *     // pre-wired; declare extra backends here. Primary = the one flagged
 *     // 'default' => true, else `local`. Declaring `r2` alone leaves `local`
 *     // primary; add 'default' => true to promote it.
 *     'backend' => [
 *       'r2' => ['type' => 's3', 'default' => true, 'bucket' => '…', 'endpoint' => '…', 'key' => '…', 'secret' => '…'],
 *       // 'auto_generate' => true on an s3 backend skips variant generation at
 *       // upload; an edge worker in front of the bucket materialises variants
 *       // on demand through OnDemandVariantGeneratorInterface instead.
 *       // 'local' => ['type' => 'local', 'root_path' => '/abs/override'],  // only to override the pre-wired root
 *     ],
 *     // WHAT transforms exist. Flat; each MAY pin a 'backend' (default = primary).
 *     'variants' => [
 *       'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
 *       'gallery'     => ['dimensions' => ['320x', '640x'], 'fit' => 'contain'],
 *     ],
 *     // WHICH variants a path owns — keyed by path; '*' is the universal wildcard.
 *     // Consumed via resolverFromArray(); not needed to build the manager.
 *     'paths' => ['*' => ['variants' => ['admin-thumb']], '/asset/library/news/lg' => ['variants' => ['gallery']]],
 *   ]
 *
 * Each backend carries a VariantRegistry of the variants assigned to it (a
 * variant's own `backend`, else the primary). The primary backend holds
 * originals and any variant that doesn't pin its own.
 *
 * @mago-expect lint:too-many-methods One parser per config block; splitting into per-backend factories is a follow-up.
 * @mago-expect lint:cyclomatic-complexity One parser per config block; splitting into per-backend factories is a follow-up.
 * @mago-expect lint:kan-defect One parser per config block; splitting into per-backend factories is a follow-up.
 */
final class StorageConfig
{
    /**
     * Build a manager with a single implicit local backend at $rootPath. Used
     * when no storage config is supplied so consumers keep working.
     */
    public static function default(ImageResizerInterface $resizer, string $rootPath): StorageManager
    {
        return self::fromArray(null, $resizer, $rootPath);
    }

    /**
     * @param array<array-key, mixed>|null $config The `storage` config array.
     *
     * @throws InvalidArgumentException If a backend or variant is malformed.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    public static function fromArray(
        ?array $config,
        ImageResizerInterface $resizer,
        string $defaultRootPath,
    ): StorageManager {
        $backends  = self::backends($config);
        $primary   = self::primaryBackendKey($backends);
        $byBackend = self::variantsByBackend(self::section($config, 'variants'), $primary, $backends);
        /**
         * Ownership is consulted at generation time so an upload only
         * materialises the families its path owns.
         */
        $paths = self::resolverFromArray($config);

        $manager = new StorageManager();
        foreach ($backends as $name => $backend) {
            $name = (string) $name;
            if (! is_array($backend)) {
                throw new InvalidArgumentException(sprintf('Backend "%s" must be an array.', $name));
            }

            $variants = new VariantRegistry(...$byBackend[$name] ?? []);
            $type     = (string) ($backend['type'] ?? 'local');
            $instance = match ($type) {
                'local'             => self::buildLocal($backend, $variants, $resizer, $defaultRootPath, $paths),
                's3'                => self::buildS3($backend, $variants, $resizer, $paths),
                'cloudflare-images' => self::buildCloudflareImages($backend, $variants, $resizer),
                default             => throw new InvalidArgumentException(sprintf(
                    'Backend "%s" has unknown type "%s" (expected: local, s3, cloudflare-images).',
                    $name,
                    $type,
                )),
            };

            $manager->register($name, $instance, isPrimary: $name === $primary);
        }

        return $manager;
    }

    /**
     * The primary backend's raw config block (the `'default' => true` backend,
     * else the always-present `local`). Lets front-end factories read backend
     * settings — `public_base_url`, `generate_secret`, `root_path`, `type` — from
     * the one place they live, instead of a separate top-level block.
     *
     * @param array<array-key, mixed>|null $config The `storage` config array.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If more than one backend is flagged as the default.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    public static function primaryBackendConfig(?array $config): array
    {
        $backends = self::backends($config);
        $primary  = $backends[self::primaryBackendKey($backends)] ?? null;

        return is_array($primary) ? $primary : [];
    }

    /**
     * Build the path → families resolver from the `storage.paths` map. One
     * canonical construction point reused by generation (worker) and render
     * (front-end). Each entry is `<path> => ['variants' => [...]]`; the '*' key
     * declares families owned by every path.
     *
     * @param array<array-key, mixed>|null $config The `storage` config array.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    public static function resolverFromArray(?array $config): PathVariantResolver
    {
        $map = [];
        foreach (self::section($config, 'paths') as $base => $entry) {
            $map[$base] = self::pathVariants($entry);
        }

        return new PathVariantResolver($map);
    }

    /**
     * The declared variant names assigned to a backend (a variant's own
     * `backend`, else the primary). Lets the CMS asset-index UI/worker list a
     * backend's variants from the config without building a StorageManager.
     *
     * @param array<array-key, mixed>|null $config The `storage` config array.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException If more than one backend is flagged as the default.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    public static function variantNamesForBackend(?array $config, string $backend): array
    {
        $primary = self::primaryBackendKey(self::backends($config));

        $names = [];
        foreach (self::section($config, 'variants') as $name => $spec) {
            if (! is_array($spec) || (string) ($spec['backend'] ?? $primary) !== $backend) {
                continue;
            }

            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * The declared backends plus the always-present `local` one. 'local' is
     * pre-wired (rooted at the web root) unless the config declares its own
     * 'local' backend, e.g. to override root_path.
     *
     * @param array<array-key, mixed>|null $config
     *
     * @return array<array-key, mixed>
     */
    private static function backends(?array $config): array
    {
        $backends = self::section($config, 'backend');
        if (! array_key_exists(StorageManager::DEFAULT_PROFILE, $backends)) {
            $backends[StorageManager::DEFAULT_PROFILE] = ['type' => 'local'];
        }

        return $backends;
    }

    /**
     * @param array<array-key, mixed> $backend
     *
     * @throws InvalidArgumentException If a required key is missing.
     */
    private static function buildCloudflareImages(
        array $backend,
        VariantRegistry $variants,
        ImageResizerInterface $resizer,
    ): CloudflareImages {
        /**
         * The wrapped object store doesn't pre-generate variants — they resolve
         * through Cloudflare URL transforms at url() time.
         */
        $inner = new S3(
            fs: self::buildFlysystem($backend),
            publicUrlBase: self::publicUrlBase($backend),
            variants: new VariantRegistry(),
            resizer: $resizer,
        );

        return new CloudflareImages(
            objectStore: $inner,
            deliveryBaseUrl: self::requireString($backend, 'deliveryBaseUrl'),
            variants: $variants,
        );
    }

    /**
     * @param array<array-key, mixed> $backend
     *
     * @throws InvalidArgumentException If a required key is missing.
     */
    private static function buildFlysystem(array $backend): Filesystem
    {
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => (string) ($backend['region'] ?? 'auto'),
            'endpoint'                => self::requireString($backend, 'endpoint'),
            'credentials'             => [
                'key'    => self::requireString($backend, 'key'),
                'secret' => self::requireString($backend, 'secret'),
            ],
            'use_path_style_endpoint' => self::flag($backend, 'usePathStyleEndpoint'),
        ]);

        return new Filesystem(new AwsS3V3Adapter($client, self::requireString($backend, 'bucket')));
    }

    /**
     * Local-filesystem backend. An explicit `root_path` on the backend overrides
     * the caller's default root (the resolved web root); otherwise the default is
     * used, so a backend-less local site needs no `root_path` at all.
     *
     * @param array<array-key, mixed> $backend
     */
    private static function buildLocal(
        array $backend,
        VariantRegistry $variants,
        ImageResizerInterface $resizer,
        string $defaultRootPath,
        PathVariantResolver $paths,
    ): LocalFilesystem {
        $root = (string) ($backend['root_path'] ?? '');

        return new LocalFilesystem(
            rootPath: '' === $root ? $defaultRootPath : $root,
            publicPath: (string) ($backend['public_path'] ?? ''),
            variants: $variants,
            resizer: $resizer,
            paths: $paths,
        );
    }

    /**
     * @param array<array-key, mixed> $backend
     *
     * @throws InvalidArgumentException If a required key is missing.
     */
    private static function buildS3(
        array $backend,
        VariantRegistry $variants,
        ImageResizerInterface $resizer,
        PathVariantResolver $paths,
    ): S3 {
        return new S3(
            fs: self::buildFlysystem($backend),
            publicUrlBase: self::publicUrlBase($backend),
            variants: $variants,
            resizer: $resizer,
            paths: $paths,
            autoGenerate: self::flag($backend, 'auto_generate'),
        );
    }

    /**
     * Expand one variant declaration: an art-directed `dimensions` ladder
     * compiles to one Variant per rung (`<name>-<width>`); otherwise it is a
     * single flat variant declared with explicit width/height.
     *
     * @param array<array-key, mixed> $spec
     *
     * @return list<Variant>
     *
     * @throws InvalidArgumentException If the declaration is malformed.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    private static function buildVariant(string $name, array $spec): array
    {
        if (null !== ($spec['dimensions'] ?? null)) {
            /** @var array<string, mixed> $spec */
            return VariantProfile::fromArray($name, $spec)->variants;
        }

        $formats = array_map(
            static fn(string $format): string => strtolower(trim($format, characters: " \t\n\r\0\x0B.")),
            self::names($spec['formats'] ?? null),
        );
        $quality = $spec['quality'] ?? null;

        return [
            new Variant(
                name: $name,
                width: (int) self::requireString($spec, 'width'),
                height: (int) self::requireString($spec, 'height'),
                fit: self::parseFit((string) ($spec['fit'] ?? 'contain')),
                formats: $formats,
                quality: null === $quality ? null : (int) $quality,
            ),
        ];
    }

    /**
     * A boolean backend option, read with PHP's usual truthiness so `1` and
     * `"1"` keep enabling it.
     *
     * @param array<array-key, mixed> $backend
     *
     * @mago-expect analysis:mixed-operand Storage config is untyped input; truthiness is the documented contract.
     */
    private static function flag(array $backend, string $key): bool
    {
        return (bool) ($backend[$key] ?? false);
    }

    /**
     * The scalar entries of a config list, as strings. Anything else yields
     * an empty list.
     *
     * @return list<string>
     */
    private static function names(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_map(
            static fn(bool|float|int|string $name): string => (string) $name,
            array_filter($list, is_scalar(...)),
        ));
    }

    /**
     * @throws InvalidArgumentException If $fit is not a known fit.
     */
    private static function parseFit(string $fit): VariantFit
    {
        return match (strtolower($fit)) {
            'cover'   => VariantFit::Cover,
            'contain' => VariantFit::Contain,
            'fill'    => VariantFit::Fill,
            default   => throw new InvalidArgumentException(sprintf(
                'Unknown variant fit "%s" (expected: cover, contain, fill).',
                $fit,
            )),
        };
    }

    /**
     * The variant names a `storage.paths` entry declares.
     *
     * @return list<string>
     */
    private static function pathVariants(mixed $entry): array
    {
        return is_array($entry) ? self::names($entry['variants'] ?? null) : [];
    }

    /**
     * Primary = the backend flagged `'default' => true`; if none is flagged, the
     * always-present `local` backend. More than one flagged is a config error.
     *
     * @param array<array-key, mixed> $backends
     *
     * @throws InvalidArgumentException If more than one backend is flagged.
     */
    private static function primaryBackendKey(array $backends): string
    {
        $flagged = array_keys(array_filter(
            $backends,
            static fn(mixed $backend): bool => is_array($backend) && true === ($backend['default'] ?? false),
        ));

        if (count($flagged) > 1) {
            throw new InvalidArgumentException(sprintf(
                'At most one backend may declare \'default\' => true (found %d).',
                count($flagged),
            ));
        }

        return (string) ($flagged[0] ?? StorageManager::DEFAULT_PROFILE);
    }

    /**
     * The public base URL of an object-store backend. `publicUrl` is canonical;
     * `public_base_url` (the key the Laminas asset bridge reads) is accepted as
     * an alias so a site only has to declare one of them.
     *
     * @param array<array-key, mixed> $backend
     *
     * @throws InvalidArgumentException If neither key is set.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    private static function publicUrlBase(array $backend): string
    {
        $value = $backend['publicUrl'] ?? $backend['public_base_url'] ?? null;
        if (null === $value || '' === $value) {
            throw new InvalidArgumentException('Missing required config key "publicUrl" (or "public_base_url").');
        }

        return (string) $value;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidArgumentException If $key is missing or empty.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    private static function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (null === $value || '' === $value) {
            throw new InvalidArgumentException(sprintf('Missing required config key "%s".', $key));
        }

        return (string) $value;
    }

    /**
     * One block of the `storage` config, or an empty array when it is absent
     * or not an array.
     *
     * @param array<array-key, mixed>|null $config
     *
     * @return array<array-key, mixed>
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    private static function section(?array $config, string $key): array
    {
        $section = $config[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * Expand every variant declaration and group the resulting Variant objects
     * by their target backend (a variant's own `backend`, else the primary).
     *
     * @param array<array-key, mixed> $variants
     * @param array<array-key, mixed> $backends
     *
     * @return array<string, list<Variant>>
     *
     * @throws InvalidArgumentException If a variant is malformed or targets an unknown backend.
     *
     * @mago-expect analysis:mixed-assignment Storage config is untyped input; it is validated here.
     */
    private static function variantsByBackend(array $variants, string $primary, array $backends): array
    {
        $byBackend = [];
        foreach ($variants as $name => $spec) {
            if (! is_array($spec)) {
                throw new InvalidArgumentException(sprintf('Variant "%s" must be an array.', $name));
            }

            $target = (string) ($spec['backend'] ?? $primary);
            if (! array_key_exists($target, $backends)) {
                throw new InvalidArgumentException(sprintf(
                    'Variant "%s" targets unknown backend "%s".',
                    $name,
                    $target,
                ));
            }

            foreach (self::buildVariant((string) $name, $spec) as $variant) {
                $byBackend[$target][] = $variant;
            }
        }

        return $byBackend;
    }
}
