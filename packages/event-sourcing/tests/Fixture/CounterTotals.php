<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture;

use Acta\EventSourcing\Projection\ReadModel;
use Override;

/**
 * In-memory read model holding the total of each counter, used by the projection tests.
 */
final class CounterTotals implements ReadModel
{
    /** @var array<string, int>|null `null` until the read model is initialized */
    public ?array $totals = null;

    public int $persisted = 0;

    public bool $deleted = false;

    public function add(string $counterId, int $by): void
    {
        $this->totals[$counterId] = ($this->totals[$counterId] ?? 0) + $by;
    }

    #[Override]
    public function init(): void
    {
        $this->totals = [];
    }

    #[Override]
    public function isInitialized(): bool
    {
        return $this->totals !== null;
    }

    #[Override]
    public function reset(): void
    {
        $this->totals = [];
    }

    #[Override]
    public function delete(): void
    {
        $this->totals = null;
        $this->deleted = true;
    }

    #[Override]
    public function persist(): void
    {
        $this->persisted++;
    }
}
