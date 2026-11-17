<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Postgres;

use Acta\EventSourcing\Postgres\PostgresProjectionStore;
use Acta\EventSourcing\Projection\ProjectionStore;
use Acta\EventSourcing\Testing\ProjectionStoreContract;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[Group('postgres')]
#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresProjectionStoreTest extends ProjectionStoreContract
{
    use ConnectsToPostgres;

    #[Override]
    protected function createProjectionStore(): ProjectionStore
    {
        return new PostgresProjectionStore(self::connectToFreshSchema());
    }

    #[Override]
    protected function createCompetingProjectionStore(): ProjectionStore
    {
        // Advisory locks belong to a connection: a second one stands for another process.
        return new PostgresProjectionStore(self::connect());
    }

    public function testALockIsReleasedWhenItsConnectionCloses(): void
    {
        $crashed = new PostgresProjectionStore(self::connect());

        self::assertTrue($crashed->acquireLock('totals'));
        self::assertFalse($this->store->acquireLock('totals'));

        unset($crashed);

        self::assertTrue($this->store->acquireLock('totals'));

        $this->store->releaseLock('totals');
    }
}
