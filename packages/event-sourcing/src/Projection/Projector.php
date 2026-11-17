<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\TransactionManager;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A persistent projection whose result is its state, and optionally new events.
 *
 * ```php
 * $projections->projection('accounts_opened')
 *     ->init(fn(): array => ['count' => 0])
 *     ->fromCategory('account')
 *     ->when([AccountOpened::class => fn(array $state): array => ['count' => $state['count'] + 1]])
 *     ->run(keepRunning: false);
 *
 * $projections->state('accounts_opened'); // ['count' => 12]
 * ```
 *
 * Handlers can also derive streams with {@see emit()} and {@see linkTo()}, through the
 * projection they receive as fourth argument.
 */
final class Projector extends PersistentProjection
{
    /**
     * @internal use {@see ProjectionManager::projection()}
     */
    public function __construct(
        string $name,
        EventStore $eventStore,
        ProjectionStore $store,
        ?TransactionManager $transactions,
        ProjectionOptions $options,
        private readonly EnvelopeFactory $envelopes,
        LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($name, $eventStore, $store, $transactions, $options, $logger);
    }

    /**
     * Appends a new event to the stream named after the projection.
     *
     * Make sure the projection does not read its own stream, or select events by name,
     * otherwise it would handle what it emits.
     */
    public function emit(object $event, ?Metadata $metadata = null): void
    {
        $this->eventStore->append(
            $this->emittedStream(),
            ExpectedVersion::any(),
            $this->envelopes->wrap($event, $metadata),
        );
    }

    /**
     * Copies an event to another stream, for instance to build one stream per customer
     * out of the events of several aggregates.
     *
     * The copy is a new event with its own identifier; its {@see Metadata::LINK} entry
     * points back to the original.
     */
    public function linkTo(StreamName|string $stream, RecordedEvent $event): void
    {
        $this->eventStore->append(
            $stream instanceof StreamName ? $stream : StreamName::fromString($stream),
            ExpectedVersion::any(),
            $this->envelopes->wrap($event->message, $event->metadata->with(Metadata::LINK, [
                'id' => $event->envelope->id->value,
                'stream' => $event->stream->value,
                'version' => $event->streamVersion,
            ])),
        );
    }

    #[Override]
    protected function onReset(): void
    {
        $this->eventStore->delete($this->emittedStream());
    }

    #[Override]
    protected function onDelete(bool $deleteEmittedEvents): void
    {
        if ($deleteEmittedEvents) {
            $this->eventStore->delete($this->emittedStream());
        }
    }

    private function emittedStream(): StreamName
    {
        return StreamName::fromString($this->name);
    }
}
