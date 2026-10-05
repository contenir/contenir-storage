<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\Config\StorageConfig;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\StorageManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class StorageConfigTest extends TestCase
{
    private StubImageResizer $resizer;

    #[Test]
    public function aBackendThatIsNotAnArrayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backend "local" must be an array.');

        $this->build(['backend' => ['local' => 'nope']]);
    }

    #[Test]
    public function artDirectedLadderExpandsToWidthNamedVariants(): void
    {
        $manager = $this->build([
            'variants' => [
                'card'        => ['fit' => 'cover', 'dimensions' => ['320x320', '480x480', '768x768']],
                'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
            ],
        ]);

        $backend = $manager->primary();

        static::assertNull($backend->url('missing.jpg', 'card-320'));
        static::assertNull($backend->url('missing.jpg', 'card-768'));
        static::assertNull($backend->url('missing.jpg', 'admin-thumb'));

        // The family key itself is not a variant — only its expanded rungs are.
        $this->expectException(InvalidArgumentException::class);
        $backend->url('missing.jpg', 'card');
    }

    #[Test]
    public function aVariantThatIsNotAnArrayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant "thumb" must be an array.');

        $this->build(['variants' => ['thumb' => 'nope']]);
    }

    #[Test]
    public function cloudflareImagesBackendBuilds(): void
    {
        $manager = $this->build([
            'backend' => [
                'cf' => $this->s3Stub([
                    'type'            => 'cloudflare-images',
                    'deliveryBaseUrl' => 'https://cdn.example.com',
                ]),
            ],
        ]);

        static::assertInstanceOf(CloudflareImages::class, $manager->get('cf'));
    }

    #[Test]
    public function declaredBackendWithoutDefaultLeavesLocalPrimary(): void
    {
        $manager = $this->build([
            'backend' => ['r2' => $this->s3Stub()],
        ]);

        // 'local' is pre-wired; without a default flag it stays primary.
        static::assertSame(['r2', 'local'], $manager->profiles());
        static::assertSame('local', $manager->primaryKey());
        static::assertInstanceOf(S3::class, $manager->get('r2'));
        static::assertInstanceOf(LocalFilesystem::class, $manager->get('local'));
    }

    #[Test]
    public function declaredLocalBackendOverridesPrewiredRoot(): void
    {
        $manager = $this->build([
            'backend' => ['local' => ['type' => 'local', 'root_path' => '/custom/root']],
        ]);

        static::assertSame('/custom/root/file.jpg', $manager->get('local')->localPath('file.jpg'));
    }

    #[Test]
    public function defaultBuildsAnImplicitLocalPrimary(): void
    {
        $manager = StorageConfig::default($this->resizer, '/var/www/public');

        static::assertSame(['local'], $manager->profiles());
        static::assertInstanceOf(LocalFilesystem::class, $manager->primary());
    }

    #[Test]
    public function defaultFlagPromotesBackendToPrimary(): void
    {
        $manager = $this->build([
            'backend' => ['r2' => $this->s3Stub() + ['default' => true]],
        ]);

        static::assertSame('r2', $manager->primaryKey());
        static::assertInstanceOf(S3::class, $manager->primary());
    }

    #[Test]
    public function flatVariantsCarryQualityFitAndNormalisedFormats(): void
    {
        $manager = $this->build([
            'variants' => [
                'hero' => [
                    'width'   => 10,
                    'height'  => 5,
                    'fit'     => 'FILL',
                    'quality' => '70',
                    'formats' => [' .AVIF ', 'webp'],
                ],
            ],
        ]);

        static::assertSame(
            [
                'avif'   => '/_variant/hero/hero.png',
                'webp'   => '/_variant/hero/hero.png',
                'source' => '/_variant/hero/hero.png',
            ],
            $manager->primary()->variantUrls('hero.png', 'hero'),
        );
    }

    #[Test]
    public function implicitLocalRootIsTheDefaultRoot(): void
    {
        $manager = StorageConfig::fromArray([], $this->resizer, '/srv/site/public');

        static::assertSame('/srv/site/public/file.jpg', $manager->primary()->localPath('file.jpg'));
    }

    #[Test]
    public function localBackendRootPathOverridesDefaultRoot(): void
    {
        $manager = $this->build([
            'backend' => ['main' => ['type' => 'local', 'root_path' => '/explicit/root']],
        ]);

        static::assertSame('/explicit/root/file.jpg', $manager->get('main')->localPath('file.jpg'));
    }

    #[Test]
    public function moreThanOneDefaultThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At most one backend');

        $this->build([
            'backend' => [
                'a'  => ['type' => 'local', 'default' => true],
                'r2' => $this->s3Stub() + ['default' => true],
            ],
        ]);
    }

    #[Test]
    public function multipleBackendsRequireExactlyOneDefault(): void
    {
        $manager = $this->build([
            'backend' => [
                'local' => ['type' => 'local'],
                'r2'    => $this->s3Stub() + ['default' => true],
            ],
        ]);

        static::assertSame(['local', 'r2'], $manager->profiles());
        static::assertSame('r2', $manager->primaryKey());
    }

    #[Test]
    public function noBackendDeclaredBuildsImplicitLocalPrimary(): void
    {
        $manager = StorageConfig::fromArray([], $this->resizer, '/var/uploads');

        static::assertSame(['local'], $manager->profiles());
        static::assertSame('local', $manager->primaryKey());
        static::assertInstanceOf(LocalFilesystem::class, $manager->primary());
    }

    #[Test]
    public function nullConfigBuildsImplicitLocal(): void
    {
        $manager = StorageConfig::fromArray(null, $this->resizer, '/var/uploads');

        static::assertSame(['local'], $manager->profiles());
        static::assertSame('local', $manager->primaryKey());
    }

    #[Test]
    public function primaryBackendConfigFallsBackToImplicitLocal(): void
    {
        $primary = StorageConfig::primaryBackendConfig(['variants' => []]);

        static::assertSame('local', $primary['type']);
    }

    #[Test]
    public function primaryBackendConfigIsEmptyWhenThePrimaryIsNotAnArray(): void
    {
        static::assertSame([], StorageConfig::primaryBackendConfig(['backend' => ['local' => 'nope']]));
    }

    /**
     * @mago-expect lint:no-literal-password Dummy credentials for an S3 client that is never called.
     */
    #[Test]
    public function primaryBackendConfigReturnsTheDefaultBackend(): void
    {
        $cfg = ['backend' => [
            'r2' => $this->s3Stub()
                + [
                    'default'         => true,
                    'public_base_url' => 'https://cdn.example.com',
                    'generate_secret' => 's3cr3t',
                ],
        ]];

        $primary = StorageConfig::primaryBackendConfig($cfg);

        static::assertSame('https://cdn.example.com', $primary['public_base_url']);
        static::assertSame('s3cr3t', $primary['generate_secret']);
    }

    #[Test]
    public function resolverFromArrayBuildsPathVariantResolver(): void
    {
        $resolver = StorageConfig::resolverFromArray([
            'paths' => [
                '*'                      => ['variants' => ['admin-thumb']],
                '/asset/library/news/lg' => ['variants' => ['gallery']],
            ],
        ]);

        static::assertSame(['gallery', 'admin-thumb'], $resolver->familiesFor('/asset/library/news/lg/x.jpg'));
        static::assertSame(['admin-thumb'], $resolver->familiesFor('/asset/library/slide/x.jpg'));
    }

    #[Test]
    public function resolverFromArrayHandlesMissingPaths(): void
    {
        $resolver = StorageConfig::resolverFromArray([]);

        static::assertSame([], $resolver->familiesFor('/anything.jpg'));
    }

    #[Test]
    public function resolverFromArrayIgnoresMalformedPathEntries(): void
    {
        $resolver = StorageConfig::resolverFromArray([
            'paths' => [
                '/news' => 'nope',
                '/blog' => ['variants' => ['card', ['nested'], 3]],
            ],
        ]);

        static::assertSame([], $resolver->familiesFor('/news/a.png'));
        static::assertSame(['card', '3'], $resolver->familiesFor('/blog/a.png'));
    }

    #[Test]
    public function s3BackendAcceptsThePublicBaseUrlAlias(): void
    {
        $stub = $this->s3Stub([
            'public_base_url'      => 'https://alias.test',
            'usePathStyleEndpoint' => 1,
            'auto_generate'        => '1',
        ]);
        unset($stub['publicUrl']);

        static::assertSame(
            ['https://alias.test/a.png'],
            $this->build(['backend' => ['r2' => $stub]])->get('r2')->urlsForKey('a.png'),
        );
    }

    #[Test]
    public function s3BackendRequiresAPublicUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required config key "publicUrl" (or "public_base_url").');

        $stub = $this->s3Stub();
        unset($stub['publicUrl']);
        $this->build(['backend' => ['r2' => $stub]]);
    }

    #[Test]
    public function throwsForCloudflareImagesMissingDeliveryUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deliveryBaseUrl');

        $this->build([
            'backend' => ['cf' => $this->s3Stub(['type' => 'cloudflare-images'])],
        ]);
    }

    /**
     * @mago-expect lint:no-literal-password Dummy credentials for an S3 client that is never called.
     */
    #[Test]
    public function throwsForS3MissingBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bucket');

        $this->build([
            'backend' => [
                'r2' => [
                    'type'      => 's3',
                    'endpoint'  => 'https://e.example.com',
                    'key'       => 'k',
                    'secret'    => 's',
                    'publicUrl' => 'https://cdn.example.com',
                ],
            ],
        ]);
    }

    #[Test]
    public function throwsForUnknownBackendType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type');

        $this->build([
            'backend' => ['weird' => ['type' => 'azure-blob']],
        ]);
    }

    #[Test]
    public function throwsForUnknownVariantFit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fit');

        $this->build([
            'variants' => ['v' => ['width' => 100, 'height' => 100, 'fit' => 'squish']],
        ]);
    }

    #[Test]
    public function variantNamesForBackendGroupsByAssignment(): void
    {
        $config = [
            'backend'  => ['r2' => $this->s3Stub() + ['default' => true]],
            'variants' => [
                'admin-thumb' => ['width' => 180, 'height' => 180],
                'gallery'     => ['dimensions' => ['320x']],
                'mark'        => ['width' => 160, 'height' => 160, 'backend' => 'local'],
            ],
        ];

        static::assertSame(['admin-thumb', 'gallery'], StorageConfig::variantNamesForBackend($config, 'r2'));
        static::assertSame(['mark'], StorageConfig::variantNamesForBackend($config, 'local'));
    }

    #[Test]
    public function variantNamesForBackendSkipsMalformedVariants(): void
    {
        static::assertSame(
            ['thumb'],
            StorageConfig::variantNamesForBackend([
                'variants' => ['thumb' => ['width' => 1, 'height' => 1], 'broken' => 'nope'],
            ], 'local'),
        );
    }

    #[Test]
    public function variantNamesForBackendUsesLocalPrimaryWhenNoDefault(): void
    {
        $config = ['variants' => ['gallery' => ['dimensions' => ['320x']]]];

        static::assertSame(['gallery'], StorageConfig::variantNamesForBackend($config, 'local'));
        static::assertSame([], StorageConfig::variantNamesForBackend($config, 'r2'));
    }

    #[Test]
    public function variantsLandOnPrimaryAndOnPinnedBackend(): void
    {
        $manager = $this->build([
            'backend'  => [
                'main' => ['type' => 'local', 'root_path' => '/a', 'default' => true],
                'side' => ['type' => 'local', 'root_path' => '/b'],
            ],
            'variants' => [
                'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
                'card'        => ['width' => 600, 'height' => 400, 'fit' => 'cover', 'backend' => 'side'],
            ],
        ]);

        $main = $manager->get('main');
        $side = $manager->get('side');

        // admin-thumb (unpinned) → primary 'main'; card (pinned) → 'side'.
        static::assertNull($main->url('x.jpg', 'admin-thumb')); // known on main, file missing
        static::assertNull($side->url('x.jpg', 'card')); // known on side, file missing

        $this->expectException(InvalidArgumentException::class);
        $main->url('x.jpg', 'card'); // not registered on main
    }

    #[Test]
    public function variantTargetingUnknownBackendThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown backend');

        $this->build([
            'backend'  => ['main' => ['type' => 'local']],
            'variants' => ['card' => ['width' => 1, 'height' => 1, 'backend' => 'ghost']],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resizer = new StubImageResizer();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function build(array $config): StorageManager
    {
        return StorageConfig::fromArray($config, $this->resizer, '/var/uploads');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     *
     * @mago-expect lint:no-literal-password Dummy credentials for an S3 client that is never called.
     */
    private function s3Stub(array $overrides = []): array
    {
        return [
            'type'      => 's3',
            'endpoint'  => 'https://e.example.com',
            'bucket'    => 'b',
            'key'       => 'k',
            'secret'    => 's',
            'publicUrl' => 'https://cdn.example.com',
            ...$overrides,
        ];
    }
}
