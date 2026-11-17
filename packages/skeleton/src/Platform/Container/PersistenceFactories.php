<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\Console\Schema\Schema;
use Acta\Console\Schema\StatementsSchema;
use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Postgres\PdoTransactionManager;
use Acta\EventSourcing\Postgres\PostgresEventStore;
use Acta\EventSourcing\Postgres\PostgresProjectionStore;
use Acta\EventSourcing\Postgres\PostgresSchema;
use Acta\EventSourcing\Postgres\PostgresSnapshotStore;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionStore;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Snapshot\InMemorySnapshotStore;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Acta\EventSourcing\TransactionManager;
use Acta\Http\Idempotency\IdempotencyStore;
use Acta\Http\Idempotency\InMemoryIdempotencyStore;
use Acta\Http\Idempotency\PostgresIdempotencyStore;
use Acta\Webhook\Incoming\DeliveryLog;
use Acta\Webhook\Incoming\InMemoryDeliveryLog;
use Acta\Webhook\Incoming\PostgresDeliveryLog;
use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\InMemoryDeliveryQueue;
use Acta\Webhook\Outgoing\PostgresDeliveryQueue;
use App\Platform\Settings;
use LogicException;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the secondary adapters that keep state: the event store and what surrounds it.
 *
 * Each port has two adapters, chosen by `acta.persistence`: `postgres`, which is what
 * runs in production, and `memory`, which keeps everything in the process and makes the
 * test suite fast. Nothing above this class knows which one is in use.
 */
final class PersistenceFactories
{
    public const string MEMORY = 'memory';

    public const string POSTGRES = 'postgres';

    /**
     * Tells whether state is kept in the process rather than in PostgreSQL.
     */
    public static function inMemory(ContainerInterface $container): bool
    {
        return match (Settings::of($container)->string('acta.persistence', self::POSTGRES)) {
            self::MEMORY => true,
            self::POSTGRES => false,
            default => throw new LogicException('The configuration "acta.persistence" must be "postgres" or "memory".'),
        };
    }

    /**
     * The connection shared by every PostgreSQL adapter, so that they join the same transactions.
     */
    public static function pdo(ContainerInterface $container): PDO
    {
        $settings = Settings::of($container);

        return PDO::connect(
            $settings->string('acta.database.dsn'),
            $settings->string('acta.database.user') ?: null,
            $settings->string('acta.database.password') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    public static function eventStore(ContainerInterface $container): EventStore
    {
        $serializer = Resolve::from($container, MessageSerializer::class);

        return self::inMemory($container)
            ? new InMemoryEventStore($serializer)
            : new PostgresEventStore(Resolve::from($container, PDO::class), $serializer);
    }

    public static function transactions(ContainerInterface $container): TransactionManager
    {
        // The in-memory event store is its own transaction manager.
        return self::inMemory($container)
            ? Resolve::named($container, EventStore::class, TransactionManager::class)
            : new PdoTransactionManager(Resolve::from($container, PDO::class));
    }

    public static function projectionStore(ContainerInterface $container): ProjectionStore
    {
        return self::inMemory($container)
            ? new InMemoryProjectionStore()
            : new PostgresProjectionStore(Resolve::from($container, PDO::class));
    }

    public static function snapshotStore(ContainerInterface $container): SnapshotStore
    {
        return self::inMemory($container)
            ? new InMemorySnapshotStore()
            : new PostgresSnapshotStore(Resolve::from($container, PDO::class), logger: Resolve::from($container, LoggerInterface::class));
    }

    public static function idempotencyStore(ContainerInterface $container): IdempotencyStore
    {
        return self::inMemory($container)
            ? new InMemoryIdempotencyStore()
            : new PostgresIdempotencyStore(Resolve::from($container, PDO::class));
    }

    public static function deliveryLog(ContainerInterface $container): DeliveryLog
    {
        return self::inMemory($container)
            ? new InMemoryDeliveryLog()
            : new PostgresDeliveryLog(Resolve::from($container, PDO::class));
    }

    public static function deliveryQueue(ContainerInterface $container): DeliveryQueue
    {
        return self::inMemory($container)
            ? new InMemoryDeliveryQueue()
            : new PostgresDeliveryQueue(Resolve::from($container, PDO::class));
    }

    /**
     * The tables of the platform, created by `bin/console es:migrate` before those of the contexts.
     */
    public static function schema(ContainerInterface $container): Schema
    {
        $pdo = Resolve::from($container, PDO::class);

        return new StatementsSchema('platform', [
            ...new PostgresSchema()->statements(),
            ...new PostgresIdempotencyStore($pdo)->statements(),
            ...new PostgresDeliveryLog($pdo)->statements(),
            ...new PostgresDeliveryQueue($pdo)->statements(),
        ]);
    }
}
