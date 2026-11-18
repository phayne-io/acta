<?php

declare(strict_types=1);

namespace Acta\Webhook\Incoming;

use DateTimeImmutable;
use Override;

/**
 * Delivery log living in the memory of the process: for tests and single-process servers.
 */
final class InMemoryDeliveryLog implements DeliveryLog
{
    /** @var array<string, DateTimeImmutable> expiry of each recorded delivery */
    private array $deliveries = [];

    #[Override]
    public function claim(string $source, string $deliveryId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        $key = $source . "\0" . $deliveryId;

        if (isset($this->deliveries[$key]) && $this->deliveries[$key] > $now) {
            return false;
        }

        $this->deliveries[$key] = $expiresAt;

        return true;
    }

    #[Override]
    public function release(string $source, string $deliveryId): void
    {
        unset($this->deliveries[$source . "\0" . $deliveryId]);
    }
}
