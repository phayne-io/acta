<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Projection;

use Acta\EventSourcing\EventStore\Position;

/**
 * What is persisted about a projection: how far it read, the state it computed and its
 * lifecycle status.
 */
final readonly class Checkpoint
{
    /**
     * @param Position     $position position of the last event handled
     * @param array<mixed> $state    state returned by the handlers, JSON-serializable
     */
    public function __construct(
        public Position $position,
        public array $state,
        public ProjectionStatus $status,
    ) {}
}
