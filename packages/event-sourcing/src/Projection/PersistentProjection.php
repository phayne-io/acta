<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Exception\ProjectionAlreadyRunning;
use Acta\EventSourcing\TransactionManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Engine of the projections that remember where they stopped.
 *
 * It reads the global log by batches, calls the handlers and saves the position and the
 * state after each batch, inside one transaction when a transaction manager is
 * available. A crash in the middle of a batch therefore leaves the checkpoint and the
 * read model where they were, and the batch is handled again on the next run.
 *
 * The engine is written against the {@see EventStore} and {@see ProjectionStore} ports
 * only, so the same code runs in memory and on PostgreSQL.
 *
 * Its lifecycle is reported to a PSR-3 logger: start, stop, reset and deletion at the
 * info and notice levels, each batch at the debug level, and the failure of a handler at
 * the error level, with the position reached, before the exception is rethrown.
 *
 * @internal extended by {@see Projector} and {@see ReadModelProjector} only
 */
abstract class PersistentProjection extends Projection
{
    private Position $position;

    private bool $running = false;

    private bool $deleted = false;

    /**
     * @internal use the {@see ProjectionManager}
     */
    public function __construct(
        public readonly string $name,
        protected readonly EventStore $eventStore,
        private readonly ProjectionStore $store,
        private readonly ?TransactionManager $transactions,
        private readonly ProjectionOptions $options,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($name === '' || strlen($name) > 150 || preg_match('/\s/', $name) === 1) {
            throw new InvalidArgument(sprintf(
                'A projection name must be 1 to 150 characters long without whitespace, got "%s".',
                $name,
            ));
        }

        $this->position = Position::start();
    }

    /**
     * Handles the events the projection has not seen yet.
     *
     * @param bool $keepRunning `true` to wait for new events until the projection is stopped,
     *                          `false` to return once it has caught up with the log
     *
     * @throws ProjectionAlreadyRunning when another process runs the same projection
     */
    public function run(bool $keepRunning = true): void
    {
        $selector = $this->selector();

        if (!$this->store->acquireLock($this->name)) {
            throw new ProjectionAlreadyRunning(sprintf('Projection "%s" is already running.', $this->name));
        }

        $this->running = true;
        $this->stopped = false;
        $this->deleted = false;

        try {
            // A request made while the projection was not running is carried out first.
            if ($this->applyRequestedStatus()) {
                return;
            }

            $this->load();
            $this->store->setStatus($this->name, ProjectionStatus::Running);
            $this->logger->info('Projection {projection} started at position {position}.', $this->logContext());

            do {
                if ($this->options->dispatchSignals && function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                if ($this->stopped || $this->applyRequestedStatus()) {
                    break;
                }

                $events = iterator_to_array(
                    $this->eventStore->read($selector, $this->position, $this->options->batchSize),
                    false,
                );

                if ($events !== []) {
                    $work = fn() => $this->handleBatch($events);
                    $this->transactions === null ? $work() : $this->transactions->transactional($work);

                    continue;
                }

                if ($keepRunning) {
                    ($this->options->wait)($this->options->sleepMicroseconds);
                }
            } while ($keepRunning || $events !== []);

            $this->logger->info('Projection {projection} stopped at position {position}.', $this->logContext());
        } catch (Throwable $failure) {
            $this->logger->error(
                'Projection {projection} failed after position {position}: {error}',
                [...$this->logContext(), 'error' => $failure->getMessage(), 'exception' => $failure],
            );

            throw $failure;
        } finally {
            $this->running = false;

            if (!$this->deleted) {
                $this->store->setStatus($this->name, ProjectionStatus::Idle);
            }

            $this->store->releaseLock($this->name);
        }
    }

    /**
     * Forgets the state and the position of the projection, so that it starts over from
     * the first event.
     */
    public function reset(): void
    {
        $this->position = Position::start();
        $this->state = $this->initialState();

        $this->onReset();

        $this->store->save($this->name, $this->position, $this->state);
        $this->store->setStatus($this->name, $this->running ? ProjectionStatus::Running : ProjectionStatus::Idle);
        $this->logger->notice('Projection {projection} was reset.', $this->logContext());
    }

    /**
     * Removes the projection and stops it if it is running.
     *
     * @param bool $deleteEmittedEvents also removes what the projection produced: the stream of emitted
     *                                  events for a {@see Projector}, the read model for a
     *                                  {@see ReadModelProjector}
     */
    public function delete(bool $deleteEmittedEvents = false): void
    {
        $this->store->delete($this->name);

        $this->onDelete($deleteEmittedEvents);

        $this->position = Position::start();
        $this->state = $this->initialState();
        $this->stopped = true;
        $this->deleted = true;

        $this->logger->notice(
            'Projection {projection} was deleted.',
            [...$this->logContext(), 'deleted_emitted_events' => $deleteEmittedEvents],
        );
    }

    /**
     * Returns the position of the last event handled.
     */
    public function position(): Position
    {
        return $this->position;
    }

    /**
     * Called when the projection starts running, before any event is handled.
     */
    protected function onStart(): void {}

    /**
     * Called at the end of each batch, before the checkpoint is saved.
     */
    protected function onPersist(): void {}

    /**
     * Called when the projection is reset.
     */
    protected function onReset(): void {}

    /**
     * Called when the projection is deleted.
     */
    protected function onDelete(bool $deleteEmittedEvents): void {}

    private function load(): void
    {
        $checkpoint = $this->store->find($this->name);

        if ($checkpoint === null) {
            $this->position = Position::start();
            $this->state = $this->initialState();
            $this->store->save($this->name, $this->position, $this->state);
        } else {
            $this->position = $checkpoint->position;
            $this->state = $checkpoint->state;
        }

        $this->onStart();
    }

    /**
     * Carries out what the {@see ProjectionManager} asked for from another process.
     *
     * @return bool `true` when the projection must stop running
     */
    private function applyRequestedStatus(): bool
    {
        switch ($this->store->find($this->name)?->status) {
            case ProjectionStatus::Stopping:
                $this->stopped = true;

                return true;

            case ProjectionStatus::Resetting:
                $this->reset();

                return false;

            case ProjectionStatus::Deleting:
                $this->delete(false);

                return true;

            case ProjectionStatus::DeletingWithEmittedEvents:
                $this->delete(true);

                return true;

            default:
                return false;
        }
    }

    /**
     * @param list<RecordedEvent> $events
     */
    private function handleBatch(array $events): void
    {
        foreach ($events as $event) {
            $this->handle($event);
            $this->position = $event->position;

            if ($this->stopped) {
                break;
            }
        }

        if ($this->deleted) {
            return;
        }

        $this->onPersist();
        $this->store->save($this->name, $this->position, $this->state);

        $this->logger->debug(
            'Projection {projection} handled {count} event(s), now at position {position}.',
            [...$this->logContext(), 'count' => count($events)],
        );
    }

    /**
     * @return array{projection: string, position: string}
     */
    private function logContext(): array
    {
        return ['projection' => $this->name, 'position' => (string) $this->position];
    }
}
