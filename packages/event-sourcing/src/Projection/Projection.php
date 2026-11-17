<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\InvalidArgument;
use Closure;

/**
 * Fluent configuration shared by {@see Query}, {@see Projector} and
 * {@see ReadModelProjector}: where events come from and what to do with them.
 *
 * Handlers receive up to four arguments and return the new state, or nothing to leave
 * it unchanged:
 *
 * ```php
 * function (array $state, AccountOpened $event, RecordedEvent $recorded, Projector $projection): array
 * ```
 *
 * Prooph bound handlers to the projector so that `$this->emit()` or `$this->stop()`
 * worked inside them. Here the projection is passed as the last argument instead, which
 * keeps handlers plain closures that static analysis understands.
 *
 * Create projections through the {@see ProjectionManager}; this class is not meant to be
 * extended outside the library.
 */
abstract class Projection
{
    /** @var array<mixed> */
    protected array $state = [];

    protected bool $stopped = false;

    /** @var (Closure(): array<mixed>)|null */
    private ?Closure $init = null;

    private ?Selector $selector = null;

    /** @var array<string, Closure> */
    private array $handlers = [];

    private ?Closure $anyHandler = null;

    /**
     * Sets the callback returning the initial state of the projection.
     *
     * @param Closure(): array<mixed> $callback
     */
    public function init(Closure $callback): static
    {
        if ($this->init !== null) {
            throw new InvalidArgument('The initial state of the projection is already defined.');
        }

        $this->init = $callback;

        return $this;
    }

    /**
     * Reads the events described by a selector.
     */
    public function from(Selector $selector): static
    {
        if ($this->selector !== null) {
            throw new InvalidArgument('The source of the projection is already defined.');
        }

        $this->selector = $selector;

        return $this;
    }

    /**
     * Reads every event of the store.
     */
    public function fromAll(): static
    {
        return $this->from(Selector::all());
    }

    /**
     * Reads the events of one or several streams.
     */
    public function fromStream(StreamName|string ...$streams): static
    {
        return $this->from(Selector::stream(...$streams));
    }

    /**
     * Reads the events of every stream of one or several categories.
     */
    public function fromCategory(string ...$categories): static
    {
        return $this->from(Selector::category(...$categories));
    }

    /**
     * Sets the handlers, indexed by event class. Events without handler are skipped,
     * unless {@see whenAny()} is also used.
     *
     * @param array<class-string, Closure> $handlers
     */
    public function when(array $handlers): static
    {
        if ($this->handlers !== []) {
            throw new InvalidArgument('The handlers of the projection are already defined.');
        }

        foreach ($handlers as $eventClass => $handler) {
            if (!is_string($eventClass) || !$handler instanceof Closure) {
                throw new InvalidArgument('Projection handlers must be closures indexed by event class.');
            }
        }

        $this->handlers = $handlers;

        return $this;
    }

    /**
     * Sets the handler called for every event that has no handler of its own.
     */
    public function whenAny(Closure $handler): static
    {
        if ($this->anyHandler !== null) {
            throw new InvalidArgument('The catch-all handler of the projection is already defined.');
        }

        $this->anyHandler = $handler;

        return $this;
    }

    /**
     * Returns the state computed so far.
     *
     * @return array<mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Asks the projection to stop after the event being handled. Meant to be called from
     * a handler or a signal handler.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * @return array<mixed>
     */
    protected function initialState(): array
    {
        return $this->init === null ? [] : ($this->init)();
    }

    /**
     * @throws InvalidArgument when the projection is not fully configured
     */
    protected function selector(): Selector
    {
        if ($this->handlers === [] && $this->anyHandler === null) {
            throw new InvalidArgument('The projection has no handler: call when() or whenAny() first.');
        }

        return $this->selector
            ?? throw new InvalidArgument('The projection has no source: call from(), fromStream(), fromCategory() or fromAll() first.');
    }

    protected function handle(RecordedEvent $event): void
    {
        $handler = $this->handlers[$event->message::class] ?? $this->anyHandler;

        if ($handler === null) {
            return;
        }

        $state = $handler($this->state, $event->message, $event, $this);

        if (is_array($state)) {
            $this->state = $state;
        }
    }
}
