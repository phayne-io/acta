<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use Money\Money;

#[MessageName('banking.money-withdrawn')]
final readonly class MoneyWithdrawn implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public Money $money,
    ) {}
}
