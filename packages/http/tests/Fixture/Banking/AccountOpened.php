<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Money\Currency;

final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public string $owner,
        public Currency $currency,
    ) {}
}
