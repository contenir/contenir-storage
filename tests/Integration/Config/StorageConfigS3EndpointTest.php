<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Config;

use Contenir\Storage\Config\StorageConfig;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Tests\Trait\FakeS3EndpointTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Builds S3 backends from config against a local stand-in endpoint and
 * checks what the configured client actually sends: the bucket addressing
 * style and the signing region.
 */
#[Group('integration')]
#[Group('storage')]
final class StorageConfigS3EndpointTest extends TestCase
{
    use FakeS3EndpointTrait;

    #[Test]
    public function addressesTheBucketByHostUnlessPathStyleIsEnabled(): void
    {
        $this->backend([])->exists('a.png');

        static::assertSame([["bucket.s3.localhost:{$this->fakeS3Port}", '/a.png']], $this->hostsAndPaths());
    }

    #[Test]
    public function addressesTheBucketByPathWhenPathStyleIsEnabled(): void
    {
        $this->backend(['usePathStyleEndpoint' => true])->exists('a.png');

        static::assertSame([["s3.localhost:{$this->fakeS3Port}", '/bucket/a.png']], $this->hostsAndPaths());
    }

    #[Test]
    public function signsRequestsForTheConfiguredRegion(): void
    {
        $this->backend(['region' => 'eu-test-1'])->exists('a.png');

        static::assertStringContainsString(
            '/eu-test-1/s3/aws4_request',
            $this->fakeS3Requests()[0]['authorization'] ?? '',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeS3Endpoint();
    }

    protected function tearDown(): void
    {
        $this->tearDownFakeS3Endpoint();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @mago-expect lint:no-literal-password Dummy credentials for the local stand-in endpoint.
     */
    private function backend(array $overrides): StorageInterface
    {
        return StorageConfig::fromArray(
            [
                'backend' => [
                    'r2' => [
                        'type'      => 's3',
                        'default'   => true,
                        'endpoint'  => "http://s3.localhost:{$this->fakeS3Port}",
                        'bucket'    => 'bucket',
                        'key'       => 'k',
                        'secret'    => 's',
                        'publicUrl' => 'https://cdn.example.com',
                        ...$overrides,
                    ],
                ],
            ],
            new StubImageResizer(),
            '/var/uploads',
        )->primary();
    }

    /**
     * @return list<array{string, string}>
     */
    private function hostsAndPaths(): array
    {
        return array_map(
            static fn(array $request): array => [$request['host'], $request['path']],
            $this->fakeS3Requests(),
        );
    }
}
