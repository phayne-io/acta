<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Snapshot;

use Acta\EventSourcing\Snapshot\InMemorySnapshotStore;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Acta\EventSourcing\Testing\SnapshotStoreContract;
use Override;

final class InMemorySnapshotStoreTest extends SnapshotStoreContract
{
    #[Override]
    protected function createSnapshotStore(): SnapshotStore
    {
        return new InMemorySnapshotStore();
    }
}
