<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Projection;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Exception\ProjectionAlreadyRunning;
use Acta\EventSourcing\Exception\ProjectionNotFound;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\ProjectionStatus;
use Acta\EventSourcing\Projection\Projector;
use Acta\EventSourcing\Projection\Query;
use Acta\EventSourcing\Projection\ReadModelProjector;
use Acta\EventSourcing\Testing\Fixture\CounterIncremented;
use Acta\EventSourcing\Testing\Fixture\CounterStarted;
use Acta\EventSourcing\Tests\Fixture\CounterTotals;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ProjectionTest extends TestCase
{
    private InMemoryEventStore $store;

    private InMemoryProjectionStore $checkpoints;

    private ProjectionManager $projections;

    private EnvelopeFactory $envelopes;

    #[Override]
    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->checkpoints = new InMemoryProjectionStore();
        $this->envelopes = new EnvelopeFactory();
        $this->projections = new ProjectionManager($this->store, $this->checkpoints, $this->store, $this->envelopes);
    }

    public function testAQueryFoldsEventsIntoAStateAndResumesWhereItStopped(): void
    {
        $this->counter('a', 1, 2);
        $this->counter('b', 5);

        $query = $this->projections->query()
            ->init(static fn(): array => ['total' => 0, 'started' => 0])
            ->fromCategory('counter')
            ->when([
                CounterStarted::class => static fn(array $state): array => [...$state, 'started' => $state['started'] + 1],
                CounterIncremented::class => static fn(array $state, CounterIncremented $event): array
                    => [...$state, 'total' => $state['total'] + $event->by],
            ]);

        $query->run();

        self::assertSame(['total' => 8, 'started' => 2], $query->state());

        $this->increment('a', 10);
        $query->run();

        self::assertSame(['total' => 18, 'started' => 2], $query->state());

        $query->reset();

        self::assertSame(['total' => 0, 'started' => 0], $query->state());

        $query->run();

        self::assertSame(['total' => 18, 'started' => 2], $query->state());
    }

    public function testAQueryCanStopEarlyAndReceivesTheRecordedEvent(): void
    {
        $this->counter('a', 1, 2, 3);

        $query = $this->projections->query()
            ->fromStream('counter-a')
            ->whenAny(static function (array $state, object $event, RecordedEvent $recorded, Query $query): array {
                if ($recorded->streamVersion === 3) {
                    $query->stop();
                }

                $state[$recorded->streamVersion] = $event::class;

                return $state;
            });

        $query->run();

        self::assertSame(
            [1 => CounterStarted::class, 2 => CounterIncremented::class, 3 => CounterIncremented::class],
            $query->state(),
        );
    }

    public function testAProjectionMustBeConfiguredOnce(): void
    {
        $failures = 0;
        $attempts = [
            fn() => $this->projections->query()->fromAll()->run(),
            fn() => $this->projections->query()->whenAny(static fn() => null)->run(),
            fn() => $this->projections->query()->fromAll()->fromAll(),
            fn() => $this->projections->query()->init(static fn(): array => [])->init(static fn(): array => []),
            fn() => $this->projections->query()->whenAny(static fn() => null)->whenAny(static fn() => null),
            fn() => $this->projections->query()->when([CounterStarted::class => static fn() => null])->when([CounterStarted::class => static fn() => null]),
            /** @phpstan-ignore argument.type */
            fn() => $this->projections->query()->when([CounterStarted::class => 'strlen']),
            fn() => $this->projections->projection('has space'),
            fn() => new ProjectionOptions(batchSize: 0),
            fn() => new ProjectionOptions(sleepMicroseconds: -1),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgument) {
                $failures++;
            }
        }

        self::assertSame(count($attempts), $failures);
    }

    public function testAProjectorPersistsItsStateAndItsPosition(): void
    {
        $this->counter('a', 1, 2);
        $build = fn(): Projector => $this->projections->projection('totals', new ProjectionOptions(batchSize: 2))
            ->init(static fn(): array => ['total' => 0])
            ->fromAll()
            ->when([
                CounterIncremented::class => static fn(array $state, CounterIncremented $event): array
                    => ['total' => $state['total'] + $event->by],
            ]);

        $build()->run(keepRunning: false);

        self::assertSame(['total' => 3], $this->projections->state('totals'));
        self::assertSame(ProjectionStatus::Idle, $this->projections->status('totals'));
        self::assertTrue($this->projections->position('totals')->equals(new Position(0, 3)));
        self::assertSame(['totals'], $this->projections->names());

        $this->increment('a', 10);
        $this->counter('b', 100);

        // A new instance, as after a restart, resumes from the saved position.
        $projector = $build();
        $projector->run(keepRunning: false);

        self::assertSame(['total' => 113], $projector->state());
        self::assertSame(['total' => 113], $this->projections->state('totals'));
        self::assertTrue($projector->position()->equals(new Position(0, 6)));
    }

    public function testAReadModelProjectorFeedsItsReadModel(): void
    {
        $this->counter('a', 1, 2);
        $this->counter('b', 5);
        $totals = new CounterTotals();

        $projector = $this->totalsProjection($totals, batchSize: 2);
        $projector->run(keepRunning: false);

        self::assertSame($totals, $projector->readModel);
        self::assertSame(['a' => 3, 'b' => 5], $totals->totals);
        self::assertSame(3, $totals->persisted);

        $this->increment('b', 5);
        $this->totalsProjection($totals)->run(keepRunning: false);

        self::assertSame(['a' => 3, 'b' => 10], $totals->totals);
    }

    public function testAFailingBatchIsRolledBackAndHandledAgain(): void
    {
        $this->counter('a', 1, 2, 3);
        $emitted = StreamName::fromString('doubles');
        $failure = new stdClass();
        $failure->enabled = true;
        $build = fn(): Projector => $this->projections->projection('doubles', new ProjectionOptions(batchSize: 10))
            ->init(static fn(): array => ['handled' => 0])
            ->fromCategory('counter')
            ->when([
                CounterIncremented::class => static function (array $state, CounterIncremented $event, RecordedEvent $recorded, Projector $projector) use ($failure): array {
                    $projector->emit(new CounterIncremented($event->counterId, $event->by * 2));

                    if ($failure->enabled && $event->by === 3) {
                        throw new RuntimeException('boom');
                    }

                    return ['handled' => $state['handled'] + 1];
                },
            ]);

        try {
            $build()->run(keepRunning: false);
            self::fail('The failure of a handler should stop the projection.');
        } catch (RuntimeException) {
        }

        self::assertFalse($this->store->hasStream($emitted));
        self::assertSame(['handled' => 0], $this->projections->state('doubles'));
        self::assertSame(ProjectionStatus::Idle, $this->projections->status('doubles'));

        $failure->enabled = false;
        $build()->run(keepRunning: false);

        self::assertSame(['handled' => 3], $this->projections->state('doubles'));
        self::assertSame(
            [2, 4, 6],
            array_map(static fn(RecordedEvent $event) => $event->message->by ?? null, [...$this->store->load($emitted)]),
        );
    }

    public function testAProjectorCanLinkEventsToOtherStreams(): void
    {
        $this->counter('a', 1);
        $this->counter('b', 2);
        $original = [...$this->store->load(StreamName::for('counter', 'b'))][1];

        $this->projections->projection('big_increments')
            ->fromCategory('counter')
            ->when([
                CounterIncremented::class => static function (array $state, CounterIncremented $event, RecordedEvent $recorded, Projector $projector): void {
                    if ($event->by > 1) {
                        $projector->linkTo('big-increments', $recorded);
                    }
                },
            ])
            ->run(keepRunning: false);

        $linked = [...$this->store->load(StreamName::fromString('big-increments'))];

        self::assertCount(1, $linked);
        self::assertEquals($original->message, $linked[0]->message);
        self::assertFalse($original->envelope->id->equals($linked[0]->envelope->id));
        self::assertSame(
            ['id' => $original->envelope->id->value, 'stream' => 'counter-b', 'version' => 2],
            $linked[0]->metadata->get(Metadata::LINK),
        );
    }

    public function testOnlyOneProcessRunsAProjection(): void
    {
        $this->counter('a', 1);
        $nested = null;

        $this->projections->projection('totals')
            ->fromAll()
            ->whenAny(function () use (&$nested): void {
                try {
                    $this->projections->projection('totals')->fromAll()->whenAny(static fn() => null)->run(keepRunning: false);
                } catch (ProjectionAlreadyRunning $exception) {
                    $nested = $exception;
                }
            })
            ->run(keepRunning: false);

        self::assertInstanceOf(ProjectionAlreadyRunning::class, $nested);

        // The lock was released: the projection can run again.
        $this->projections->projection('totals')->fromAll()->whenAny(static fn() => null)->run(keepRunning: false);
    }

    public function testARunningProjectionWaitsForNewEventsUntilItIsStopped(): void
    {
        $this->counter('a', 1);
        $waits = 0;
        $totals = new CounterTotals();
        $options = new ProjectionOptions(
            sleepMicroseconds: 250,
            wait: function (int $microseconds) use (&$waits): void {
                self::assertSame(250, $microseconds);

                match (++$waits) {
                    1 => $this->increment('a', 10),
                    2 => $this->projections->stop('totals'),
                    default => self::fail('The projection should have stopped.'),
                };
            },
        );

        $this->totalsProjection($totals, options: $options)->run();

        self::assertSame(2, $waits);
        self::assertSame(['a' => 11], $totals->totals);
        self::assertSame(ProjectionStatus::Idle, $this->projections->status('totals'));
    }

    public function testAHandlerCanStopARunningProjection(): void
    {
        $this->counter('a', 1, 2, 3);

        $projector = $this->projections->projection('first_two')
            ->init(static fn(): array => ['seen' => 0])
            ->fromAll()
            ->whenAny(static function (array $state, object $event, RecordedEvent $recorded, Projector $projector): array {
                if ($state['seen'] === 1) {
                    $projector->stop();
                }

                return ['seen' => $state['seen'] + 1];
            });

        $projector->run();

        self::assertSame(['seen' => 2], $this->projections->state('first_two'));
        self::assertTrue($this->projections->position('first_two')->equals(new Position(0, 2)));
    }

    public function testAProjectionCanBeResetFromAnotherProcess(): void
    {
        $this->counter('a', 1, 2);
        $totals = new CounterTotals();
        $this->totalsProjection($totals)->run(keepRunning: false);

        $totals->add('ghost', 99);
        $this->projections->reset('totals');

        self::assertSame(ProjectionStatus::Resetting, $this->projections->status('totals'));

        $this->totalsProjection($totals)->run(keepRunning: false);

        self::assertSame(['a' => 3], $totals->totals);
        self::assertSame(ProjectionStatus::Idle, $this->projections->status('totals'));
    }

    public function testAResetProjectorForgetsWhatItEmitted(): void
    {
        $this->counter('a', 1);
        $build = fn(): Projector => $this->projections->projection('echo')
            ->fromCategory('counter')
            ->when([
                CounterIncremented::class => static function (array $state, CounterIncremented $event, RecordedEvent $recorded, Projector $projector): void {
                    $projector->emit($event);
                },
            ]);

        $build()->run(keepRunning: false);
        $projector = $build();
        $projector->reset();

        self::assertFalse($this->store->hasStream(StreamName::fromString('echo')));
        self::assertTrue($this->projections->position('echo')->equals(Position::start()));

        $projector->run(keepRunning: false);

        self::assertSame(1, $this->store->streamVersion(StreamName::fromString('echo')));
    }

    public function testAProjectionCanBeDeletedFromAnotherProcess(): void
    {
        $this->counter('a', 1);
        $totals = new CounterTotals();
        $this->totalsProjection($totals)->run(keepRunning: false);
        $this->totalsProjection(new CounterTotals(), name: 'kept')->run(keepRunning: false);

        $this->projections->delete('totals');
        $this->totalsProjection($totals)->run(keepRunning: false);

        self::assertSame(['kept'], $this->projections->names());
        self::assertFalse($totals->deleted);

        $kept = new CounterTotals();
        $this->totalsProjection($kept, name: 'kept')->run(keepRunning: false);
        $this->projections->delete('kept', deleteEmittedEvents: true);
        $this->totalsProjection($kept, name: 'kept')->run(keepRunning: false);

        self::assertSame([], $this->projections->names());
        self::assertTrue($kept->deleted);
    }

    public function testDeletingAProjectorCanAlsoRemoveItsEmittedEvents(): void
    {
        $this->counter('a', 1);
        $echo = StreamName::fromString('echo');
        $build = fn(): Projector => $this->projections->projection('echo')
            ->fromCategory('counter')
            ->whenAny(static function (array $state, object $event, RecordedEvent $recorded, Projector $projector): void {
                $projector->emit($event);
            });

        $build()->run(keepRunning: false);
        $build()->delete();

        self::assertTrue($this->store->hasStream($echo));
        self::assertSame([], $this->projections->names());

        $build()->run(keepRunning: false);
        $build()->delete(deleteEmittedEvents: true);

        self::assertFalse($this->store->hasStream($echo));
    }

    public function testAStopRequestedWhileIdleIsConsumedByTheNextRun(): void
    {
        $this->counter('a', 1);
        $totals = new CounterTotals();
        $this->totalsProjection($totals)->run(keepRunning: false);
        $this->increment('a', 5);

        $this->projections->stop('totals');
        $this->totalsProjection($totals)->run(keepRunning: false);

        self::assertSame(['a' => 1], $totals->totals);
        self::assertSame(ProjectionStatus::Idle, $this->projections->status('totals'));

        $this->totalsProjection($totals)->run(keepRunning: false);

        self::assertSame(['a' => 6], $totals->totals);
    }

    public function testTheManagerRefusesUnknownProjections(): void
    {
        $failures = 0;

        foreach (['stop', 'reset', 'delete', 'status', 'position', 'state'] as $method) {
            try {
                $this->projections->{$method}('unknown');
            } catch (ProjectionNotFound) {
                $failures++;
            }
        }

        self::assertSame(6, $failures);
    }

    public function testASelectorNarrowsWhatAProjectionReads(): void
    {
        $this->counter('a', 1);
        $this->store->append(
            StreamName::for('gauge', 'g'),
            ExpectedVersion::any(),
            $this->envelopes->wrap(new CounterIncremented('g', 50), new Metadata(['tenant' => 'acme'])),
        );

        $query = $this->projections->query()
            ->init(static fn(): array => ['total' => 0])
            ->from(Selector::category('gauge')->named(CounterIncremented::class))
            ->whenAny(static fn(array $state, CounterIncremented $event): array => ['total' => $state['total'] + $event->by]);
        $query->run();

        self::assertSame(['total' => 50], $query->state());
    }

    private function counter(string $id, int ...$increments): void
    {
        $this->store->append(
            StreamName::for('counter', $id),
            ExpectedVersion::noStream(),
            $this->envelopes->wrap(new CounterStarted($id)),
            ...array_map(fn(int $by) => $this->envelopes->wrap(new CounterIncremented($id, $by)), array_values($increments)),
        );
    }

    private function increment(string $id, int $by): void
    {
        $this->store->append(
            StreamName::for('counter', $id),
            ExpectedVersion::streamExists(),
            $this->envelopes->wrap(new CounterIncremented($id, $by)),
        );
    }

    private function totalsProjection(
        CounterTotals $totals,
        string $name = 'totals',
        int $batchSize = 1000,
        ?ProjectionOptions $options = null,
    ): ReadModelProjector {
        return $this->projections
            ->readModelProjection($name, $totals, $options ?? new ProjectionOptions(batchSize: $batchSize))
            ->fromCategory('counter')
            ->when([
                CounterIncremented::class => static fn(array $state, CounterIncremented $event) => $totals->add($event->counterId, $event->by),
            ]);
    }
}
