<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Testing\Fixture;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;

/**
 * Fixture event with an explicit public name, used by the contract test suites.
 */
#[MessageName('acta.testing.counter-started')]
final readonly class CounterStarted implements DomainEvent
{
    public function __construct(
        public string $counterId,
    ) {}
}
