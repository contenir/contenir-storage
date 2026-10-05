<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Internal;

use Contenir\Storage\Internal\Warnings;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;
use function trigger_error;

use const E_USER_WARNING;

#[Group('unit')]
#[Group('storage')]
final class WarningsTest extends TestCase
{
    #[Test]
    public function suppressRestoresThePreviousHandlerEvenWhenTheCallThrows(): void
    {
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            Warnings::suppress(static fn(): never => throw new LogicException('boom'));
        } catch (LogicException) {
            trigger_error('after', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        static::assertSame(['after'], $seen);
    }

    #[Test]
    public function suppressReturnsTheResultAndDiscardsTheWarning(): void
    {
        $result = Warnings::suppress(static function (string $value): string {
            trigger_error('ignored', E_USER_WARNING);

            return $value;
        }, 'kept');

        static::assertSame('kept', $result);
    }
}
