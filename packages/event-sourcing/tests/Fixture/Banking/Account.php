<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Fixture\Banking;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Message\DomainEvent;
use DomainException;
use Money\Currency;
use Money\Money;
use Override;

/**
 * Bank account holding one currency, with amounts modelled by moneyphp/money.
 */
final class Account extends AggregateRoot
{
    private AccountId $id;

    private Money $balance;

    private function __construct() {}

    public static function open(AccountId $id, string $owner, Currency $currency = new Currency('EUR')): self
    {
        return new self()->recordThat(new AccountOpened($id, $owner, $currency));
    }

    public function deposit(Money $money): void
    {
        $this->assertSameCurrency($money);
        $money->isPositive() || throw new DomainException('A deposit must be positive.');

        $this->recordThat(new MoneyDeposited($this->id, $money));
    }

    public function withdraw(Money $money): void
    {
        $this->assertSameCurrency($money);
        $money->isPositive() || throw new DomainException('A withdrawal must be positive.');
        $money->lessThanOrEqual($this->balance) || throw new InsufficientFunds('Not enough money on the account.');

        $this->recordThat(new MoneyWithdrawn($this->id, $money));
    }

    public function balance(): Money
    {
        return $this->balance;
    }

    #[Override]
    public function aggregateId(): string
    {
        return $this->id->value;
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof AccountOpened => [$this->id, $this->balance] = [$event->accountId, new Money(0, $event->currency)],
            $event instanceof MoneyDeposited => $this->balance = $this->balance->add($event->money),
            $event instanceof MoneyWithdrawn => $this->balance = $this->balance->subtract($event->money),
            default => null,
        };
    }

    private function assertSameCurrency(Money $money): void
    {
        $money->isSameCurrency($this->balance) || throw new DomainException(sprintf(
            'This account holds %s, not %s.',
            $this->balance->getCurrency(),
            $money->getCurrency(),
        ));
    }
}
