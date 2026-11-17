<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\EventStore;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Serializer\SerializedMessage;
use Acta\EventSourcing\Serializer\Upcaster;
use Acta\EventSourcing\Testing\EventStoreContract;
use Acta\EventSourcing\Testing\Fixture\CounterIncremented;
use Acta\EventSourcing\Testing\Fixture\CounterStarted;
use Override;

/**
 * Runs the event store contract against the in-memory adapter storing serialized events,
 * and checks that reads go through upcasting.
 */
final class SerializingInMemoryEventStoreTest extends EventStoreContract
{
    #[Override]
    protected function createEventStore(): EventStore
    {
        return new InMemoryEventStore(new MessageSerializer(self::messageTypes()));
    }

    public function testStoredEventsAreUpcastWhenRead(): void
    {
        // Doubles every increment and splits increments of 4 or more in two events.
        $upcaster = new class implements Upcaster {
            public function upcast(SerializedMessage $message): iterable
            {
                if ($message->name !== CounterIncremented::class) {
                    return [$message];
                }

                $by = $message->payload['by'] * 2;

                return $by < 8
                    ? [$message->withPayload([...$message->payload, 'by' => $by])]
                    : [
                        $message->withPayload([...$message->payload, 'by' => $by - 1]),
                        $message->withPayload([...$message->payload, 'by' => 1]),
                    ];
            }
        };
        $store = new InMemoryEventStore(new MessageSerializer(self::messageTypes(), upcaster: $upcaster));
        $stream = StreamName::for('counter', 'a');

        $store->append(
            $stream,
            ExpectedVersion::noStream(),
            $this->event(new CounterStarted('a')),
            $this->event(new CounterIncremented('a', 1)),
            $this->event(new CounterIncremented('a', 4)),
        );

        $loaded = [...$store->load($stream)];
        $read = [...$store->read(Selector::all(), count: 3)];

        self::assertEquals(
            [new CounterStarted('a'), new CounterIncremented('a', 2), new CounterIncremented('a', 7), new CounterIncremented('a', 1)],
            array_map(static fn($event) => $event->message, $loaded),
        );
        // Events born from the same stored event share its version and its position.
        self::assertSame([1, 2, 3, 3], self::versions($loaded));
        self::assertTrue($loaded[2]->position->equals($loaded[3]->position));
        // The count applies to stored events: a split event is never cut in the middle.
        self::assertCount(4, $read);
    }
}
