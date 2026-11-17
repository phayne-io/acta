<?php

declare(strict_types=1);

namespace App\Banking\Domain\Event;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use App\Banking\Domain\AccountId;
use Money\Money;

/**
 * Money was added to an account.
 */
#[MessageName('banking.money-deposited')]
final readonly class MoneyDeposited implements DomainEvent
{
    /**
     * @param string|null $reference what the deposit refers to outside the bank, a payment for instance
     */
    public function __construct(
        public AccountId $accountId,
        public Money $money,
        public ?string $reference = null,
    ) {}
}
