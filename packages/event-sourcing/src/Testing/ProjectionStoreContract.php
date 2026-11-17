<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing;

use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\Projection\ProjectionStatus;
use Acta\EventSourcing\Projection\ProjectionStore;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Contract every {@see ProjectionStore} adapter must honour.
 *
 * Extend it in the test suite of your adapter and implement the two factory methods.
 */
abstract class ProjectionStoreContract extends TestCase
{
    protected ProjectionStore $store;

    /**
     * Returns an empty projection store.
     */
    abstract protected function createProjectionStore(): ProjectionStore;

    /**
     * Returns a second store on the same storage, standing for another process. Stores
     * that cannot be shared between processes return the same instance.
     */
    abstract protected function createCompetingProjectionStore(): ProjectionStore;

    #[Override]
    protected function setUp(): void
    {
        $this->store = $this->createProjectionStore();
    }

    public function testAnUnknownProjectionHasNoCheckpoint(): void
    {
        self::assertNull($this->store->find('unknown'));
        self::assertSame([], $this->store->names());
    }

    public function testItSavesAndRestoresACheckpoint(): void
    {
        $state = ['count' => 3, 'ratio' => 0.5, 'label' => 'été', 'nested' => ['a' => [1, 2], 'b' => null, 'c' => true]];

        $this->store->save('totals', new Position(42, 1337), $state);

        $checkpoint = $this->store->find('totals');

        self::assertNotNull($checkpoint);
        self::assertTrue($checkpoint->position->equals(new Position(42, 1337)));
        self::assertEquals($state, $checkpoint->state);
        self::assertSame(ProjectionStatus::Idle, $checkpoint->status);
    }

    public function testSavingKeepsTheStatus(): void
    {
        $this->store->save('totals', Position::start(), []);
        $this->store->setStatus('totals', ProjectionStatus::Running);
        $this->store->save('totals', new Position(0, 5), ['count' => 1]);

        $checkpoint = $this->store->find('totals');

        self::assertNotNull($checkpoint);
        self::assertSame(ProjectionStatus::Running, $checkpoint->status);
        self::assertSame(['count' => 1], $checkpoint->state);
        self::assertSame(5, $checkpoint->position->sequence);
    }

    public function testItStoresEveryStatus(): void
    {
        $this->store->save('totals', Position::start(), []);

        foreach (ProjectionStatus::cases() as $status) {
            $this->store->setStatus('totals', $status);

            self::assertSame($status, $this->store->find('totals')?->status);
        }
    }

    public function testSettingTheStatusOfAnUnknownProjectionDoesNothing(): void
    {
        $this->store->setStatus('unknown', ProjectionStatus::Stopping);

        self::assertNull($this->store->find('unknown'));
    }

    public function testItDeletesACheckpoint(): void
    {
        $this->store->save('totals', Position::start(), []);
        $this->store->save('other', Position::start(), []);

        $this->store->delete('totals');
        $this->store->delete('unknown');

        self::assertNull($this->store->find('totals'));
        self::assertSame(['other'], $this->store->names());
    }

    public function testItListsProjectionsInAlphabeticalOrder(): void
    {
        foreach (['zeta', 'alpha', 'mid'] as $name) {
            $this->store->save($name, Position::start(), []);
        }

        self::assertSame(['alpha', 'mid', 'zeta'], $this->store->names());
    }

    public function testOnlyOneProcessCanLockAProjection(): void
    {
        $competitor = $this->createCompetingProjectionStore();

        self::assertTrue($this->store->acquireLock('totals'));
        self::assertFalse($competitor->acquireLock('totals'));
        self::assertTrue($competitor->acquireLock('other'));

        $this->store->releaseLock('totals');

        self::assertTrue($competitor->acquireLock('totals'));

        $competitor->releaseLock('totals');
        $competitor->releaseLock('other');
    }
}
