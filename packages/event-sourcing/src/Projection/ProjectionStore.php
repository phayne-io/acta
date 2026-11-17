<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\Position;

/**
 * Port persisting the checkpoints of projections and arbitrating who runs them.
 *
 * Prooph shipped one projector implementation per database. Here the projection engine
 * is written once against this port and against the event store port, so supporting a
 * new database means implementing these few methods.
 */
interface ProjectionStore
{
    /**
     * Returns the checkpoint of a projection, `null` when it was never persisted.
     */
    public function find(string $name): ?Checkpoint;

    /**
     * Persists the position and the state of a projection, creating it as idle if needed.
     * The status of an existing projection is left untouched.
     *
     * @param array<mixed> $state
     */
    public function save(string $name, Position $position, array $state): void;

    /**
     * Changes the status of a projection. Does nothing when the projection does not exist.
     */
    public function setStatus(string $name, ProjectionStatus $status): void;

    /**
     * Removes the checkpoint of a projection.
     */
    public function delete(string $name): void;

    /**
     * Lists the names of the persisted projections, in alphabetical order.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * Tries to become the only process running a projection.
     *
     * @return bool `false` when another process already holds the lock
     */
    public function acquireLock(string $name): bool;

    /**
     * Releases a lock obtained with {@see acquireLock()}.
     */
    public function releaseLock(string $name): void;
}
