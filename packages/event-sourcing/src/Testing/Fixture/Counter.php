<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing\Fixture;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Message\DomainEvent;
use InvalidArgumentException;
use Override;

/**
 * Minimal event-sourced aggregate, used by the contract test suites and as an example.
 */
final class Counter extends AggregateRoot
{
    private string $id;

    private int $count = 0;

    private function __construct() {}

    public static function start(string $id): self
    {
        return new self()->recordThat(new CounterStarted($id));
    }

    public function increment(int $by = 1): void
    {
        if ($by < 1) {
            throw new InvalidArgumentException('A counter can only be incremented by a positive number.');
        }

        $this->recordThat(new CounterIncremented($this->id, $by));
    }

    public function count(): int
    {
        return $this->count;
    }

    #[Override]
    public function aggregateId(): string
    {
        return $this->id;
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof CounterStarted => $this->id = $event->counterId,
            $event instanceof CounterIncremented => $this->count += $event->by,
            default => null,
        };
    }
}
