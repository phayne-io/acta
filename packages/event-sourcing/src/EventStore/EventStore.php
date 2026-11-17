<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Message\Envelope;

/**
 * Port of the event store: an append-only log of events grouped in streams.
 *
 * Compared with Prooph's `EventStore`, streams are implicit: a stream exists as soon as
 * it holds an event, so there is no `create()` and no stream metadata. Transactions are
 * a separate port, {@see \Acta\EventSourcing\TransactionManager}.
 */
interface EventStore extends ReadOnlyEventStore
{
    /**
     * Appends events at the end of a stream.
     *
     * The write is atomic: either every event is stored or none is.
     *
     * @return list<RecordedEvent> the events with their stream version and position
     *
     * @throws ConcurrencyConflict when the stream is not at the expected version
     */
    public function append(StreamName $stream, ExpectedVersion $expectedVersion, Envelope ...$events): array;

    /**
     * Removes a stream and all its events. Deleting a stream that does not exist is a
     * no-op.
     */
    public function delete(StreamName $stream): void;
}
