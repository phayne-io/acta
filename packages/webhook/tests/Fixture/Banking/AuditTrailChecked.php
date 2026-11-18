<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;

/**
 * A domain event that stays private: no integration event is derived from it.
 */
final readonly class AuditTrailChecked implements DomainEvent
{
    public function __construct(
        public string $accountId,
    ) {}
}
