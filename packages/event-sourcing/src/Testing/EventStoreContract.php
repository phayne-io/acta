<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\MetadataMatcher;
use Acta\EventSourcing\EventStore\Operator;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Testing\Fixture\CounterIncremented;
use Acta\EventSourcing\Testing\Fixture\CounterStarted;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Contract every {@see EventStore} adapter must honour.
 *
 * Extend it in the test suite of your adapter and implement {@see createEventStore()};
 * the in-memory and PostgreSQL adapters of the library are verified with it.
 */
abstract class EventStoreContract extends TestCase
{
    protected EventStore $store;

    protected EnvelopeFactory $envelopes;

    /**
     * Returns an empty event store.
     */
    abstract protected function createEventStore(): EventStore;

    /**
     * Returns the type map the adapter under test must use to resolve the fixture events.
     */
    final protected static function messageTypes(): MessageTypeMap
    {
        return new MessageTypeMap(CounterStarted::class, CounterIncremented::class);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->envelopes = new EnvelopeFactory(self::messageTypes());
        $this->store = $this->createEventStore();
    }

    public function testItReturnsAppendedEventsWithTheirVersionAndPosition(): void
    {
        $stream = StreamName::for('counter', 'a');

        $first = $this->store->append($stream, ExpectedVersion::noStream(), $this->event(new CounterStarted('a')));
        $next = $this->store->append(
            $stream,
            ExpectedVersion::exactly(1),
            $this->event(new CounterIncremented('a')),
            $this->event(new CounterIncremented('a', 2)),
        );

        self::assertCount(1, $first);
        self::assertSame(1, $first[0]->streamVersion);
        self::assertSame([2, 3], array_map(static fn(RecordedEvent $event): int => $event->streamVersion, $next));
        self::assertTrue($next[0]->position->isAfter($first[0]->position));
        self::assertTrue($next[1]->position->isAfter($next[0]->position));
        self::assertTrue($next[1]->stream->equals($stream));
    }

    public function testAppendingNothingIsANoOp(): void
    {
        self::assertSame([], $this->store->append(StreamName::for('counter', 'a'), ExpectedVersion::any()));
        self::assertFalse($this->store->hasStream(StreamName::for('counter', 'a')));
    }

    public function testItPreservesTheEnvelopeOfAnEvent(): void
    {
        $stream = StreamName::for('counter', 'a');
        $envelope = $this->event(
            new CounterIncremented('a', 7),
            ['tenant' => 'acme', 'attempt' => 2, 'ratio' => 1.5, 'flags' => ['x' => true, 'y' => null], 'label' => 'été/1'],
        );

        $this->store->append($stream, ExpectedVersion::any(), $envelope);

        $loaded = [...$this->store->load($stream)];

        self::assertCount(1, $loaded);
        self::assertEquals($envelope->message, $loaded[0]->message);
        self::assertTrue($envelope->id->equals($loaded[0]->envelope->id));
        self::assertSame($envelope->name, $loaded[0]->name);
        self::assertEquals($envelope->createdAt, $loaded[0]->envelope->createdAt);
        self::assertEquals($envelope->metadata->values, $loaded[0]->metadata->values);
    }

    public function testItStoresEventsUnderTheirPublicName(): void
    {
        $stream = StreamName::for('counter', 'a');
        $this->store->append($stream, ExpectedVersion::any(), $this->event(new CounterStarted('a')));

        self::assertSame('acta.testing.counter-started', [...$this->store->load($stream)][0]->name);
    }

    public function testItLoadsAStreamInOrder(): void
    {
        $stream = $this->counter('a', increments: 4);
        $this->counter('b', increments: 2);

        self::assertSame([1, 2, 3, 4, 5], self::versions($this->store->load($stream)));
        self::assertSame([3, 4, 5], self::versions($this->store->load($stream, 3)));
        self::assertSame([2, 3], self::versions($this->store->load($stream, 2, 2)));
        self::assertSame([], self::versions($this->store->load($stream, 6)));
    }

    public function testItLoadsAStreamBackwards(): void
    {
        $stream = $this->counter('a', increments: 4);

        self::assertSame([5, 4, 3, 2, 1], self::versions($this->store->loadReverse($stream)));
        self::assertSame([3, 2, 1], self::versions($this->store->loadReverse($stream, 3)));
        self::assertSame([5, 4], self::versions($this->store->loadReverse($stream, null, 2)));
    }

    public function testLoadingAnUnknownStreamYieldsNothing(): void
    {
        $unknown = StreamName::for('counter', 'unknown');

        self::assertSame([], [...$this->store->load($unknown)]);
        self::assertSame([], [...$this->store->loadReverse($unknown)]);
        self::assertFalse($this->store->hasStream($unknown));
        self::assertSame(0, $this->store->streamVersion($unknown));
    }

    public function testItFiltersAStreamOnMetadata(): void
    {
        $stream = StreamName::for('counter', 'a');
        $this->store->append(
            $stream,
            ExpectedVersion::any(),
            $this->event(new CounterStarted('a'), ['tenant' => 'acme', 'attempt' => 1]),
            $this->event(new CounterIncremented('a'), ['tenant' => 'globex', 'attempt' => 2]),
            $this->event(new CounterIncremented('a'), ['tenant' => 'acme', 'attempt' => 3]),
            $this->event(new CounterIncremented('a')),
        );

        $load = fn(MetadataMatcher $matcher): array => self::versions($this->store->load($stream, matcher: $matcher));

        self::assertSame([1, 3], $load(MetadataMatcher::where('tenant', Operator::Equals, 'acme')));
        self::assertSame([2, 4], $load(MetadataMatcher::where('tenant', Operator::NotEquals, 'acme')));
        self::assertSame([4], $load(MetadataMatcher::where('tenant', Operator::Equals, null)));
        self::assertSame([2, 3], $load(MetadataMatcher::where('attempt', Operator::GreaterThan, 1)));
        self::assertSame([1, 2], $load(MetadataMatcher::where('attempt', Operator::LowerThanOrEqual, 2)));
        self::assertSame([1, 2, 3], $load(MetadataMatcher::where('tenant', Operator::In, ['acme', 'globex'])));
        self::assertSame([2, 4], $load(MetadataMatcher::where('tenant', Operator::NotIn, ['acme'])));
        self::assertSame([2], $load(MetadataMatcher::where('tenant', Operator::Regex, '^glob')));
        self::assertSame([3], $load(
            MetadataMatcher::where('tenant', Operator::Equals, 'acme')->and('attempt', Operator::GreaterThanOrEqual, 2),
        ));
        self::assertSame([3, 1], self::versions(
            $this->store->loadReverse($stream, matcher: MetadataMatcher::where('tenant', Operator::Equals, 'acme')),
        ));
    }

    public function testItRefusesAnAppendWhenTheStreamIsNotAtTheExpectedVersion(): void
    {
        $stream = $this->counter('a', increments: 1);

        foreach ([ExpectedVersion::noStream(), ExpectedVersion::exactly(1), ExpectedVersion::exactly(3)] as $expected) {
            try {
                $this->store->append($stream, $expected, $this->event(new CounterIncremented('a')));
                self::fail(sprintf('Appending with "%s" expected should have been refused.', $expected->describe()));
            } catch (ConcurrencyConflict $conflict) {
                self::assertStringContainsString('counter-a', $conflict->getMessage());
            }
        }

        self::assertSame(2, $this->store->streamVersion($stream));
    }

    public function testItRefusesAnAppendToAMissingStreamExpectedToExist(): void
    {
        $this->expectException(ConcurrencyConflict::class);

        $this->store->append(
            StreamName::for('counter', 'missing'),
            ExpectedVersion::streamExists(),
            $this->event(new CounterIncremented('missing')),
        );
    }

    public function testItAppendsWhateverTheVersionWhenAnyIsExpected(): void
    {
        $stream = $this->counter('a', increments: 1);

        $this->store->append($stream, ExpectedVersion::any(), $this->event(new CounterIncremented('a')));
        $this->store->append($stream, ExpectedVersion::streamExists(), $this->event(new CounterIncremented('a')));
        $this->store->append($stream, ExpectedVersion::exactly(4), $this->event(new CounterIncremented('a')));

        self::assertSame(5, $this->store->streamVersion($stream));
    }

    public function testItReadsEveryEventInTheOrderTheyWereAppended(): void
    {
        $this->store->append(StreamName::for('counter', 'a'), ExpectedVersion::any(), $this->event(new CounterStarted('a')));
        $this->store->append(StreamName::for('counter', 'b'), ExpectedVersion::any(), $this->event(new CounterStarted('b')));
        $this->store->append(StreamName::for('counter', 'a'), ExpectedVersion::any(), $this->event(new CounterIncremented('a')));
        $this->store->append(StreamName::for('gauge', 'c'), ExpectedVersion::any(), $this->event(new CounterStarted('c')));

        $events = [...$this->store->read(Selector::all())];

        self::assertSame(['counter-a', 'counter-b', 'counter-a', 'gauge-c'], self::streams($events));

        for ($i = 1; $i < count($events); $i++) {
            self::assertTrue($events[$i]->position->isAfter($events[$i - 1]->position));
        }
    }

    public function testItResumesReadingAfterAPosition(): void
    {
        $this->counter('a', increments: 2);
        $this->counter('b', increments: 2);

        $firstBatch = [...$this->store->read(Selector::all(), count: 4)];
        $secondBatch = [...$this->store->read(Selector::all(), $firstBatch[3]->position, 4)];
        $thirdBatch = [...$this->store->read(Selector::all(), $secondBatch[1]->position)];

        self::assertCount(4, $firstBatch);
        self::assertSame(['counter-b', 'counter-b'], self::streams($secondBatch));
        self::assertSame([], $thirdBatch);
    }

    public function testItReadsASelectionOfTheLog(): void
    {
        $this->counter('a', increments: 1);
        $this->counter('b', increments: 1);
        $this->store->append(
            StreamName::for('gauge', 'c'),
            ExpectedVersion::any(),
            $this->event(new CounterStarted('c'), ['tenant' => 'acme']),
        );

        $read = fn(Selector $selector): array => self::streams($this->store->read($selector));

        self::assertSame(['counter-a', 'counter-a', 'counter-b', 'counter-b'], $read(Selector::category('counter')));
        self::assertSame(['gauge-c'], $read(Selector::category('gauge')));
        self::assertCount(5, $read(Selector::category('counter', 'gauge')));
        self::assertSame(['counter-b', 'counter-b'], $read(Selector::stream('counter-b')));
        self::assertSame(['counter-a', 'counter-a', 'gauge-c'], $read(Selector::stream('counter-a')->orCategory('gauge')));
        self::assertSame([], $read(Selector::category('unknown')));
        self::assertSame(
            ['counter-a', 'counter-b', 'gauge-c'],
            $read(Selector::all()->named('acta.testing.counter-started')),
        );
        self::assertSame(
            ['counter-a', 'counter-b'],
            $read(Selector::category('counter')->named(CounterIncremented::class)),
        );
        self::assertSame(
            ['gauge-c'],
            $read(Selector::all()->matching(MetadataMatcher::where('tenant', Operator::Equals, 'acme'))),
        );
    }

    public function testItDeletesAStream(): void
    {
        $deleted = $this->counter('a', increments: 2);
        $kept = $this->counter('b', increments: 1);

        $this->store->delete($deleted);
        $this->store->delete(StreamName::for('counter', 'unknown'));

        self::assertFalse($this->store->hasStream($deleted));
        self::assertSame(0, $this->store->streamVersion($deleted));
        self::assertSame([], [...$this->store->load($deleted)]);
        self::assertTrue($this->store->hasStream($kept));
        self::assertSame(['counter-b', 'counter-b'], self::streams($this->store->read(Selector::all())));
    }

    public function testItListsStreamsAndCategories(): void
    {
        $this->counter('b', increments: 0);
        $this->counter('a', increments: 1);
        $this->store->append(StreamName::for('gauge', 'c'), ExpectedVersion::any(), $this->event(new CounterStarted('c')));
        $this->store->append(StreamName::fromString('audit'), ExpectedVersion::any(), $this->event(new CounterStarted('x')));

        self::assertSame(['audit', 'counter-a', 'counter-b', 'gauge-c'], self::names($this->store->streamNames()));
        self::assertSame(['counter-a', 'counter-b'], self::names($this->store->streamNames('counter')));
        self::assertSame(['counter-b', 'gauge-c'], self::names($this->store->streamNames(limit: 2, offset: 2)));
        self::assertSame(['audit', 'counter', 'gauge'], $this->store->categoryNames());
        self::assertSame(['counter'], $this->store->categoryNames(1, 1));
    }

    /**
     * Wraps a fixture event in an envelope.
     *
     * @param array<string, mixed> $metadata
     */
    final protected function event(object $event, array $metadata = []): Envelope
    {
        return $this->envelopes->wrap($event, new Metadata($metadata));
    }

    /**
     * Appends a started counter followed by a number of increments.
     */
    final protected function counter(string $id, int $increments): StreamName
    {
        $stream = StreamName::for('counter', $id);

        $this->store->append(
            $stream,
            ExpectedVersion::noStream(),
            $this->event(new CounterStarted($id)),
            ...array_map(fn(): Envelope => $this->event(new CounterIncremented($id)), array_fill(0, $increments, null)),
        );

        return $stream;
    }

    /**
     * @param iterable<RecordedEvent> $events
     *
     * @return list<int>
     */
    final protected static function versions(iterable $events): array
    {
        return array_map(
            static fn(RecordedEvent $event): int => $event->streamVersion,
            iterator_to_array($events, false),
        );
    }

    /**
     * @param iterable<RecordedEvent> $events
     *
     * @return list<string>
     */
    final protected static function streams(iterable $events): array
    {
        return array_map(
            static fn(RecordedEvent $event): string => $event->stream->value,
            iterator_to_array($events, false),
        );
    }

    /**
     * @param list<StreamName> $streams
     *
     * @return list<string>
     */
    private static function names(array $streams): array
    {
        return array_map(static fn(StreamName $stream): string => $stream->value, $streams);
    }
}
