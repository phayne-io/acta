<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing;

use Acta\EventSourcing\Aggregate\AggregateAccess;
use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Message\DomainEvent;
use AssertionError;
use Closure;
use Throwable;

/**
 * Given/when/then helper to test aggregates without any infrastructure.
 *
 * ```php
 * AggregateScenario::for(Account::class)
 *     ->given(new AccountOpened($id))
 *     ->when(fn(Account $account) => $account->deposit(100))
 *     ->then(new MoneyDeposited($id, 100));
 *
 * AggregateScenario::for(Account::class)
 *     ->when(fn() => Account::open($id))          // creation: return the new aggregate
 *     ->then(new AccountOpened($id));
 *
 * AggregateScenario::for(Account::class)
 *     ->given(new AccountOpened($id))
 *     ->when(fn(Account $account) => $account->withdraw(100))
 *     ->thenThrows(InsufficientFunds::class);
 * ```
 *
 * Failures are reported with {@see AssertionError}, which PHPUnit and the other test
 * frameworks treat as a failed assertion; the class itself depends on none of them.
 *
 * @template T of AggregateRoot
 */
final class AggregateScenario
{
    /** @var list<DomainEvent> */
    private array $history = [];

    private ?Closure $action = null;

    /** @var T|null */
    private ?AggregateRoot $aggregate = null;

    /**
     * @param class-string<T> $aggregateClass
     */
    private function __construct(
        private readonly string $aggregateClass,
    ) {}

    /**
     * @template A of AggregateRoot
     *
     * @param class-string<A> $aggregateClass
     *
     * @return self<A>
     */
    public static function for(string $aggregateClass): self
    {
        return new self($aggregateClass);
    }

    /**
     * Sets the events that already happened to the aggregate. Without any, the aggregate
     * does not exist yet and `when()` receives `null`.
     *
     * @return $this
     */
    public function given(DomainEvent ...$events): self
    {
        $this->history = array_values($events);

        return $this;
    }

    /**
     * Sets the behaviour under test: a closure calling a method of the aggregate, or
     * returning a new aggregate when testing a creation.
     *
     * The closure receives the aggregate rebuilt from the given events, so it can type its
     * parameter with the aggregate class. Without given events it receives `null`.
     *
     * @param Closure(never): mixed $action
     *
     * @return $this
     */
    public function when(Closure $action): self
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Asserts that exactly these events were recorded, in this order.
     *
     * @return $this
     *
     * @throws AssertionError
     */
    public function then(DomainEvent ...$expected): self
    {
        $recorded = $this->execute();

        if ($recorded != array_values($expected)) {
            throw new AssertionError(sprintf(
                "The aggregate did not record the expected events.\nExpected: %s\nRecorded: %s",
                self::describe(array_values($expected)),
                self::describe($recorded),
            ));
        }

        return $this;
    }

    /**
     * Asserts that no event was recorded.
     *
     * @return $this
     *
     * @throws AssertionError
     */
    public function thenNothing(): self
    {
        return $this->then();
    }

    /**
     * Asserts that the behaviour is refused with the given exception, and that no event
     * was recorded.
     *
     * @param class-string<Throwable> $exceptionClass
     *
     * @return $this
     *
     * @throws AssertionError
     */
    public function thenThrows(string $exceptionClass): self
    {
        try {
            $this->execute();
        } catch (Throwable $exception) {
            if ($exception instanceof $exceptionClass) {
                return $this;
            }

            throw new AssertionError(
                sprintf('Expected "%s" to be thrown, got "%s": %s', $exceptionClass, $exception::class, $exception->getMessage()),
                previous: $exception,
            );
        }

        throw new AssertionError(sprintf('Expected "%s" to be thrown, nothing was.', $exceptionClass));
    }

    /**
     * Returns the aggregate after the scenario ran, to assert on its state.
     *
     * @return T
     *
     * @throws AssertionError when the scenario produced no aggregate
     */
    public function aggregate(): AggregateRoot
    {
        return $this->aggregate ?? throw new AssertionError(
            'The scenario has no aggregate: call given(), or return the new aggregate from when().',
        );
    }

    /**
     * @return list<DomainEvent> the events recorded by the behaviour under test
     */
    private function execute(): array
    {
        $this->aggregate = null;

        if ($this->history !== []) {
            $this->aggregate = AggregateAccess::instantiate($this->aggregateClass);

            foreach ($this->history as $index => $event) {
                AggregateAccess::replayOn($this->aggregate, $event, $index + 1);
            }
        }

        $result = $this->action === null ? null : ($this->action)($this->aggregate);

        if ($result instanceof $this->aggregateClass) {
            $this->aggregate = $result;
        }

        return $this->aggregate === null ? [] : AggregateAccess::releaseEvents($this->aggregate);
    }

    /**
     * @param list<DomainEvent> $events
     */
    private static function describe(array $events): string
    {
        if ($events === []) {
            return '(nothing)';
        }

        return implode(', ', array_map(
            static fn(DomainEvent $event): string => $event::class . ' ' . json_encode(get_object_vars($event), JSON_PARTIAL_OUTPUT_ON_ERROR),
            $events,
        ));
    }
}
