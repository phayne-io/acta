<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Aggregate;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\AggregateNotFound;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Snapshot\Snapshot;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Acta\EventSourcing\SystemClock;
use NoDiscard;
use Psr\Clock\ClockInterface;
use Stringable;

/**
 * Loads aggregates from their event stream and appends the events they record.
 *
 * Each aggregate has its own stream, named `<category>-<id>`. The repository is
 * stateless: it keeps no identity map, so it is safe in long-running workers.
 *
 * It is generic and meant to be wrapped by the repository of your domain, which exposes
 * the vocabulary of the model and implements a port owned by it:
 *
 * ```php
 * final readonly class EventSourcedAccounts implements Accounts
 * {
 *     // @param AggregateRepository<Account> $repository
 *     public function __construct(private AggregateRepository $repository) {}
 *
 *     public function get(AccountId $id): Account
 *     {
 *         return $this->repository->get($id);
 *     }
 *
 *     public function save(Account $account): void
 *     {
 *         $this->repository->save($account);
 *     }
 * }
 * ```
 *
 * @template T of AggregateRoot
 */
final readonly class AggregateRepository
{
    /**
     * @param class-string<T>    $aggregateClass class of the aggregates handled by this repository
     * @param string             $category       stream category and snapshot type, e.g. `account`
     * @param SnapshotStore|null $snapshots      enables loading from snapshots when given
     * @param int                $snapshotEvery  takes a snapshot each time an aggregate passes a multiple
     *                                           of this many events; 0 leaves snapshotting to
     *                                           {@see takeSnapshot()}
     *
     * @throws InvalidArgument when the class is not an aggregate or the category is invalid
     */
    public function __construct(
        private string $aggregateClass,
        public string $category,
        private EventStore $eventStore,
        private EnvelopeFactory $envelopes = new EnvelopeFactory(),
        private ?SnapshotStore $snapshots = null,
        private int $snapshotEvery = 0,
        private ClockInterface $clock = new SystemClock(),
    ) {
        if (!is_subclass_of($aggregateClass, AggregateRoot::class)) {
            throw new InvalidArgument(sprintf('"%s" is not an aggregate root.', $aggregateClass));
        }

        if ($snapshotEvery < 0) {
            throw new InvalidArgument('The snapshot interval cannot be negative.');
        }

        if ($snapshotEvery > 0 && $snapshots === null) {
            throw new InvalidArgument('A snapshot store is required to take snapshots automatically.');
        }

        StreamName::for($category, 'check');
    }

    /**
     * Appends the events recorded by the aggregate to its stream.
     *
     * @param T $aggregate
     *
     * @throws ConcurrencyConflict when the stream changed since the aggregate was loaded
     */
    public function save(AggregateRoot $aggregate): void
    {
        if (!$aggregate instanceof $this->aggregateClass) {
            throw new InvalidArgument(sprintf(
                'This repository stores "%s", got "%s".',
                $this->aggregateClass,
                $aggregate::class,
            ));
        }

        $events = AggregateAccess::releaseEvents($aggregate);

        if ($events === []) {
            return;
        }

        $versionBefore = $aggregate->version - count($events);

        $this->eventStore->append(
            $this->streamName($aggregate->aggregateId()),
            ExpectedVersion::exactly($versionBefore),
            ...array_map(fn(DomainEvent $event): Envelope => $this->envelopes->wrap($event), $events),
        );

        if (
            $this->snapshotEvery > 0
            && intdiv($aggregate->version, $this->snapshotEvery) > intdiv($versionBefore, $this->snapshotEvery)
        ) {
            $this->storeSnapshot($aggregate);
        }
    }

    /**
     * Rebuilds an aggregate from its latest snapshot, if any, and the events that follow.
     *
     * @return T|null `null` when the aggregate has no event
     */
    public function find(string|Stringable $id): ?AggregateRoot
    {
        $snapshot = $this->snapshots?->get($this->category, (string) $id);
        $aggregate = $snapshot?->aggregate;

        if ($aggregate !== null && !$aggregate instanceof $this->aggregateClass) {
            $aggregate = null;
        }

        $fromVersion = $aggregate === null ? 1 : $aggregate->version + 1;

        foreach ($this->eventStore->load($this->streamName($id), $fromVersion) as $recorded) {
            if (!$recorded->message instanceof DomainEvent) {
                continue;
            }

            $aggregate ??= AggregateAccess::instantiate($this->aggregateClass);

            AggregateAccess::replayOn($aggregate, $recorded->message, $recorded->streamVersion);
        }

        return $aggregate;
    }

    /**
     * Same as {@see find()} for aggregates that are expected to exist.
     *
     * @return T
     *
     * @throws AggregateNotFound
     */
    #[NoDiscard]
    public function get(string|Stringable $id): AggregateRoot
    {
        return $this->find($id) ?? throw new AggregateNotFound(sprintf(
            'No "%s" aggregate has the identifier "%s".',
            $this->category,
            $id,
        ));
    }

    /**
     * Tells whether the aggregate has at least one event.
     */
    public function exists(string|Stringable $id): bool
    {
        return $this->eventStore->hasStream($this->streamName($id));
    }

    /**
     * Stores a snapshot of the current state of an aggregate.
     *
     * It is what Prooph's snapshotter did on a `TakeSnapshot` command. Does nothing when
     * the aggregate does not exist.
     *
     * @throws InvalidArgument when the repository has no snapshot store
     */
    public function takeSnapshot(string|Stringable $id): void
    {
        if ($this->snapshots === null) {
            throw new InvalidArgument('This repository has no snapshot store.');
        }

        $aggregate = $this->find($id);

        if ($aggregate !== null) {
            $this->storeSnapshot($aggregate);
        }
    }

    /**
     * Returns the name of the stream of one aggregate.
     */
    public function streamName(string|Stringable $id): StreamName
    {
        return StreamName::for($this->category, $id);
    }

    private function storeSnapshot(AggregateRoot $aggregate): void
    {
        $this->snapshots?->save(new Snapshot(
            $this->category,
            $aggregate->aggregateId(),
            $aggregate,
            $aggregate->version,
            $this->clock->now(),
        ));
    }
}
