<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\Position;
use Override;

/**
 * Projection store keeping checkpoints in a PHP array, for tests and prototypes.
 */
final class InMemoryProjectionStore implements ProjectionStore
{
    /** @var array<string, Checkpoint> */
    private array $checkpoints = [];

    /** @var array<string, true> */
    private array $locks = [];

    #[Override]
    public function find(string $name): ?Checkpoint
    {
        return $this->checkpoints[$name] ?? null;
    }

    #[Override]
    public function save(string $name, Position $position, array $state): void
    {
        $this->checkpoints[$name] = new Checkpoint(
            $position,
            $state,
            $this->checkpoints[$name]->status ?? ProjectionStatus::Idle,
        );
    }

    #[Override]
    public function setStatus(string $name, ProjectionStatus $status): void
    {
        $checkpoint = $this->checkpoints[$name] ?? null;

        if ($checkpoint !== null) {
            $this->checkpoints[$name] = new Checkpoint($checkpoint->position, $checkpoint->state, $status);
        }
    }

    #[Override]
    public function delete(string $name): void
    {
        unset($this->checkpoints[$name]);
    }

    #[Override]
    public function names(): array
    {
        $names = array_map(strval(...), array_keys($this->checkpoints));
        sort($names);

        return $names;
    }

    #[Override]
    public function acquireLock(string $name): bool
    {
        if (isset($this->locks[$name])) {
            return false;
        }

        $this->locks[$name] = true;

        return true;
    }

    #[Override]
    public function releaseLock(string $name): void
    {
        unset($this->locks[$name]);
    }
}
