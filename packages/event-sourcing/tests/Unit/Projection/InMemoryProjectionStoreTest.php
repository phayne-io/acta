<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Projection;

use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionStore;
use Acta\EventSourcing\Testing\ProjectionStoreContract;
use Override;

final class InMemoryProjectionStoreTest extends ProjectionStoreContract
{
    #[Override]
    protected function createProjectionStore(): ProjectionStore
    {
        return new InMemoryProjectionStore();
    }

    #[Override]
    protected function createCompetingProjectionStore(): ProjectionStore
    {
        return $this->store;
    }
}
