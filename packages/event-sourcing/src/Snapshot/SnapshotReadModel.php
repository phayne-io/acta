<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Snapshot;

use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\ReadModel;
use Acta\EventSourcing\Projection\ReadModelProjector;
use Override;

/**
 * Read model that snapshots aggregates in the background.
 *
 * It is the counterpart of Prooph's snapshotter: a projection follows the events of one
 * aggregate type, and at the end of each batch the aggregates that changed are loaded
 * and snapshotted. Command handling is not slowed down, unlike with the
 * `$snapshotEvery` option of the repository.
 *
 * ```php
 * new SnapshotReadModel($accounts, $snapshots)
 *     ->projection($projections, 'account_snapshots')
 *     ->run();
 * ```
 */
final class SnapshotReadModel implements ReadModel
{
    /** @var array<string, true> */
    private array $changed = [];

    /**
     * @param AggregateRepository<covariant AggregateRoot> $repository repository of the aggregates to
     *                                                                 snapshot, configured with `$snapshots`
     */
    public function __construct(
        private readonly AggregateRepository $repository,
        private readonly SnapshotStore $snapshots,
    ) {}

    /**
     * Builds the projection that feeds this read model with the events of the aggregate
     * type handled by the repository.
     */
    public function projection(
        ProjectionManager $projections,
        string $name,
        ProjectionOptions $options = new ProjectionOptions(),
    ): ReadModelProjector {
        return $projections
            ->readModelProjection($name, $this, $options)
            ->fromCategory($this->repository->category)
            ->whenAny(function (array $state, object $event, RecordedEvent $recorded): void {
                $this->mark((string) $recorded->stream->id);
            });
    }

    /**
     * Flags an aggregate as changed; its snapshot is taken on the next {@see persist()}.
     */
    public function mark(string $aggregateId): void
    {
        if ($aggregateId !== '') {
            $this->changed[$aggregateId] = true;
        }
    }

    #[Override]
    public function persist(): void
    {
        foreach (array_keys($this->changed) as $aggregateId) {
            $this->repository->takeSnapshot((string) $aggregateId);
        }

        $this->changed = [];
    }

    #[Override]
    public function init(): void {}

    #[Override]
    public function isInitialized(): bool
    {
        return true;
    }

    #[Override]
    public function reset(): void
    {
        $this->changed = [];
        $this->snapshots->removeAll($this->repository->category);
    }

    #[Override]
    public function delete(): void
    {
        $this->reset();
    }
}
