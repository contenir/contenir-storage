<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Trait;

use Contenir\Storage\Internal\Warnings;
use RuntimeException;

use function array_map;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function fsockopen;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function sprintf;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Runs PHP's built-in web server on a free loopback port as a stand-in S3
 * endpoint that records every request it receives, so tests can observe what
 * a configured client actually sends without touching the network.
 */
trait FakeS3EndpointTrait
{
    private int $fakeS3Port = 0;

    private string $fakeS3Log = '';

    /** @var resource|null */
    private $fakeS3Process;

    protected function setUpFakeS3Endpoint(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Cannot reserve a loopback port.');
        }
        $name = (string) stream_socket_get_name($probe, remote: false);
        fclose($probe);

        $this->fakeS3Port = (int) substr($name, (int) strrpos($name, needle: ':') + 1);
        $this->fakeS3Log  = sys_get_temp_dir() . '/contenir-fake-s3-' . uniqid(more_entropy: true) . '.log';
        $process          = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->fakeS3Port}", __DIR__ . '/../TestAsset/S3/recording-router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $_pipes,
            env_vars: ['S3_REQUEST_LOG' => $this->fakeS3Log],
        );
        $this->fakeS3Process = false === $process ? null : $process;

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = Warnings::suppress(fsockopen(...), '127.0.0.1', $this->fakeS3Port);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(20_000);
        }

        throw new RuntimeException(sprintf('Fake S3 endpoint did not start on port %d.', $this->fakeS3Port));
    }

    protected function tearDownFakeS3Endpoint(): void
    {
        if (is_resource($this->fakeS3Process)) {
            proc_terminate($this->fakeS3Process);
            proc_close($this->fakeS3Process);
        }
        if ('' !== $this->fakeS3Log && file_exists($this->fakeS3Log)) {
            unlink($this->fakeS3Log);
        }
    }

    /**
     * @return list<array{method: string, host: string, path: string, authorization: string}>
     */
    private function fakeS3Requests(): array
    {
        if (! file_exists($this->fakeS3Log)) {
            return [];
        }

        return array_map(
            static fn(string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            explode("\n", trim((string) file_get_contents($this->fakeS3Log))),
        );
    }
}
