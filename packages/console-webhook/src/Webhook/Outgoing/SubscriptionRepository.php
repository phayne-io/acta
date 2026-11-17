<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

/**
 * Where the subscriptions are kept. Implement it over your own storage when
 * subscribers manage their endpoints themselves.
 */
interface SubscriptionRepository
{
    /**
     * Returns the subscriptions that want an event type.
     *
     * @return iterable<Subscription>
     */
    public function wanting(string $eventType): iterable;

    /**
     * Returns a subscription, or null when it no longer exists.
     */
    public function find(string $id): ?Subscription;
}
