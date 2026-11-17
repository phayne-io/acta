<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Money\Money;

final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public Money $money,
    ) {}
}
