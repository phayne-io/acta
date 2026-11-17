<?php

declare(strict_types=1);

namespace Acta\Http\Tests\Fixture\Banking;

use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Message\DomainEvent;
use DomainException;
use Money\Currency;
use Money\Money;
use Override;

/**
 * Bank account, with amounts modelled by moneyphp/money.
 */
final class Account extends AggregateRoot
{
    private AccountId $id;

    private string $owner;

    private Money $balance;

    private function __construct() {}

    public static function open(AccountId $id, string $owner, Currency $currency): self
    {
        return new self()->recordThat(new AccountOpened($id, $owner, $currency));
    }

    public function deposit(Money $money): void
    {
        $money->isSameCurrency($this->balance) || throw new DomainException(sprintf('This account holds %s.', $this->balance->getCurrency()));
        $money->isPositive() || throw new DomainException('A deposit must be positive.');

        $this->recordThat(new MoneyDeposited($this->id, $money));
    }

    /**
     * Returns the representation of the account the API exposes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'owner' => $this->owner,
            'balance' => ['amount' => $this->balance->getAmount(), 'currency' => $this->balance->getCurrency()->getCode()],
            'version' => $this->version,
        ];
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
            $event instanceof AccountOpened => [$this->id, $this->owner, $this->balance] = [$event->accountId, $event->owner, new Money(0, $event->currency)],
            $event instanceof MoneyDeposited => $this->balance = $this->balance->add($event->money),
            default => null,
        };
    }
}
