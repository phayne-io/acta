<?php

declare(strict_types=1);

namespace Acta\Webhook\Outgoing;

use DateTimeImmutable;
use Override;

/**
 * Delivery queue living in the memory of the process: for tests and prototypes.
 */
final class InMemoryDeliveryQueue implements DeliveryQueue
{
    /** @var array<string, OutgoingDelivery> */
    private array $deliveries = [];

    /**
     * Returns every delivery, in the order they were added.
     *
     * @return list<OutgoingDelivery>
     */
    public function all(): array
    {
        return array_values($this->deliveries);
    }

    #[Override]
    public function enqueue(OutgoingDelivery ...$deliveries): void
    {
        foreach ($deliveries as $delivery) {
            $this->deliveries[$delivery->id] ??= $delivery;
        }
    }

    #[Override]
    public function reserve(DateTimeImmutable $now, int $limit, DateTimeImmutable $reservedUntil): array
    {
        $due = array_filter(
            $this->deliveries,
            static fn(OutgoingDelivery $delivery): bool => $delivery->status === DeliveryStatus::Pending && $delivery->nextAttemptAt <= $now,
        );
        usort($due, static fn(OutgoingDelivery $a, OutgoingDelivery $b): int => $a->nextAttemptAt <=> $b->nextAttemptAt);
        $reserved = [];

        foreach (array_slice($due, 0, max(0, $limit)) as $delivery) {
            $reserved[] = $this->deliveries[$delivery->id] = $delivery->with([
                'attempts' => $delivery->attempts + 1,
                'nextAttemptAt' => $reservedUntil,
            ]);
        }

        return $reserved;
    }

    #[Override]
    public function delivered(string $id, int $status): void
    {
        $this->update($id, ['status' => DeliveryStatus::Delivered, 'lastStatus' => $status, 'lastError' => null]);
    }

    #[Override]
    public function retryAt(string $id, DateTimeImmutable $at, ?int $status, string $error): void
    {
        $this->update($id, ['nextAttemptAt' => $at, 'lastStatus' => $status, 'lastError' => $error]);
    }

    #[Override]
    public function dead(string $id, ?int $status, string $error): void
    {
        $this->update($id, ['status' => DeliveryStatus::Dead, 'lastStatus' => $status, 'lastError' => $error]);
    }

    #[Override]
    public function deadLetters(int $limit = 100): array
    {
        $dead = array_filter($this->deliveries, static fn(OutgoingDelivery $delivery): bool => $delivery->status === DeliveryStatus::Dead);
        usort($dead, static fn(OutgoingDelivery $a, OutgoingDelivery $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice($dead, 0, max(0, $limit));
    }

    #[Override]
    public function requeue(string $id, DateTimeImmutable $at): bool
    {
        if (($this->deliveries[$id] ?? null)?->status !== DeliveryStatus::Dead) {
            return false;
        }

        $this->update($id, ['status' => DeliveryStatus::Pending, 'attempts' => 0, 'nextAttemptAt' => $at]);

        return true;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function update(string $id, array $changes): void
    {
        if (isset($this->deliveries[$id])) {
            $this->deliveries[$id] = $this->deliveries[$id]->with($changes);
        }
    }
}
