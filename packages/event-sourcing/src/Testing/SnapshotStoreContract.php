<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing;

use Acta\EventSourcing\Aggregate\AggregateAccess;
use Acta\EventSourcing\Snapshot\Snapshot;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Acta\EventSourcing\Testing\Fixture\Counter;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Contract every {@see SnapshotStore} adapter must honour.
 *
 * Extend it in the test suite of your adapter and implement {@see createSnapshotStore()}.
 */
abstract class SnapshotStoreContract extends TestCase
{
    protected SnapshotStore $store;

    /**
     * Returns an empty snapshot store.
     */
    abstract protected function createSnapshotStore(): SnapshotStore;

    #[Override]
    protected function setUp(): void
    {
        $this->store = $this->createSnapshotStore();
    }

    public function testAnAggregateWithoutSnapshotHasNone(): void
    {
        self::assertNull($this->store->get('counter', 'unknown'));
    }

    public function testItSavesAndRestoresASnapshot(): void
    {
        $takenAt = new DateTimeImmutable('2026-01-15T10:30:00.123456+00:00');

        $this->store->save(self::snapshot('a', increments: 3, takenAt: $takenAt));

        $snapshot = $this->store->get('counter', 'a');

        self::assertNotNull($snapshot);
        self::assertSame('counter', $snapshot->aggregateType);
        self::assertSame('a', $snapshot->aggregateId);
        self::assertSame(4, $snapshot->version);
        self::assertEquals($takenAt, $snapshot->createdAt);
        self::assertInstanceOf(Counter::class, $snapshot->aggregate);
        self::assertSame(3, $snapshot->aggregate->count());
        self::assertSame(4, $snapshot->aggregate->version);
        self::assertSame('a', $snapshot->aggregate->aggregateId());
    }

    public function testTheRestoredAggregateIsACopy(): void
    {
        $this->store->save(self::snapshot('a', increments: 1));

        $first = $this->store->get('counter', 'a')?->aggregate;
        self::assertInstanceOf(Counter::class, $first);
        $first->increment(10);

        $second = $this->store->get('counter', 'a')?->aggregate;
        self::assertInstanceOf(Counter::class, $second);
        self::assertSame(1, $second->count());
    }

    public function testANewSnapshotReplacesThePreviousOne(): void
    {
        $this->store->save(self::snapshot('a', increments: 1));
        $this->store->save(self::snapshot('a', increments: 5), self::snapshot('b', increments: 2));

        self::assertSame(6, $this->store->get('counter', 'a')?->version);
        self::assertSame(3, $this->store->get('counter', 'b')?->version);
    }

    public function testItRemovesTheSnapshotsOfOneAggregateType(): void
    {
        $counter = self::snapshot('a', increments: 1);
        $gauge = new Snapshot('gauge', 'a', $counter->aggregate, $counter->version, $counter->createdAt);

        $this->store->save($counter, $gauge);
        $this->store->removeAll('counter');

        self::assertNull($this->store->get('counter', 'a'));
        self::assertNotNull($this->store->get('gauge', 'a'));
    }

    private static function snapshot(string $id, int $increments, ?DateTimeImmutable $takenAt = null): Snapshot
    {
        $counter = Counter::start($id);

        for ($i = 0; $i < $increments; $i++) {
            $counter->increment();
        }

        // What a repository does before snapshotting: the pending events are released.
        AggregateAccess::releaseEvents($counter);

        return new Snapshot('counter', $id, $counter, $counter->version, $takenAt ?? new DateTimeImmutable());
    }
}
