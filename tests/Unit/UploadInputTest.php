<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\UploadInput;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class UploadInputTest extends TestCase
{
    #[Test]
    public function fromFilesArrayCoercesEmptyStringsForMissingPaths(): void
    {
        $input = UploadInput::fromFilesArray([]);

        static::assertSame('', $input->sourcePath);
        static::assertSame('', $input->clientFilename);
        static::assertNull($input->clientMime);
    }

    #[Test]
    public function fromFilesArrayDefaultsMimeToNullWhenMissing(): void
    {
        $input = UploadInput::fromFilesArray([
            'name'     => 'doc.txt',
            'tmp_name' => '/tmp/up',
        ]);

        static::assertNull($input->clientMime);
    }

    #[Test]
    public function fromFilesArrayMapsCorePhpUploadKeys(): void
    {
        $input = UploadInput::fromFilesArray([
            'name'     => 'photo.jpg',
            'tmp_name' => '/tmp/php1234',
            'type'     => 'image/jpeg',
            'size'     => 12_345,
            'error'    => 0,
        ]);

        static::assertSame('/tmp/php1234', $input->sourcePath);
        static::assertSame('photo.jpg', $input->clientFilename);
        static::assertSame('image/jpeg', $input->clientMime);
    }
}
