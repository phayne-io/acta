<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\EventStore;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Testing\EventStoreContract;
use Override;

/**
 * Runs the event store contract against the in-memory adapter keeping event objects.
 */
final class InMemoryEventStoreTest extends EventStoreContract
{
    #[Override]
    protected function createEventStore(): EventStore
    {
        return new InMemoryEventStore();
    }
}
