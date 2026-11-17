<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use DateTimeImmutable;

/**
 * Remembers which deliveries were already handled, since senders deliver at least once.
 *
 * Check an implementation with {@see \Acta\Webhook\Testing\DeliveryLogContract}.
 */
interface DeliveryLog
{
    /**
     * Records a delivery as being handled.
     *
     * Must be atomic: of two concurrent calls for the same delivery, only one gets true.
     * A delivery whose record has expired counts as new.
     *
     * @param DateTimeImmutable $now       the current time, by which earlier records are judged expired
     * @param DateTimeImmutable $expiresAt when the record may be forgotten
     *
     * @return bool true when the delivery is new, false when it was already recorded
     */
    public function claim(string $source, string $deliveryId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool;

    /**
     * Forgets a delivery whose handling failed, so that the retry of the sender is handled.
     */
    public function release(string $source, string $deliveryId): void;
}
