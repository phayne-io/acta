<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\Console\ProjectionRegistry;
use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\DefaultQueryBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\LoggingMiddleware;
use Acta\EventSourcing\Bus\Middleware\TransactionMiddleware;
use Acta\EventSourcing\Bus\QueryBus;
use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Postgres\NotificationWaiter;
use Acta\EventSourcing\Projection\PersistentProjection;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\ProjectionStore;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\TransactionManager;
use Acta\Webhook\Outgoing\WebhookOutbox;
use App\Platform\Projection\CatchUpMiddleware;
use App\Platform\Projection\ProjectionDefinition;
use App\Platform\Settings;
use PDO;
use Pdo\Pgsql;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Builds the buses and the projections out of what the contexts declare.
 *
 * A context never registers a handler by hand: it lists classes under `acta.events`,
 * `acta.command_handlers`, `acta.query_handlers` and `acta.projections`, and the
 * platform does the rest.
 */
final class MessagingFactories
{
    /** Metadata key that carries who is acting, from the first command to every event it causes. */
    public const string ACTOR = 'actor';

    /** Name of the projection that turns domain events into outgoing webhooks. */
    public const string OUTBOX = 'webhooks';

    public static function types(ContainerInterface $container): MessageTypeMap
    {
        return new MessageTypeMap(...Settings::of($container)->classes('acta.events'));
    }

    public static function serializer(ContainerInterface $container): MessageSerializer
    {
        return new MessageSerializer(Resolve::from($container, MessageTypeMap::class));
    }

    public static function causation(): CausationMiddleware
    {
        return new CausationMiddleware(self::ACTOR);
    }

    public static function envelopes(ContainerInterface $container): EnvelopeFactory
    {
        return new EnvelopeFactory(
            Resolve::from($container, MessageTypeMap::class),
            Resolve::from($container, ClockInterface::class),
            Resolve::from($container, CausationMiddleware::class),
        );
    }

    public static function commandBus(ContainerInterface $container): CommandBus
    {
        $settings = Settings::of($container);
        $transactions = Resolve::from($container, TransactionManager::class);

        return new DefaultCommandBus(
            new HandlerMap($container)->registerLazy(...$settings->classes('acta.command_handlers')),
            Resolve::from($container, EnvelopeFactory::class),
            new LoggingMiddleware(Resolve::from($container, LoggerInterface::class), LogLevel::DEBUG),
            Resolve::from($container, CausationMiddleware::class),
            new CatchUpMiddleware(
                array_values($settings->strings('acta.projections_after_command')),
                static fn(): ProjectionRegistry => Resolve::from($container, ProjectionRegistry::class),
                $transactions,
            ),
            new TransactionMiddleware($transactions),
        );
    }

    public static function queryBus(ContainerInterface $container): QueryBus
    {
        return new DefaultQueryBus(
            new HandlerMap($container)->registerLazy(...Settings::of($container)->classes('acta.query_handlers')),
            Resolve::from($container, EnvelopeFactory::class),
        );
    }

    public static function projections(ContainerInterface $container): ProjectionManager
    {
        return new ProjectionManager(
            Resolve::from($container, EventStore::class),
            Resolve::from($container, ProjectionStore::class),
            Resolve::from($container, TransactionManager::class),
            Resolve::from($container, EnvelopeFactory::class),
            Resolve::from($container, LoggerInterface::class),
        );
    }

    /**
     * How projections wait for new events: on a PostgreSQL notification where there is
     * one, which makes read models follow writes within milliseconds.
     */
    public static function projectionOptions(ContainerInterface $container): ProjectionOptions
    {
        $pdo = PersistenceFactories::inMemory($container) ? null : Resolve::from($container, PDO::class);

        return $pdo instanceof Pgsql
            ? new ProjectionOptions(sleepMicroseconds: 1_000_000, wait: new NotificationWaiter($pdo)->__invoke(...))
            : new ProjectionOptions();
    }

    public static function projectionRegistry(ContainerInterface $container): ProjectionRegistry
    {
        $projections = Resolve::from($container, ProjectionManager::class);
        $options = Resolve::from($container, ProjectionOptions::class);
        $registry = new ProjectionRegistry();

        foreach (Settings::of($container)->strings('acta.projections') as $name => $definition) {
            $registry->add(
                (string) $name,
                static fn(): PersistentProjection => Resolve::named($container, $definition, ProjectionDefinition::class)
                    ->build($projections, (string) $name, $options),
            );
        }

        return $registry->add(
            self::OUTBOX,
            static fn(): PersistentProjection => Resolve::from($container, WebhookOutbox::class)
                ->projection($projections, self::OUTBOX, $options),
        );
    }
}
