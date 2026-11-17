<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use DateTimeImmutable;

/**
 * One integration event to deliver to one subscription.
 */
final readonly class OutgoingDelivery
{
    /**
     * @param string            $id            identifier sent as `webhook-id`, the same for every attempt, which
     *                                         lets the subscriber deduplicate
     * @param string            $body          the JSON body, stored as it will be sent so that retries are byte-identical
     * @param int               $attempts      attempts made so far
     * @param DateTimeImmutable $nextAttemptAt when the next attempt is due
     * @param int|null          $lastStatus    HTTP status of the last attempt, if it got a response
     * @param string|null       $lastError     why the last attempt failed
     */
    public function __construct(
        public string $id,
        public string $subscriptionId,
        public string $eventType,
        public string $body,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $nextAttemptAt,
        public DeliveryStatus $status = DeliveryStatus::Pending,
        public int $attempts = 0,
        public ?int $lastStatus = null,
        public ?string $lastError = null,
    ) {}

    /**
     * Builds the identifier of the delivery of an event to a subscription.
     *
     * It is derived from both, so that publishing the same event twice, after a
     * projection was reset for instance, never delivers it twice.
     */
    public static function idFor(string $eventId, string $subscriptionId): string
    {
        return 'msg_' . substr(hash('sha256', $eventId . "\0" . $subscriptionId), 0, 32);
    }

    /**
     * Returns a copy with other values for some of its properties.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return clone($this, $changes);
    }
}
