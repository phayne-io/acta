<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\Exception\ProjectionNotFound;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\TransactionManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Entry point to create projections and to control the ones that are persisted.
 *
 * It offers what Prooph's `ProjectionManager` did, with one implementation for every
 * database: the storage specifics live behind the {@see EventStore} and
 * {@see ProjectionStore} ports.
 *
 * As in Prooph, {@see stop()}, {@see reset()} and {@see delete()} do not act by
 * themselves. They record a request that the process running the projection carries out
 * between two batches, or the next time the projection is started.
 */
final readonly class ProjectionManager
{
    /**
     * @param TransactionManager|null $transactions when given, each batch of a persistent projection
     *                                              (handlers, read model, checkpoint) is one transaction
     * @param EnvelopeFactory         $envelopes    used for the events emitted by projections
     * @param LoggerInterface         $logger       PSR-3 logger handed to the persistent projections, which
     *                                              report their lifecycle and their failures to it
     */
    public function __construct(
        private EventStore $eventStore,
        private ProjectionStore $store,
        private ?TransactionManager $transactions = null,
        private EnvelopeFactory $envelopes = new EnvelopeFactory(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Creates a one-off, in-memory query over the events.
     */
    public function query(): Query
    {
        return new Query($this->eventStore);
    }

    /**
     * Creates or resumes the persistent projection of the given name.
     */
    public function projection(string $name, ProjectionOptions $options = new ProjectionOptions()): Projector
    {
        return new Projector(
            $name,
            $this->eventStore,
            $this->store,
            $this->transactions,
            $options,
            $this->envelopes,
            $this->logger,
        );
    }

    /**
     * Creates or resumes the persistent projection feeding a read model.
     */
    public function readModelProjection(
        string $name,
        ReadModel $readModel,
        ProjectionOptions $options = new ProjectionOptions(),
    ): ReadModelProjector {
        return new ReadModelProjector(
            $name,
            $this->eventStore,
            $this->store,
            $this->transactions,
            $options,
            $readModel,
            $this->logger,
        );
    }

    /**
     * Asks a projection to stop after its current batch.
     *
     * @throws ProjectionNotFound
     */
    public function stop(string $name): void
    {
        $this->request($name, ProjectionStatus::Stopping);
    }

    /**
     * Asks a projection to start over from the first event.
     *
     * @throws ProjectionNotFound
     */
    public function reset(string $name): void
    {
        $this->request($name, ProjectionStatus::Resetting);
    }

    /**
     * Asks a projection to remove itself.
     *
     * @param bool $deleteEmittedEvents also removes the emitted events or the read model
     *
     * @throws ProjectionNotFound
     */
    public function delete(string $name, bool $deleteEmittedEvents = false): void
    {
        $this->request(
            $name,
            $deleteEmittedEvents ? ProjectionStatus::DeletingWithEmittedEvents : ProjectionStatus::Deleting,
        );
    }

    /**
     * Lists the persisted projections, in alphabetical order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->store->names();
    }

    /**
     * @throws ProjectionNotFound
     */
    public function status(string $name): ProjectionStatus
    {
        return $this->checkpoint($name)->status;
    }

    /**
     * Returns the position of the last event a projection handled. Compare it with the
     * position of a freshly appended event to know whether a read model is up to date.
     *
     * @throws ProjectionNotFound
     */
    public function position(string $name): Position
    {
        return $this->checkpoint($name)->position;
    }

    /**
     * Returns the persisted state of a projection.
     *
     * @return array<mixed>
     *
     * @throws ProjectionNotFound
     */
    public function state(string $name): array
    {
        return $this->checkpoint($name)->state;
    }

    private function request(string $name, ProjectionStatus $status): void
    {
        $this->checkpoint($name);
        $this->store->setStatus($name, $status);
    }

    private function checkpoint(string $name): Checkpoint
    {
        return $this->store->find($name)
            ?? throw new ProjectionNotFound(sprintf('Projection "%s" does not exist.', $name));
    }
}
