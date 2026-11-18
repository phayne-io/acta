<?php

declare(strict_types=1);

namespace Acta\Webhook\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;

final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public string $accountId,
        public string $owner,
    ) {}
}
