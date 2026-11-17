<?php

declare(strict_types=1);

namespace App\Banking\Domain\Event;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\MessageName;
use App\Banking\Domain\AccountId;
use Money\Currency;

/**
 * An account was opened in the name of someone, to hold one currency.
 */
#[MessageName('banking.account-opened')]
final readonly class AccountOpened implements DomainEvent
{
    public function __construct(
        public AccountId $accountId,
        public string $owner,
        public Currency $currency,
    ) {}
}
