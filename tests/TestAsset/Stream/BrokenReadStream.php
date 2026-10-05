<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\TestAsset\Stream;

use RuntimeException;

use function fopen;
use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;

/**
 * A readable stream whose reads fail part-way through a download: either
 * reporting failure (`false`), which makes stream_copy_to_stream() return
 * false, or throwing, as a broken network stream can.
 *
 * @mago-expect lint:method-name PHP's stream wrapper protocol requires the snake_case stream_* method names.
 */
final class BrokenReadStream
{
    public const string PROTOCOL = 'contenir-broken-read';

    /** @var resource|null */
    public $context;

    private bool $throws = false;

    /**
     * A stream whose reads report failure.
     *
     * @return resource
     */
    public static function failing()
    {
        return self::open('false');
    }

    /**
     * A stream whose reads throw.
     *
     * @return resource
     */
    public static function throwing()
    {
        return self::open('throw');
    }

    /**
     * @return resource
     */
    private static function open(string $mode)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), strict: true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $stream = fopen(self::PROTOCOL . "://{$mode}", mode: 'rb');
        if (false === $stream) {
            throw new RuntimeException('Cannot open the broken read stream.');
        }

        return $stream;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->throws = self::PROTOCOL . '://throw' === $path;

        return true;
    }

    public function stream_read(int $count): false
    {
        if ($this->throws) {
            throw new RuntimeException('Connection reset while downloading.');
        }

        return false;
    }

    /**
     * @return array<never, never>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
