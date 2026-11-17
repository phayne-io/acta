<?php

declare(strict_types=1);

namespace Acta\EventSourcing\EventStore;

use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\NestedTransactions;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Serializer\SerializedMessage;
use Acta\EventSourcing\TransactionManager;
use Override;

/**
 * Event store keeping everything in PHP arrays.
 *
 * It is meant for tests and prototypes and honours the same contract as the PostgreSQL
 * adapter, transactions included. Pass a {@see MessageSerializer} to store events in
 * their serialized form: reads then go through deserialization and upcasting exactly as
 * they would with a real database, which makes serialization mistakes visible in unit
 * tests.
 *
 * @phpstan-type Entry array{stream: StreamName, version: int, sequence: int, name: string, metadata: Metadata, envelope: Envelope|null, serialized: SerializedMessage|null}
 */
final class InMemoryEventStore implements EventStore, TransactionManager
{
    use NestedTransactions;

    /** @var list<Entry> */
    private array $log = [];

    /** @var array<string, int> */
    private array $versions = [];

    private int $sequence = 0;

    /** @var array{log: list<Entry>, versions: array<string, int>, sequence: int}|null */
    private ?array $backup = null;

    public function __construct(
        private readonly ?MessageSerializer $serializer = null,
    ) {}

    #[Override]
    public function append(StreamName $stream, ExpectedVersion $expectedVersion, Envelope ...$events): array
    {
        if ($events === []) {
            return [];
        }

        $version = $this->streamVersion($stream);

        if (!$expectedVersion->isSatisfiedBy($version)) {
            throw ConcurrencyConflict::versionMismatch($stream, $expectedVersion, $version);
        }

        $events = array_values($events);
        $serialized = array_map(
            fn(Envelope $envelope): ?SerializedMessage => $this->serializer?->serialize($envelope),
            $events,
        );

        $recorded = [];

        foreach ($events as $index => $envelope) {
            $this->log[] = [
                'stream' => $stream,
                'version' => ++$version,
                'sequence' => ++$this->sequence,
                'name' => $envelope->name,
                'metadata' => $envelope->metadata,
                'envelope' => $serialized[$index] === null ? $envelope : null,
                'serialized' => $serialized[$index],
            ];

            $recorded[] = new RecordedEvent($envelope, $stream, $version, new Position(0, $this->sequence));
        }

        $this->versions[$stream->value] = $version;

        return $recorded;
    }

    #[Override]
    public function delete(StreamName $stream): void
    {
        $this->log = array_values(array_filter(
            $this->log,
            static fn(array $entry): bool => !$entry['stream']->equals($stream),
        ));

        unset($this->versions[$stream->value]);
    }

    #[Override]
    public function load(
        StreamName $stream,
        int $fromVersion = 1,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable {
        $entries = array_filter(
            $this->log,
            static fn(array $entry): bool => $entry['stream']->equals($stream)
                && $entry['version'] >= $fromVersion
                && ($matcher === null || $matcher->matches($entry['metadata'])),
        );

        yield from $this->materialize($entries, $count);
    }

    #[Override]
    public function loadReverse(
        StreamName $stream,
        ?int $fromVersion = null,
        ?int $count = null,
        ?MetadataMatcher $matcher = null,
    ): iterable {
        $entries = array_filter(
            $this->log,
            static fn(array $entry): bool => $entry['stream']->equals($stream)
                && ($fromVersion === null || $entry['version'] <= $fromVersion)
                && ($matcher === null || $matcher->matches($entry['metadata'])),
        );

        yield from $this->materialize(array_reverse($entries), $count);
    }

    #[Override]
    public function read(Selector $selector, ?Position $after = null, ?int $count = null): iterable
    {
        $after ??= Position::start();

        $entries = array_filter(
            $this->log,
            static fn(array $entry): bool => new Position(0, $entry['sequence'])->isAfter($after)
                && $selector->accepts($entry['stream'], $entry['name'], $entry['metadata']),
        );

        yield from $this->materialize($entries, $count);
    }

    #[Override]
    public function hasStream(StreamName $stream): bool
    {
        return isset($this->versions[$stream->value]);
    }

    #[Override]
    public function streamVersion(StreamName $stream): int
    {
        return $this->versions[$stream->value] ?? 0;
    }

    #[Override]
    public function streamNames(?string $category = null, int $limit = 100, int $offset = 0): array
    {
        $names = array_map(
            static fn(string|int $name): StreamName => StreamName::fromString((string) $name),
            array_keys($this->versions),
        );

        if ($category !== null) {
            $names = array_filter($names, static fn(StreamName $name): bool => $name->category === $category);
        }

        usort($names, static fn(StreamName $a, StreamName $b): int => $a->value <=> $b->value);

        return array_slice($names, $offset, $limit);
    }

    #[Override]
    public function categoryNames(int $limit = 100, int $offset = 0): array
    {
        $categories = array_unique(array_map(
            static fn(string|int $name): string => StreamName::fromString((string) $name)->category,
            array_keys($this->versions),
        ));

        sort($categories);

        return array_slice($categories, $offset, $limit);
    }

    /**
     * Turns stored entries back into recorded events, upcasting them when the store
     * keeps events serialized.
     *
     * @param array<Entry> $entries
     * @param int|null     $count   maximum number of stored events to return
     *
     * @return iterable<RecordedEvent>
     */
    private function materialize(array $entries, ?int $count): iterable
    {
        if ($count !== null) {
            $entries = array_slice($entries, 0, max(0, $count));
        }

        foreach ($entries as $entry) {
            $envelopes = $entry['serialized'] !== null && $this->serializer !== null
                ? $this->serializer->deserialize($entry['serialized'])
                : array_filter([$entry['envelope']]);

            foreach ($envelopes as $envelope) {
                yield new RecordedEvent(
                    $envelope,
                    $entry['stream'],
                    $entry['version'],
                    new Position(0, $entry['sequence']),
                );
            }
        }
    }

    private function beginTransaction(): void
    {
        $this->backup = ['log' => $this->log, 'versions' => $this->versions, 'sequence' => $this->sequence];
    }

    private function commitTransaction(): void
    {
        $this->backup = null;
    }

    private function rollBackTransaction(): void
    {
        if ($this->backup !== null) {
            ['log' => $this->log, 'versions' => $this->versions, 'sequence' => $this->sequence] = $this->backup;
        }

        $this->backup = null;
    }
}
