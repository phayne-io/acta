<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use Money\Currency;

#[MessageName('banking.account-opened', version: 2)]
final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public string $owner,
        public Currency $currency = new Currency('EUR'),
        public AccountType $type = AccountType::Checking,
    ) {}
}
