<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

/**
 * A query-optimized view kept up to date by a {@see ReadModelProjector}.
 *
 * The interface only covers the lifecycle the projector drives. The methods that change
 * the view (insert a row, increment a counter...) are yours: handlers call them
 * directly on the read model.
 *
 * Compared with Prooph, `stack()` is gone. When the read model lives in the same
 * database as the checkpoint, the projector wraps each batch in one transaction, which
 * gives the atomicity `stack()` and `persist()` were emulating.
 */
interface ReadModel
{
    /**
     * Creates the storage of the read model: tables, indexes, collections.
     */
    public function init(): void;

    /**
     * Tells whether {@see init()} has already been done.
     */
    public function isInitialized(): bool;

    /**
     * Empties the read model, before the projection replays every event.
     */
    public function reset(): void;

    /**
     * Removes the storage of the read model.
     */
    public function delete(): void;

    /**
     * Called at the end of each batch, before the checkpoint is saved.
     *
     * Read models that buffer their writes flush them here; the others leave it empty.
     */
    public function persist(): void;
}
