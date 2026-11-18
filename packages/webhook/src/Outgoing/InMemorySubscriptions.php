<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use Override;

/**
 * Subscriptions declared in code or in configuration.
 */
final class InMemorySubscriptions implements SubscriptionRepository
{
    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    public function __construct(Subscription ...$subscriptions)
    {
        foreach ($subscriptions as $subscription) {
            $this->add($subscription);
        }
    }

    public function add(Subscription $subscription): void
    {
        $this->subscriptions[$subscription->id] = $subscription;
    }

    public function remove(string $id): void
    {
        unset($this->subscriptions[$id]);
    }

    #[Override]
    public function wanting(string $eventType): iterable
    {
        return array_values(array_filter(
            $this->subscriptions,
            static fn(Subscription $subscription): bool => $subscription->wants($eventType),
        ));
    }

    #[Override]
    public function find(string $id): ?Subscription
    {
        return $this->subscriptions[$id] ?? null;
    }
}
