<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\ReadModel;
use Acta\EventSourcing\Projection\ReadModelProjector;
use Acta\EventSourcing\SystemClock;
use DateTimeInterface;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;

/**
 * Turns domain events into deliveries waiting in the queue.
 *
 * It is the read model of a projection over the whole event log: for each event the
 * mapper makes public, one delivery is enqueued per interested subscription. Nothing
 * is sent here; {@see WebhookSender} does that, later and as often as it takes.
 *
 * Because the projection keeps a checkpoint, no event is skipped when the application
 * restarts. Because delivery identifiers are derived from the event and the
 * subscription, resetting the projection replays the log without sending anything twice.
 *
 * The body sent is `{"id", "type", "timestamp", "data"}`.
 */
final readonly class WebhookOutbox implements ReadModel
{
    /**
     * @param PayloadValidator|null $validator when given, a body that does not match its description is not
     *                                         sent: it goes straight to the dead letters, where the defect
     *                                         is visible and from where it can be sent once fixed
     */
    public function __construct(
        private IntegrationEventMapper $mapper,
        private SubscriptionRepository $subscriptions,
        private DeliveryQueue $queue,
        private ?PayloadValidator $validator = null,
        private ClockInterface $clock = new SystemClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Builds the projection feeding the outbox. Run it like any other projection.
     */
    public function projection(
        ProjectionManager $projections,
        string $name = 'webhooks',
        ProjectionOptions $options = new ProjectionOptions(),
    ): ReadModelProjector {
        return $projections
            ->readModelProjection($name, $this, $options)
            ->fromAll()
            ->whenAny(function (array $state, object $event, RecordedEvent $recorded): void {
                $this->publish($recorded);
            });
    }

    /**
     * Enqueues the deliveries of one domain event, if it is public.
     *
     * @return int how many deliveries the event gave rise to
     */
    public function publish(RecordedEvent $recorded): int
    {
        $event = $this->mapper->map($recorded);

        if ($event === null) {
            return 0;
        }

        $eventId = (string) $recorded->envelope->id;
        $content = [
            'id' => $eventId,
            'type' => $event->type,
            'timestamp' => $recorded->envelope->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'data' => $event->data,
        ];
        $body = json_encode(
            [...$content, 'data' => $event->data === [] ? new stdClass() : $event->data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        $defects = $this->validator?->validate($event->type, $content) ?? [];
        $now = $this->clock->now();
        $deliveries = [];

        if ($defects !== []) {
            $this->logger->error('The {type} webhook of event {event} does not match its description and will not be sent: {defects}', [
                'type' => $event->type,
                'event' => $eventId,
                'defects' => implode(' ', $defects),
            ]);
        }

        foreach ($this->subscriptions->wanting($event->type) as $subscription) {
            $deliveries[] = new OutgoingDelivery(
                OutgoingDelivery::idFor($eventId, $subscription->id),
                $subscription->id,
                $event->type,
                $body,
                $now,
                $now,
                $defects === [] ? DeliveryStatus::Pending : DeliveryStatus::Dead,
                lastError: $defects === [] ? null : 'The body does not match its description: ' . implode(' ', $defects),
            );
        }

        $this->queue->enqueue(...$deliveries);

        return count($deliveries);
    }

    #[Override]
    public function init(): void {}

    #[Override]
    public function isInitialized(): bool
    {
        return true;
    }

    /**
     * Keeps the queue: replaying the log enqueues nothing that is already there.
     */
    #[Override]
    public function reset(): void {}

    #[Override]
    public function delete(): void {}

    #[Override]
    public function persist(): void {}
}
