<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

/**
 * Read side of the event store port.
 *
 * Depend on it wherever events are only consumed (projections, queries, tooling), so
 * that the code cannot append or delete by accident.
 */
interface ReadOnlyEventStore
{
    /**
     * Reads one stream in the order its events were appended.
     *
     * A stream that does not exist yields nothing.
     *
     * @param int      $fromVersion first stream version to return, 1 being the first event
     * @param int|null $count       maximum number of events, `null` for the whole stream
     *
     * @return iterable<RecordedEvent>
     */
    public function load(
        StreamName $stream,
        int $fromVersion = 1,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable;

    /**
     * Reads one stream from its end towards its beginning.
     *
     * @param int|null $fromVersion highest stream version to return, `null` for the last event
     * @param int|null $count       maximum number of events, `null` for the whole stream
     *
     * @return iterable<RecordedEvent>
     */
    public function loadReverse(
        StreamName $stream,
        ?int $fromVersion = null,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable;

    /**
     * Reads the global log in commit order, across streams.
     *
     * This is what projections and subscribers use. The store guarantees that an event
     * returned here is never preceded, later on, by an event the reader has not seen.
     *
     * @param Position|null $after last position already handled, `null` to start from the beginning
     * @param int|null      $count maximum number of events, `null` for everything available
     *
     * @return iterable<RecordedEvent>
     */
    public function read(Selector $selector, ?Position $after = null, ?int $count = null): iterable;

    /**
     * Tells whether the stream holds at least one event.
     */
    public function hasStream(StreamName $stream): bool;

    /**
     * Returns the version of the last event of the stream, 0 when it does not exist.
     */
    public function streamVersion(StreamName $stream): int;

    /**
     * Lists stream names in alphabetical order.
     *
     * @param string|null $category restricts the list to one category
     *
     * @return list<StreamName>
     */
    public function streamNames(?string $category = null, int $limit = 100, int $offset = 0): array;

    /**
     * Lists the categories that hold at least one stream, in alphabetical order.
     *
     * @return list<string>
     */
    public function categoryNames(int $limit = 100, int $offset = 0): array;
}
