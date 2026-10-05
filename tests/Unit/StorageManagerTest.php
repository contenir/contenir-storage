<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\StorageManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class StorageManagerTest extends TestCase
{
    #[Test]
    public function defaultProfileConstantIsLocal(): void
    {
        static::assertSame('local', StorageManager::DEFAULT_PROFILE);
    }

    #[Test]
    public function getReturnsRegisteredBackend(): void
    {
        $backend = new InMemoryStorage();
        $manager = new StorageManager();

        $manager->register('local', $backend);

        static::assertSame($backend, $manager->get('local'));
    }

    #[Test]
    public function getThrowsForUnknownProfile(): void
    {
        $manager = new StorageManager();

        $this->expectException(InvalidArgumentException::class);

        $manager->get('nope');
    }

    #[Test]
    public function hasReportsRegistrationStatus(): void
    {
        $manager = new StorageManager();

        static::assertFalse($manager->has('local'));

        $manager->register('local', new InMemoryStorage());

        static::assertTrue($manager->has('local'));
    }

    #[Test]
    public function laterRegistrationsDoNotTakeOverThePrimaryUnlessFlagged(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());
        $manager->register('r2-cdn', new InMemoryStorage());

        static::assertSame('local', $manager->primaryKey());
    }

    #[Test]
    public function profilesListsAllRegistered(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());
        $manager->register('r2-cdn', new InMemoryStorage());

        static::assertSame(['local', 'r2-cdn'], $manager->profiles());
    }

    #[Test]
    public function registeredBackendImplementsContract(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());

        static::assertInstanceOf(StorageInterface::class, $manager->get('local'));
    }

    #[Test]
    public function registerRejectsDuplicateProfileName(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());

        $this->expectException(InvalidArgumentException::class);

        $manager->register('local', new InMemoryStorage());
    }

    #[Test]
    public function registerRejectsEmptyProfileName(): void
    {
        $manager = new StorageManager();

        $this->expectException(InvalidArgumentException::class);

        $manager->register('', new InMemoryStorage());
    }
}
