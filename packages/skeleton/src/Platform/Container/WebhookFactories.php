<?php

declare(strict_types=1);

namespace App\Platform\Container;

use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\TransactionManager;
use Acta\Http\OpenApi\Contract;
use Acta\Webhook\Bridge\ContractPayloadValidator;
use Acta\Webhook\Incoming\DeliveryLog;
use Acta\Webhook\Incoming\WebhookReceiver;
use Acta\Webhook\Incoming\WebhookSource;
use Acta\Webhook\Outgoing\DeliveryQueue;
use Acta\Webhook\Outgoing\InMemorySubscriptions;
use Acta\Webhook\Outgoing\IntegrationEventMapper;
use Acta\Webhook\Outgoing\Subscription;
use Acta\Webhook\Outgoing\SubscriptionRepository;
use Acta\Webhook\Outgoing\WebhookOutbox;
use Acta\Webhook\Outgoing\WebhookSender;
use App\Platform\Settings;
use App\Platform\Webhook\IntegrationEventMappers;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the webhooks in both directions.
 *
 * Incoming: a context declares a {@see WebhookSource} under `acta.webhook_sources`; the
 * receiver verifies signatures, refuses duplicates and hands the translated commands to
 * the command bus. Outgoing: a context declares an {@see IntegrationEventMapper} under
 * `acta.integration_events`; the outbox projection queues a delivery per subscriber and
 * `bin/console webhook:deliver` sends them, with retries.
 */
final class WebhookFactories
{
    public static function receiver(ContainerInterface $container): WebhookReceiver
    {
        $logger = Resolve::from($container, LoggerInterface::class);
        $sources = [];

        foreach (array_unique(Settings::of($container)->strings('acta.webhook_sources')) as $id) {
            try {
                $sources[] = Resolve::named($container, $id, WebhookSource::class);
            } catch (ContainerExceptionInterface $exception) {
                // A source without its secret must not take the other sources down with
                // it: it is left out, and its webhooks are answered 404 until it is fixed.
                $logger->error('The webhook source {source} cannot be used and is ignored: {error}', [
                    'source' => $id,
                    'error' => ($exception->getPrevious() ?? $exception)->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }

        return new WebhookReceiver(
            Resolve::from($container, CommandBus::class),
            $sources,
            Resolve::from($container, ResponseFactoryInterface::class),
            Resolve::from($container, StreamFactoryInterface::class),
            Resolve::from($container, DeliveryLog::class),
            // The claim on a delivery and the events it causes are committed together.
            Resolve::from($container, TransactionManager::class),
            clock: Resolve::from($container, ClockInterface::class),
            logger: $logger,
        );
    }

    /**
     * The subscribers, read from the configuration. Replace this service with an adapter
     * of your own when subscriptions are managed at run time.
     */
    public static function subscriptions(ContainerInterface $container): SubscriptionRepository
    {
        $subscriptions = [];

        foreach (Settings::of($container)->records('acta.webhooks.subscriptions') as $record) {
            ['id' => $id, 'url' => $url, 'secret' => $secret] = $record + ['id' => null, 'url' => null, 'secret' => null];
            $events = is_array($record['events'] ?? null) ? array_filter($record['events'], is_string(...)) : ['*'];

            if (!is_string($id) || !is_string($url) || !is_string($secret) || $events === []) {
                throw new LogicException('A webhook subscription needs an "id", a "url", a "secret" and, optionally, a list of "events".');
            }

            $subscriptions[] = new Subscription($id, $url, $secret, array_values($events), ($record['allow_insecure'] ?? false) === true);
        }

        return new InMemorySubscriptions(...$subscriptions);
    }

    public static function mapper(ContainerInterface $container): IntegrationEventMapper
    {
        return new IntegrationEventMappers(...Resolve::all(
            $container,
            array_unique(Settings::of($container)->strings('acta.integration_events')),
            IntegrationEventMapper::class,
        ));
    }

    public static function outbox(ContainerInterface $container): WebhookOutbox
    {
        return new WebhookOutbox(
            Resolve::from($container, IntegrationEventMapper::class),
            Resolve::from($container, SubscriptionRepository::class),
            Resolve::from($container, DeliveryQueue::class),
            // What subscribers receive is what the `webhooks` section of the contract describes.
            new ContractPayloadValidator(Resolve::from($container, Contract::class)),
            Resolve::from($container, ClockInterface::class),
            Resolve::from($container, LoggerInterface::class),
        );
    }

    public static function sender(ContainerInterface $container): WebhookSender
    {
        return new WebhookSender(
            Resolve::from($container, DeliveryQueue::class),
            Resolve::from($container, SubscriptionRepository::class),
            Resolve::from($container, ClientInterface::class),
            Resolve::from($container, RequestFactoryInterface::class),
            Resolve::from($container, StreamFactoryInterface::class),
            clock: Resolve::from($container, ClockInterface::class),
            logger: Resolve::from($container, LoggerInterface::class),
        );
    }
}
