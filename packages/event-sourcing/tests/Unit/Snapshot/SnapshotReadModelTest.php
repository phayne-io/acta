<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Snapshot;

use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Snapshot\InMemorySnapshotStore;
use Acta\EventSourcing\Snapshot\SnapshotReadModel;
use Acta\EventSourcing\Testing\Fixture\Counter;
use PHPUnit\Framework\TestCase;

final class SnapshotReadModelTest extends TestCase
{
    public function testAProjectionSnapshotsTheAggregatesThatChanged(): void
    {
        $store = new InMemoryEventStore();
        $snapshots = new InMemorySnapshotStore();
        $counters = new AggregateRepository(Counter::class, 'counter', $store, snapshots: $snapshots);
        $projections = new ProjectionManager($store, new InMemoryProjectionStore(), $store);
        $readModel = new SnapshotReadModel($counters, $snapshots);

        $first = Counter::start('a');
        $first->increment(4);
        $counters->save($first);
        $counters->save(Counter::start('b'));

        self::assertNull($snapshots->get('counter', 'a'));

        $readModel->projection($projections, 'counter_snapshots')->run(keepRunning: false);

        self::assertSame(2, $snapshots->get('counter', 'a')?->version);
        self::assertSame(1, $snapshots->get('counter', 'b')?->version);

        $first->increment(6);
        $counters->save($first);
        $readModel->projection($projections, 'counter_snapshots')->run(keepRunning: false);

        $snapshot = $snapshots->get('counter', 'a');

        self::assertNotNull($snapshot);
        self::assertSame(3, $snapshot->version);
        self::assertInstanceOf(Counter::class, $snapshot->aggregate);
        self::assertSame(10, $snapshot->aggregate->count());

        // Resetting the projection removes the snapshots, then they are taken again.
        $projections->reset('counter_snapshots');
        $projector = $readModel->projection($projections, 'counter_snapshots');
        $projector->reset();

        self::assertNull($snapshots->get('counter', 'a'));

        $projector->run(keepRunning: false);

        self::assertSame(3, $snapshots->get('counter', 'a')?->version);

        $projector->delete(deleteEmittedEvents: true);

        self::assertNull($snapshots->get('counter', 'b'));
    }
}
