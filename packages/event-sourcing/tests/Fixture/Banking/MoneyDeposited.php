<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use DateTimeImmutable;
use Money\Money;

#[MessageName('banking.money-deposited')]
final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public Money $money,
        public ?Channel $channel = null,
        public ?DateTimeImmutable $valueDate = null,
    ) {}
}
