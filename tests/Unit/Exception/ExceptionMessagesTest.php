<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Exception;

use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\StorageException;
use Contenir\Storage\Exception\UnsupportedTypeException;
use Contenir\Storage\Exception\WriteException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[Group('storage')]
final class ExceptionMessagesTest extends TestCase
{
    /**
     * @return array<string, array{callable(): StorageException, string}>
     */
    public static function messageProvider(): array
    {
        return [
            'traversal'     => [
                static fn(): StorageException => InvalidPathException::forTraversal('../x'),
                'Path "../x" contains parent-directory traversal.',
            ],
            'null byte'     => [
                static fn(): StorageException => InvalidPathException::forNullByte('a'),
                'Path "a" contains a null byte.',
            ],
            'absolute path' => [
                static fn(): StorageException => InvalidPathException::forAbsolutePath('/a'),
                'Path "/a" must be relative to the storage root.',
            ],
            'escape'        => [
                static fn(): StorageException => InvalidPathException::forEscape('link'),
                'Path "link" resolves outside the storage root.',
            ],
            'empty name'    => [
                static fn(): StorageException => InvalidPathException::forEmptyName('@@'),
                'Filename "@@" has no slug-safe characters to store under.',
            ],
            'not found'     => [
                static fn(): StorageException => NotFoundException::forPath('a.png'),
                'Asset "a.png" does not exist.',
            ],
            'mime'          => [
                static fn(): StorageException => UnsupportedTypeException::forMime('x/y', 'a.bin'),
                'Detected type "x/y" of upload "a.bin" is not a supported storage type.',
            ],
            'undetectable'  => [
                static fn(): StorageException => UnsupportedTypeException::forUndetectable('/tmp/a'),
                'Could not detect the type of upload source "/tmp/a".',
            ],
            'unreadable'    => [
                static fn(): StorageException => UnsupportedTypeException::forUnreadable('/tmp/a'),
                'Upload source "/tmp/a" is not readable.',
            ],
        ];
    }

    #[Test]
    public function everyStorageFailureIsARuntimeException(): void
    {
        static::assertInstanceOf(RuntimeException::class, new WriteException('x'));
        static::assertInstanceOf(StorageException::class, new WriteException('x'));
    }

    #[Test]
    #[DataProvider('messageProvider')]
    public function namedConstructorsDescribeTheFailure(callable $create, string $message): void
    {
        static::assertSame($message, $create()->getMessage());
    }
}
