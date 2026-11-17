<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Bus\EventBus;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\TransactionManager;
use Override;

/**
 * Event store decorator that publishes appended events on an event bus.
 *
 * It is the counterpart of Prooph's `EventPublisher` from the event-store-bus-bridge:
 * listeners run in the same process as the write. There are two modes.
 *
 * - With a transaction manager, publication waits for the commit and never happens when
 *   the transaction rolls back. Use it for reactions to facts that are now durable. A
 *   reaction is lost if the process dies right after the commit: what must eventually
 *   happen should rather be driven by a persistent projection, which resumes from its
 *   last position.
 * - Without one, events are published as soon as they are appended, inside the
 *   transaction of the command being handled. A listener updating a read model on the
 *   same connection then commits or rolls back with the events: this is an inline
 *   projection, immediately consistent with the write, at the price of coupling the
 *   write to the read model. It is opt-in for that reason.
 */
final readonly class PublishingEventStore implements EventStore
{
    public function __construct(
        private EventStore $inner,
        private EventBus $eventBus,
        private ?TransactionManager $transactions = null,
    ) {}

    #[Override]
    public function append(StreamName $stream, ExpectedVersion $expectedVersion, Envelope ...$events): array
    {
        $recorded = $this->inner->append($stream, $expectedVersion, ...$events);

        if ($recorded === []) {
            return $recorded;
        }

        $publish = fn() => $this->eventBus->publish(
            ...array_map(static fn(RecordedEvent $event): Envelope => $event->envelope, $recorded),
        );

        if ($this->transactions === null) {
            $publish();
        } else {
            $this->transactions->afterCommit($publish);
        }

        return $recorded;
    }

    #[Override]
    public function delete(StreamName $stream): void
    {
        $this->inner->delete($stream);
    }

    #[Override]
    public function load(
        StreamName $stream,
        int $fromVersion = 1,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable {
        return $this->inner->load($stream, $fromVersion, $count, $matcher);
    }

    #[Override]
    public function loadReverse(
        StreamName $stream,
        ?int $fromVersion = null,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable {
        return $this->inner->loadReverse($stream, $fromVersion, $count, $matcher);
    }

    #[Override]
    public function read(Selector $selector, ?Position $after = null, ?int $count = null): iterable
    {
        return $this->inner->read($selector, $after, $count);
    }

    #[Override]
    public function hasStream(StreamName $stream): bool
    {
        return $this->inner->hasStream($stream);
    }

    #[Override]
    public function streamVersion(StreamName $stream): int
    {
        return $this->inner->streamVersion($stream);
    }

    #[Override]
    public function streamNames(?string $category = null, int $limit = 100, int $offset = 0): array
    {
        return $this->inner->streamNames($category, $limit, $offset);
    }

    #[Override]
    public function categoryNames(int $limit = 100, int $offset = 0): array
    {
        return $this->inner->categoryNames($limit, $offset);
    }
}
