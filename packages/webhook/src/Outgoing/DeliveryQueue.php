<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use DateTimeImmutable;

/**
 * The outbox: deliveries waiting to be sent, and what became of them.
 *
 * Check an implementation with {@see \Acta\Webhook\Testing\DeliveryQueueContract}.
 */
interface DeliveryQueue
{
    /**
     * Adds deliveries. One whose identifier is already known is left as it is.
     */
    public function enqueue(OutgoingDelivery ...$deliveries): void;

    /**
     * Takes the pending deliveries that are due, oldest first, and starts an attempt on
     * each: its attempt count is incremented and its next attempt set to `$reservedUntil`.
     *
     * Must be safe with several workers: a delivery is handed to one of them only. If
     * that worker dies, the delivery becomes due again at `$reservedUntil`.
     *
     * @return list<OutgoingDelivery>
     */
    public function reserve(DateTimeImmutable $now, int $limit, DateTimeImmutable $reservedUntil): array;

    /**
     * Records that the subscriber acknowledged a delivery.
     */
    public function delivered(string $id, int $status): void;

    /**
     * Schedules another attempt after a failure.
     */
    public function retryAt(string $id, DateTimeImmutable $at, ?int $status, string $error): void;

    /**
     * Gives up on a delivery: it becomes a dead letter.
     */
    public function dead(string $id, ?int $status, string $error): void;

    /**
     * Returns dead letters, most recent first.
     *
     * @return list<OutgoingDelivery>
     */
    public function deadLetters(int $limit = 100): array;

    /**
     * Makes a dead letter pending again, with a fresh attempt count.
     *
     * @return bool false when no dead letter has this identifier
     */
    public function requeue(string $id, DateTimeImmutable $at): bool;
}
