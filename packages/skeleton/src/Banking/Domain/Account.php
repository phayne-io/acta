<?php

declare(strict_types=1);

namespace App\Banking\Domain;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Message\DomainEvent;
use App\Banking\Domain\Event\AccountOpened;
use App\Banking\Domain\Event\MoneyDeposited;
use App\Banking\Domain\Event\MoneyWithdrawn;
use App\Banking\Domain\Exception\CurrencyMismatch;
use App\Banking\Domain\Exception\InsufficientFunds;
use App\Banking\Domain\Exception\InvalidAmount;
use App\Banking\Domain\Exception\InvalidOwner;
use Money\Currency;
use Money\Money;
use Override;

/**
 * A bank account holding one currency.
 *
 * The aggregate is where the rules of the bank live. A public method checks a rule and
 * records the event that says what happened; {@see apply()} is the only place where
 * state changes, which is what lets the account be rebuilt from its events.
 *
 * It knows nothing of HTTP, SQL or the framework: amounts are `Money\Money` values and
 * everything else is plain PHP.
 */
final class Account extends AggregateRoot
{
    public private(set) AccountId $id;

    public private(set) string $owner;

    public private(set) Money $balance;

    private function __construct() {}

    /**
     * @throws InvalidOwner when no owner is given
     */
    public static function open(AccountId $id, string $owner, Currency $currency): self
    {
        $owner = trim($owner);
        $owner !== '' || throw InvalidOwner::blank();

        return new self()->recordThat(new AccountOpened($id, $owner, $currency));
    }

    /**
     * @param string|null $reference what the deposit refers to outside the bank
     *
     * @throws CurrencyMismatch when the money is not in the currency of the account
     * @throws InvalidAmount    when the amount is not positive
     */
    public function deposit(Money $money, ?string $reference = null): void
    {
        $this->assertAcceptable($money, 'deposit');

        $this->recordThat(new MoneyDeposited($this->id, $money, $reference));
    }

    /**
     * @throws CurrencyMismatch  when the money is not in the currency of the account
     * @throws InvalidAmount     when the amount is not positive
     * @throws InsufficientFunds when the account does not hold that much
     */
    public function withdraw(Money $money): void
    {
        $this->assertAcceptable($money, 'withdrawal');
        $money->lessThanOrEqual($this->balance) || throw InsufficientFunds::for($money, $this->balance);

        $this->recordThat(new MoneyWithdrawn($this->id, $money));
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
            $event instanceof AccountOpened => [$this->id, $this->owner, $this->balance] = [
                $event->accountId,
                $event->owner,
                new Money(0, $event->currency),
            ],
            $event instanceof MoneyDeposited => $this->balance = $this->balance->add($event->money),
            $event instanceof MoneyWithdrawn => $this->balance = $this->balance->subtract($event->money),
            default => null,
        };
    }

    private function assertAcceptable(Money $money, string $operation): void
    {
        $money->isSameCurrency($this->balance)
            || throw CurrencyMismatch::between($this->balance->getCurrency(), $money->getCurrency());
        $money->isPositive() || throw InvalidAmount::notPositive($operation);
    }
}
