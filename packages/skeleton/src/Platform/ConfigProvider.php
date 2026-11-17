<?php

declare(strict_types=1);

namespace App\Platform;

use Acta\Console\Command\AuthLoginCommand;
use Acta\Console\Command\AuthLogoutCommand;
use Acta\Console\Command\MigrateCommand;
use Acta\Console\Command\OpenApiLintCommand;
use Acta\Console\Command\ProjectionRebuildCommand;
use Acta\Console\Command\ProjectionRunCommand;
use Acta\Console\Command\ProjectionStatusCommand;
use Acta\Console\Command\ProjectionStopCommand;
use Acta\Console\Command\WebhookDeadLettersCommand;
use Acta\Console\Command\WebhookDeliverCommand;
use Acta\Console\Command\WebhookRetryCommand;
use Acta\Console\ProjectionRegistry;
use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\QueryBus;
use Acta\EventSourcing\EventStore\EventStore;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\ProjectionStore;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Snapshot\SnapshotStore;
use Acta\EventSourcing\TransactionManager;
use Acta\Http\EventSourcing\EventSourcingExceptionMapper;
use Acta\Http\EventSourcing\MessageFactory;
use Acta\Http\EventSourcing\MetadataFactory;
use Acta\Http\Handler\OperationDispatcher;
use Acta\Http\Handler\OperationHandlers;
use Acta\Http\Idempotency\IdempotencyMiddleware;
use Acta\Http\Idempotency\IdempotencyStore;
use Acta\Http\OpenApi\Contract;
use Acta\Http\Problem\ProblemDetailsMiddleware;
use Acta\Http\Response\Responder;
use Acta\Http\Routing\Router;
use Acta\Http\Routing\RoutingMiddleware;
use Acta\Http\Security\SecurityMiddleware;
use Acta\Http\Trace\CorrelationMiddleware;
use Acta\Http\Validation\RequestValidationMiddleware;
use Acta\Http\Validation\ResponseValidationMiddleware;
use Acta\Oidc\Client\OAuthClient;
use Acta\Oidc\Client\TokenStorage;
use Acta\Oidc\Discovery\MetadataSource;
use Acta\Oidc\Http\Transport;
use Acta\Oidc\Jwks\KeySource;
use Acta\Oidc\Token\AccessTokenVerifier;
use Acta\Oidc\Token\JwtVerifier;
use Acta\Webhook\Incoming\DeliveryLog;
use Acta\Webhook\Incoming\WebhookReceiver;
use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Acta\Webhook\Outgoing\SubscriptionRepository;
use Acta\Webhook\Outgoing\WebhookOutbox;
use Acta\Webhook\Outgoing\WebhookSender;
use App\Platform\Container\ConsoleFactories;
use App\Platform\Container\HttpFactories;
use App\Platform\Container\MessagingFactories;
use App\Platform\Container\PersistenceFactories;
use App\Platform\Container\PlatformFactories;
use App\Platform\Container\SecurityFactories;
use App\Platform\Container\WebhookFactories;
use App\Platform\Http\BearerAuthenticator;
use App\Platform\Http\IdentityProviderOutageMapper;
use App\Platform\Make\MakeAggregateCommand;
use App\Platform\Make\MakeCommandCommand;
use App\Platform\Make\MakeContextCommand;
use App\Platform\Make\MakeQueryCommand;
use App\Platform\Make\MakeWebhookCommand;
use App\Platform\Make\ProjectFiles;
use Laminas\ServiceManager\AbstractFactory\ReflectionBasedAbstractFactory;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * Wires the Acta libraries together: the part of the application that is not business.
 *
 * Contexts plug into it through the `acta` key of their own `ConfigProvider`:
 *
 * | Key                         | What a context lists there                                        |
 * |-----------------------------|-------------------------------------------------------------------|
 * | `events`                    | its domain events, so that they can be stored and read back       |
 * | `command_handlers`          | the handlers of its commands                                      |
 * | `query_handlers`            | the handlers of its queries                                       |
 * | `operations`                | the request handlers of its operations of the OpenAPI contract   |
 * | `exception_mappers`         | how its exceptions translate to HTTP                              |
 * | `projections`               | its projections, keyed by name                                    |
 * | `schemas`                   | the tables it needs, for `es:migrate`                             |
 * | `webhook_sources`           | who may send it webhooks                                          |
 * | `integration_events`        | what it publishes to webhook subscribers                          |
 * | `console.commands`          | its console commands, keyed by name                               |
 *
 * Every entry but the events is a service identifier, usually a class name. A class
 * whose constructor only asks for other services needs no factory.
 */
final class ConfigProvider
{
    /** Service identifier of the tables of the platform. */
    public const string SCHEMA = 'acta.schema.platform';

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->dependencies(),
            'acta' => [
                'events' => [],
                'command_handlers' => [],
                'query_handlers' => [],
                'operations' => [],
                'exception_mappers' => [EventSourcingExceptionMapper::class, IdentityProviderOutageMapper::class],
                'projections' => [],
                'projections_after_command' => [],
                'schemas' => [self::SCHEMA],
                'webhook_sources' => [],
                'integration_events' => [],
                'http' => [
                    // Security schemes of the contract and what authenticates them.
                    'authenticators' => ['bearer' => BearerAuthenticator::class],
                ],
                'console' => [
                    'commands' => [
                        'es:migrate' => MigrateCommand::class,
                        'projection:run' => ProjectionRunCommand::class,
                        'projection:rebuild' => ProjectionRebuildCommand::class,
                        'projection:status' => ProjectionStatusCommand::class,
                        'projection:stop' => ProjectionStopCommand::class,
                        'webhook:deliver' => WebhookDeliverCommand::class,
                        'webhook:dead-letters' => WebhookDeadLettersCommand::class,
                        'webhook:retry' => WebhookRetryCommand::class,
                        'openapi:lint' => OpenApiLintCommand::class,
                        'auth:login' => AuthLoginCommand::class,
                        'auth:logout' => AuthLogoutCommand::class,
                        'make:context' => MakeContextCommand::class,
                        'make:aggregate' => MakeAggregateCommand::class,
                        'make:command' => MakeCommandCommand::class,
                        'make:query' => MakeQueryCommand::class,
                        'make:webhook' => MakeWebhookCommand::class,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencies(): array
    {
        return [
            // Builds any class whose constructor asks for services only: handlers,
            // request handlers and console commands of the contexts need no factory.
            'abstract_factories' => [ReflectionBasedAbstractFactory::class],
            'factories' => [
                Settings::class => PlatformFactories::settings(...),
                ClockInterface::class => PlatformFactories::clock(...),
                LoggerInterface::class => PlatformFactories::logger(...),

                // State
                PDO::class => PersistenceFactories::pdo(...),
                EventStore::class => PersistenceFactories::eventStore(...),
                TransactionManager::class => PersistenceFactories::transactions(...),
                ProjectionStore::class => PersistenceFactories::projectionStore(...),
                SnapshotStore::class => PersistenceFactories::snapshotStore(...),
                IdempotencyStore::class => PersistenceFactories::idempotencyStore(...),
                DeliveryLog::class => PersistenceFactories::deliveryLog(...),
                DeliveryQueue::class => PersistenceFactories::deliveryQueue(...),
                self::SCHEMA => PersistenceFactories::schema(...),

                // Messages
                MessageTypeMap::class => MessagingFactories::types(...),
                MessageSerializer::class => MessagingFactories::serializer(...),
                CausationMiddleware::class => MessagingFactories::causation(...),
                EnvelopeFactory::class => MessagingFactories::envelopes(...),
                CommandBus::class => MessagingFactories::commandBus(...),
                QueryBus::class => MessagingFactories::queryBus(...),
                ProjectionManager::class => MessagingFactories::projections(...),
                ProjectionOptions::class => MessagingFactories::projectionOptions(...),
                ProjectionRegistry::class => MessagingFactories::projectionRegistry(...),

                // HTTP
                Contract::class => HttpFactories::contract(...),
                Router::class => HttpFactories::router(...),
                Responder::class => HttpFactories::responder(...),
                CorrelationMiddleware::class => PlatformFactories::correlation(...),
                ProblemDetailsMiddleware::class => HttpFactories::problemDetails(...),
                RoutingMiddleware::class => HttpFactories::routing(...),
                SecurityMiddleware::class => HttpFactories::security(...),
                RequestValidationMiddleware::class => HttpFactories::requestValidation(...),
                IdempotencyMiddleware::class => HttpFactories::idempotency(...),
                ResponseValidationMiddleware::class => HttpFactories::responseValidation(...),
                OperationHandlers::class => HttpFactories::operationHandlers(...),
                OperationDispatcher::class => HttpFactories::dispatcher(...),
                MessageFactory::class => HttpFactories::messages(...),
                MetadataFactory::class => HttpFactories::metadata(...),

                // OAuth 2.0 and OpenID Connect
                ClientInterface::class => SecurityFactories::httpClient(...),
                CacheInterface::class => SecurityFactories::cache(...),
                Transport::class => SecurityFactories::transport(...),
                MetadataSource::class => SecurityFactories::provider(...),
                KeySource::class => SecurityFactories::keys(...),
                JwtVerifier::class => SecurityFactories::jwtVerifier(...),
                AccessTokenVerifier::class => SecurityFactories::accessTokenVerifier(...),
                BearerAuthenticator::class => SecurityFactories::authenticator(...),
                OAuthClient::class => SecurityFactories::oauthClient(...),
                TokenStorage::class => SecurityFactories::tokenStorage(...),

                // Webhooks
                WebhookReceiver::class => WebhookFactories::receiver(...),
                SubscriptionRepository::class => WebhookFactories::subscriptions(...),
                IntegrationEventMapper::class => WebhookFactories::mapper(...),
                WebhookOutbox::class => WebhookFactories::outbox(...),
                WebhookSender::class => WebhookFactories::sender(...),

                // Console
                ConsoleApplication::class => ConsoleFactories::application(...),
                MigrateCommand::class => ConsoleFactories::migrate(...),
                ProjectionRunCommand::class => ConsoleFactories::projectionRun(...),
                ProjectionRebuildCommand::class => ConsoleFactories::projectionRebuild(...),
                ProjectionStatusCommand::class => ConsoleFactories::projectionStatus(...),
                ProjectionStopCommand::class => ConsoleFactories::projectionStop(...),
                WebhookDeliverCommand::class => ConsoleFactories::webhookDeliver(...),
                WebhookDeadLettersCommand::class => ConsoleFactories::webhookDeadLetters(...),
                WebhookRetryCommand::class => ConsoleFactories::webhookRetry(...),
                OpenApiLintCommand::class => ConsoleFactories::openApiLint(...),
                AuthLoginCommand::class => ConsoleFactories::authLogin(...),
                AuthLogoutCommand::class => ConsoleFactories::authLogout(...),
                ProjectFiles::class => ConsoleFactories::projectFiles(...),
            ],
        ];
    }
}
