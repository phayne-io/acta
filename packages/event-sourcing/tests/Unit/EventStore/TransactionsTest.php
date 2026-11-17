<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\EventStore;

use Acta\EventSourcing\Bus\DefaultEventBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\PublishingEventStore;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\TransactionAborted;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Testing\Fixture\CounterIncremented;
use Acta\EventSourcing\Testing\Fixture\CounterStarted;
use ArrayObject;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionsTest extends TestCase
{
    private InMemoryEventStore $store;

    private EnvelopeFactory $envelopes;

    private StreamName $stream;

    #[Override]
    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->envelopes = new EnvelopeFactory();
        $this->stream = StreamName::for('counter', 'a');
    }

    public function testATransactionCommitsWhatWasAppended(): void
    {
        $result = $this->store->transactional(function (): string {
            $this->append(new CounterStarted('a'));
            self::assertTrue($this->store->inTransaction());

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertFalse($this->store->inTransaction());
        self::assertSame(1, $this->store->streamVersion($this->stream));
    }

    public function testAFailedTransactionLeavesTheStoreUntouched(): void
    {
        $this->append(new CounterStarted('a'));

        try {
            $this->store->transactional(function (): never {
                $this->append(new CounterIncremented('a'));
                $this->store->delete($this->stream);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        self::assertFalse($this->store->inTransaction());
        self::assertSame(1, $this->store->streamVersion($this->stream));
        self::assertCount(1, [...$this->store->read(Selector::all())]);

        // Positions keep increasing after a rollback.
        $next = $this->append(new CounterIncremented('a'));
        self::assertSame(2, $next->streamVersion);
    }

    public function testNestedTransactionsCommitWithTheOutermostOne(): void
    {
        $order = [];

        $this->store->transactional(function () use (&$order): void {
            $this->store->afterCommit(static function () use (&$order): void {
                $order[] = 'outer callback';
            });
            $this->store->transactional(function () use (&$order): void {
                $this->append(new CounterStarted('a'));
                $this->store->afterCommit(static function () use (&$order): void {
                    $order[] = 'inner callback';
                });
            });
            $order[] = 'inner done';
        });

        self::assertSame(['inner done', 'outer callback', 'inner callback'], $order);
        self::assertSame(1, $this->store->streamVersion($this->stream));
    }

    public function testAFailedNestedTransactionAbortsTheWholeTransaction(): void
    {
        $called = false;

        try {
            $this->store->transactional(function () use (&$called): void {
                $this->append(new CounterStarted('a'));
                $this->store->afterCommit(static function () use (&$called): void {
                    $called = true;
                });

                try {
                    $this->store->transactional(static fn() => throw new RuntimeException('inner'));
                } catch (RuntimeException) {
                    // Swallowing the failure does not save the transaction.
                }
            });
            self::fail('The outer transaction should have been aborted.');
        } catch (TransactionAborted) {
        }

        self::assertFalse($called);
        self::assertFalse($this->store->hasStream($this->stream));
    }

    public function testAfterCommitCallbacksRunImmediatelyOutsideATransaction(): void
    {
        $called = false;

        $this->store->afterCommit(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }

    public function testAppendedEventsArePublishedWithTheirEnvelope(): void
    {
        $published = [];
        $bus = new DefaultEventBus(new HandlerMap()->on(
            CounterStarted::class,
            static function (CounterStarted $event, Envelope $envelope) use (&$published): void {
                $published[] = $envelope;
            },
        ));
        $store = new PublishingEventStore($this->store, $bus);
        $envelope = $this->envelopes->wrap(new CounterStarted('a'), new Metadata(['tenant' => 'acme']));

        $recorded = $store->append($this->stream, ExpectedVersion::noStream(), $envelope);
        $store->append($this->stream, ExpectedVersion::any());

        self::assertSame([$envelope], $published);
        self::assertSame(1, $recorded[0]->streamVersion);
        self::assertTrue($store->hasStream($this->stream));
        self::assertSame(1, $store->streamVersion($this->stream));
        self::assertCount(1, [...$store->load($this->stream)]);
        self::assertCount(1, [...$store->loadReverse($this->stream)]);
        self::assertCount(1, [...$store->read(Selector::all())]);
        self::assertEquals([$this->stream], $store->streamNames());
        self::assertSame(['counter'], $store->categoryNames());

        $store->delete($this->stream);

        self::assertFalse($this->store->hasStream($this->stream));
    }

    public function testPublicationWaitsForTheCommitAndIsSkippedOnRollback(): void
    {
        /** @var ArrayObject<int, object> $published */
        $published = new ArrayObject();
        $bus = new DefaultEventBus(new HandlerMap()->on(
            CounterStarted::class,
            static function (CounterStarted $event) use ($published): void {
                $published->append($event);
            },
        ));
        $store = new PublishingEventStore($this->store, $bus, $this->store);

        $this->store->transactional(function () use ($store, $published): void {
            $store->append($this->stream, ExpectedVersion::noStream(), $this->envelopes->wrap(new CounterStarted('a')));
            self::assertCount(0, $published);
        });

        self::assertCount(1, $published);

        try {
            $this->store->transactional(function () use ($store): never {
                $store->append(StreamName::for('counter', 'b'), ExpectedVersion::noStream(), $this->envelopes->wrap(new CounterStarted('b')));

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        self::assertCount(1, $published);
    }

    private function append(object $event): RecordedEvent
    {
        return $this->store->append($this->stream, ExpectedVersion::any(), $this->envelopes->wrap($event))[0];
    }
}
