<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Postgres;

use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Postgres\NotificationWaiter;
use Acta\EventSourcing\Postgres\PostgresEventStore;
use Acta\EventSourcing\Postgres\PostgresSchema;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Testing\EventStoreContract;
use Acta\EventSourcing\Testing\Fixture\CounterIncremented;
use Acta\EventSourcing\Testing\Fixture\CounterStarted;
use Override;
use PDO;
use Pdo\Pgsql;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Runs the event store contract against PostgreSQL, plus what only a real database can
 * show: concurrent writers, commit ordering, notifications.
 */
#[Group('postgres')]
#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresEventStoreTest extends EventStoreContract
{
    use ConnectsToPostgres;

    private ?Pgsql $pdo = null;

    #[Override]
    protected function createEventStore(): EventStore
    {
        $this->pdo = self::connectToFreshSchema();

        return $this->storeOn($this->pdo);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    public function testEventsAreStoredAsJsonObjects(): void
    {
        $this->store->append(
            StreamName::for('counter', 'a'),
            ExpectedVersion::noStream(),
            $this->event(new CounterStarted('a')),
            $this->event(new CounterIncremented('a', 3), ['tenant' => 'acme']),
        );

        $statement = $this->pdo()->prepare(
            'SELECT stream_category, stream_version, event_name, schema_version, payload::text AS payload, '
            . 'metadata::text AS metadata, jsonb_typeof(metadata) AS metadata_type FROM event_store ORDER BY position',
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame('counter', $rows[0]['stream_category']);
        self::assertSame('acta.testing.counter-started', $rows[0]['event_name']);
        self::assertSame('{}', $rows[0]['metadata']);
        self::assertSame('object', $rows[0]['metadata_type']);
        self::assertEquals(['counterId' => 'a', 'by' => 3], json_decode($rows[1]['payload'], true));
        self::assertSame(['tenant' => 'acme'], json_decode($rows[1]['metadata'], true));
        self::assertSame(2, $rows[1]['stream_version']);
        self::assertSame(1, $rows[1]['schema_version']);
    }

    public function testLongStreamsAreReadPageByPage(): void
    {
        $stream = StreamName::for('counter', 'long');
        $events = array_map(fn(int $by): Envelope => $this->event(new CounterIncremented('long', $by)), range(1, 2500));

        $this->store->append($stream, ExpectedVersion::noStream(), ...$events);

        self::assertSame(range(1, 2500), self::versions($this->store->load($stream)));
        self::assertSame(range(2500, 1), self::versions($this->store->loadReverse($stream)));
        self::assertSame(range(1200, 2300), self::versions($this->store->load($stream, 1200, 1101)));
        self::assertCount(2500, [...$this->store->read(Selector::category('counter'))]);
        self::assertCount(1500, [...$this->store->read(Selector::all(), count: 1500)]);
    }

    public function testAWriterLosingARaceGetsAConcurrencyConflict(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The pcntl extension is required to simulate two concurrent writers.');
        }

        $stream = $this->counter('a', increments: 0);
        // Connections do not survive a fork: each process opens its own.
        $this->pdo = null;
        $this->store = new InMemoryEventStore();

        $pid = pcntl_fork();

        if ($pid === 0) {
            // First writer: appends version 2 and keeps its transaction open for a while.
            $pdo = self::connect();
            $pdo->beginTransaction();
            $this->storeOn($pdo)->append($stream, ExpectedVersion::exactly(1), $this->event(new CounterIncremented('a', 1)));
            usleep(400_000);
            $pdo->commit();
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Second writer: reads version 1 while the first transaction is still open, then
        // blocks on the unique constraint until that transaction commits.
        usleep(150_000);
        $this->pdo = self::connect();
        $this->store = $this->storeOn($this->pdo);
        $conflict = null;

        try {
            $this->store->append($stream, ExpectedVersion::exactly(1), $this->event(new CounterIncremented('a', 2)));
        } catch (ConcurrencyConflict $exception) {
            $conflict = $exception;
        } finally {
            pcntl_waitpid($pid, $status);
        }

        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertInstanceOf(PDOException::class, $conflict->getPrevious());
        self::assertSame([1, 2], self::versions($this->store->load($stream)));
        self::assertEquals(new CounterIncremented('a', 1), [...$this->store->load($stream, 2)][0]->message);
    }

    public function testAReaderNeverSkipsAnEventCommittedLate(): void
    {
        $slow = self::connect();
        $slowStore = $this->storeOn($slow);

        // The slow transaction gets its transaction id first...
        $slow->beginTransaction();
        $slow->exec('SELECT pg_current_xact_id()');

        // ...a fast one appends and commits in the meantime...
        $this->counter('fast', increments: 0);

        // ...then the slow one appends: later sequence number, earlier transaction.
        $slowStore->append(StreamName::for('counter', 'slow'), ExpectedVersion::noStream(), $this->event(new CounterStarted('slow')));

        // While the slow transaction is open, the fast event is held back: returning it now
        // would let a projection move past the slow event before it becomes visible.
        self::assertSame([], [...$this->store->read(Selector::all())]);
        self::assertTrue($this->store->hasStream(StreamName::for('counter', 'fast')));

        $slow->commit();

        $events = [...$this->store->read(Selector::all())];

        self::assertSame(['counter-slow', 'counter-fast'], self::streams($events));
        self::assertTrue($events[1]->position->isAfter($events[0]->position));
        self::assertGreaterThan($events[1]->position->sequence, $events[0]->position->sequence);
        self::assertSame(['counter-fast'], self::streams($this->store->read(Selector::all(), $events[0]->position)));
    }

    public function testAnAppendIsAtomic(): void
    {
        $duplicate = $this->event(new CounterIncremented('a'));

        try {
            $this->store->append(
                StreamName::for('counter', 'a'),
                ExpectedVersion::noStream(),
                $this->event(new CounterStarted('a')),
                $duplicate,
                $duplicate,
            );
            self::fail('Two events cannot share an identifier.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('event_id', $exception->getMessage());
        }

        self::assertFalse($this->store->hasStream(StreamName::for('counter', 'a')));
    }

    public function testAppendsWakeUpAWaitingProjection(): void
    {
        $waiter = new NotificationWaiter(self::connect());

        $startedAt = hrtime(true);
        $waiter(20_000);
        $idle = (hrtime(true) - $startedAt) / 1e6;

        $this->counter('a', increments: 0);

        $startedAt = hrtime(true);
        $waiter(5_000_000);
        $woken = (hrtime(true) - $startedAt) / 1e6;

        self::assertGreaterThanOrEqual(15, $idle);
        self::assertLessThan(2_000, $woken);
    }

    public function testNotificationsCanBeDisabled(): void
    {
        $waiter = new NotificationWaiter(self::connect());
        $waiter(1_000);

        new PostgresEventStore($this->pdo(), new MessageSerializer(self::messageTypes()), notifyChannel: null)
            ->append(StreamName::for('counter', 'quiet'), ExpectedVersion::any(), $this->event(new CounterStarted('quiet')));

        $startedAt = hrtime(true);
        $waiter(100_000);

        self::assertGreaterThanOrEqual(90, (hrtime(true) - $startedAt) / 1e6);
    }

    public function testTablesCanBeRenamedAndSchemaQualified(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE SCHEMA IF NOT EXISTS acta_custom');
        $schema = new PostgresSchema('acta_custom.events', 'acta_custom.checkpoints', 'acta_custom.snaps');
        $schema->drop($pdo);
        $schema->install($pdo);
        $schema->install($pdo);

        $store = new PostgresEventStore($pdo, new MessageSerializer(self::messageTypes()), 'acta_custom.events');
        $store->append(StreamName::for('counter', 'a'), ExpectedVersion::noStream(), $this->event(new CounterStarted('a')));

        try {
            $store->append(StreamName::for('counter', 'a'), ExpectedVersion::noStream(), $this->event(new CounterStarted('a')));
            self::fail('The expected version should be enforced on a renamed table too.');
        } catch (ConcurrencyConflict) {
        }

        self::assertSame(1, $store->streamVersion(StreamName::for('counter', 'a')));
        self::assertFalse($this->store->hasStream(StreamName::for('counter', 'a')));

        $schema->drop($pdo);
        $pdo->exec('DROP SCHEMA acta_custom');
    }

    public function testTableAndChannelNamesAreValidated(): void
    {
        $failures = 0;
        $attempts = [
            fn() => new PostgresSchema('events; DROP TABLE users'),
            fn() => new PostgresEventStore($this->pdo(), table: 'a.b.c'),
            fn() => new NotificationWaiter($this->pdo(), 'bad channel'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgument) {
                $failures++;
            }
        }

        self::assertSame(3, $failures);
    }

    private function storeOn(PDO $pdo): PostgresEventStore
    {
        return new PostgresEventStore($pdo, new MessageSerializer(self::messageTypes()));
    }

    private function pdo(): Pgsql
    {
        return $this->pdo ?? self::fail('The test has no connection.');
    }
}
