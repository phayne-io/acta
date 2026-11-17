<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Snapshot;

/**
 * Port storing the latest snapshot of each aggregate.
 *
 * Snapshots are a cache: they can be removed at any time, the event stream remains the
 * source of truth. Remove them whenever the class of an aggregate changes shape.
 */
interface SnapshotStore
{
    /**
     * Returns the latest snapshot of an aggregate, `null` when there is none.
     */
    public function get(string $aggregateType, string $aggregateId): ?Snapshot;

    /**
     * Saves snapshots, each replacing the previous one of the same aggregate.
     */
    public function save(Snapshot ...$snapshots): void;

    /**
     * Removes every snapshot of an aggregate type.
     */
    public function removeAll(string $aggregateType): void;
}
