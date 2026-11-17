<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Postgres;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Postgres\PostgresSnapshotStore;
use Acta\EventSourcing\Snapshot\Snapshot;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Acta\EventSourcing\Testing\Fixture\Counter;
use Acta\EventSourcing\Testing\SnapshotStoreContract;
use Acta\EventSourcing\Tests\Fixture\RecordingLogger;
use DateTimeImmutable;
use Override;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\LogLevel;

#[Group('postgres')]
#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresSnapshotStoreTest extends SnapshotStoreContract
{
    use ConnectsToPostgres;

    private ?Pgsql $pdo = null;

    #[Override]
    protected function createSnapshotStore(): SnapshotStore
    {
        $this->pdo = self::connectToFreshSchema();

        return new PostgresSnapshotStore($this->pdo);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    public function testTheSerializationFormatCanBeReplaced(): void
    {
        self::assertNotNull($this->pdo);

        $store = new PostgresSnapshotStore(
            $this->pdo,
            serialize: static fn(AggregateRoot $aggregate): string => (string) gzdeflate(serialize($aggregate)),
            unserialize: static fn(string $binary): mixed => unserialize((string) gzinflate($binary)),
        );
        $counter = Counter::start('zipped');

        $store->save(new Snapshot('counter', 'zipped', $counter, 1, new DateTimeImmutable()));

        self::assertInstanceOf(Counter::class, $store->get('counter', 'zipped')?->aggregate);
        // The default store cannot read the compressed form: no snapshot, and a warning.
        $logger = new RecordingLogger();

        self::assertNull(new PostgresSnapshotStore($this->pdo, logger: $logger)->get('counter', 'zipped'));
        self::assertSame(
            ['The snapshot of counter zipped cannot be read and is ignored.'],
            $logger->messages(LogLevel::WARNING),
        );
        self::assertNull(new PostgresSnapshotStore($this->pdo, logger: $logger)->get('counter', 'unknown'));
        self::assertCount(1, $logger->records);
    }
}
