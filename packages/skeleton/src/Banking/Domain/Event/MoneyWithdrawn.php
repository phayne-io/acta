<?php

declare(strict_types=1);

namespace App\Banking\Domain\Event;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use App\Banking\Domain\AccountId;
use Money\Money;

/**
 * Money was taken from an account.
 */
#[MessageName('banking.money-withdrawn')]
final readonly class MoneyWithdrawn implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public Money $money,
    ) {}
}
