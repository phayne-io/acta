<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Aggregate;

use Acta\EventSourcing\Message\DomainEvent;

/**
 * Base class of event-sourced aggregates.
 *
 * An aggregate never changes its state directly. A business method checks its
 * invariants, then calls {@see recordThat()} with the event describing what happened;
 * {@see apply()} is the only place where state changes, so replaying the events of the
 * stream rebuilds the exact same aggregate.
 *
 * ```php
 * final class Account extends AggregateRoot
 * {
 *     private AccountId $id;
 *     private int $balance = 0;
 *
 *     public static function open(AccountId $id): self
 *     {
 *         return new self()->recordThat(new AccountOpened($id));
 *     }
 *
 *     public function deposit(int $amount): void
 *     {
 *         $amount > 0 || throw new InvalidAmount($amount);
 *
 *         $this->recordThat(new MoneyDeposited($this->id, $amount));
 *     }
 *
 *     public function aggregateId(): string
 *     {
 *         return $this->id->value;
 *     }
 *
 *     protected function apply(DomainEvent $event): void
 *     {
 *         match (true) {
 *             $event instanceof AccountOpened => $this->id = $event->id,
 *             $event instanceof MoneyDeposited => $this->balance += $event->amount,
 *         };
 *     }
 * }
 * ```
 *
 * The methods the infrastructure needs to persist and rebuild an aggregate are
 * protected and final, so they stay out of the public API of the domain. The repository
 * reaches them through {@see AggregateAccess}, which makes Prooph's `AggregateTranslator`
 * unnecessary.
 */
abstract class AggregateRoot
{
    /**
     * Number of events applied to the aggregate so far, which is also the version of
     * the last event of its stream once saved.
     */
    public private(set) int $version = 0;

    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /**
     * Identifier of the aggregate, used to name its event stream.
     */
    abstract public function aggregateId(): string;

    /**
     * Changes the state of the aggregate according to an event.
     *
     * It must never fail nor have side effects: the event already happened.
     */
    abstract protected function apply(DomainEvent $event): void;

    /**
     * Records a new event and applies it to the aggregate.
     */
    final protected function recordThat(DomainEvent $event): static
    {
        $this->apply($event);
        $this->version++;
        $this->recordedEvents[] = $event;

        return $this;
    }

    /**
     * Returns the events recorded since the aggregate was loaded and forgets them.
     *
     * @internal use {@see AggregateRepository::save()}
     *
     * @return list<DomainEvent>
     */
    final protected function popRecordedEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * Applies an event read from the stream and moves the aggregate to its version.
     *
     * @internal use {@see AggregateRepository::find()}
     */
    final protected function replay(DomainEvent $event, int $version): void
    {
        $this->apply($event);
        $this->version = $version;
    }
}
