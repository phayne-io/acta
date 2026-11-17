<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Snapshot;

use Override;

/**
 * Snapshot store keeping snapshots in a PHP array, for tests and prototypes.
 *
 * Snapshots are stored serialized, as a database would, so that the aggregate returned
 * by {@see get()} is a copy: changing it does not alter the stored snapshot, and
 * aggregates that cannot be serialized are detected early.
 */
final class InMemorySnapshotStore implements SnapshotStore
{
    /** @var array<string, array<string, string>> */
    private array $snapshots = [];

    #[Override]
    public function get(string $aggregateType, string $aggregateId): ?Snapshot
    {
        $serialized = $this->snapshots[$aggregateType][$aggregateId] ?? null;

        if ($serialized === null) {
            return null;
        }

        $snapshot = unserialize($serialized);

        return $snapshot instanceof Snapshot ? $snapshot : null;
    }

    #[Override]
    public function save(Snapshot ...$snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            $this->snapshots[$snapshot->aggregateType][$snapshot->aggregateId] = serialize($snapshot);
        }
    }

    #[Override]
    public function removeAll(string $aggregateType): void
    {
        unset($this->snapshots[$aggregateType]);
    }
}
