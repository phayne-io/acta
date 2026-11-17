<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing\Fixture;

use Acta\EventSourcing\Message\DomainEvent;

/**
 * Fixture event named after its class, used by the contract test suites.
 */
final readonly class CounterIncremented implements DomainEvent
{
    public function __construct(
        public string $counterId,
        public int $by = 1,
    ) {}
}
