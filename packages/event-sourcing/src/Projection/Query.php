<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\Position;
use Acta\EventSourcing\EventStore\ReadOnlyEventStore;

/**
 * A projection that is not persisted: it folds events into a state, in memory, to answer
 * a one-off question.
 *
 * ```php
 * $query = $projections->query()
 *     ->init(fn(): array => ['deposits' => 0])
 *     ->fromStream('account-42')
 *     ->when([MoneyDeposited::class => fn(array $state): array => ['deposits' => $state['deposits'] + 1]]);
 *
 * $query->run();
 * $query->state(); // ['deposits' => 3]
 * ```
 *
 * Running the same query again only reads the events appended since the previous run.
 */
final class Query extends Projection
{
    private ?Position $position = null;

    /**
     * @internal use {@see ProjectionManager::query()}
     */
    public function __construct(
        private readonly ReadOnlyEventStore $eventStore,
        private readonly int $batchSize = 1000,
    ) {}

    /**
     * Reads the events up to the end of the log, or until a handler calls {@see stop()}.
     */
    public function run(): void
    {
        $selector = $this->selector();

        if ($this->position === null) {
            $this->state = $this->initialState();
            $this->position = Position::start();
        }

        $this->stopped = false;

        do {
            $read = 0;

            foreach ($this->eventStore->read($selector, $this->position, $this->batchSize) as $event) {
                $read++;
                $this->handle($event);
                $this->position = $event->position;

                if ($this->stopped) {
                    return;
                }
            }
        } while ($read > 0);
    }

    /**
     * Forgets the state and the position, so that the next run starts from the first event.
     */
    public function reset(): void
    {
        $this->position = null;
        $this->state = $this->initialState();
    }
}
