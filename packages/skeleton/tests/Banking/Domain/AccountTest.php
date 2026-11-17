<?php

declare(strict_types=1);

namespace App\Tests\Banking\Domain;

use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Testing\AggregateScenario;
use App\Banking\Domain\Account;
use App\Banking\Domain\AccountId;
use App\Banking\Domain\Event\AccountOpened;
use App\Banking\Domain\Event\MoneyDeposited;
use App\Banking\Domain\Event\MoneyWithdrawn;
use App\Banking\Domain\Exception\CurrencyMismatch;
use App\Banking\Domain\Exception\InsufficientFunds;
use App\Banking\Domain\Exception\InvalidAmount;
use App\Banking\Domain\Exception\InvalidOwner;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the bank, told as scenarios: given what happened, when something is
 * asked, then this happens — or it is refused. No database, no framework.
 */
#[CoversClass(Account::class)]
final class AccountTest extends TestCase
{
    private AccountId $id;

    protected function setUp(): void
    {
        $this->id = AccountId::generate();
    }

    #[DoesNotPerformAssertions]
    public function testAnAccountIsOpenedInTheNameOfSomeone(): void
    {
        AggregateScenario::for(Account::class)
            ->when(fn(): Account => Account::open($this->id, ' Ada ', new Currency('EUR')))
            ->then(new AccountOpened($this->id, 'Ada', new Currency('EUR')));
    }

    #[DoesNotPerformAssertions]
    public function testAnAccountCannotBeOpenedInTheNameOfNobody(): void
    {
        AggregateScenario::for(Account::class)
            ->when(fn(): Account => Account::open($this->id, '  ', new Currency('EUR')))
            ->thenThrows(InvalidOwner::class);
    }

    #[DoesNotPerformAssertions]
    public function testMoneyIsDeposited(): void
    {
        $this->openedAccount()
            ->when(static fn(Account $account) => $account->deposit(Money::EUR(12000), 'pay_1'))
            ->then(new MoneyDeposited($this->id, Money::EUR(12000), 'pay_1'));
    }

    #[DoesNotPerformAssertions]
    public function testADepositIsOfAPositiveAmount(): void
    {
        $this->openedAccount()
            ->when(static fn(Account $account) => $account->deposit(Money::EUR(0)))
            ->thenThrows(InvalidAmount::class);
    }

    #[DoesNotPerformAssertions]
    public function testAnAccountHoldsOneCurrency(): void
    {
        $this->openedAccount()
            ->when(static fn(Account $account) => $account->deposit(Money::USD(100)))
            ->thenThrows(CurrencyMismatch::class);
    }

    #[DoesNotPerformAssertions]
    public function testMoneyIsWithdrawn(): void
    {
        $this->openedAccount(new MoneyDeposited($this->id, Money::EUR(500)))
            ->when(static fn(Account $account) => $account->withdraw(Money::EUR(500)))
            ->then(new MoneyWithdrawn($this->id, Money::EUR(500)));
    }

    #[DoesNotPerformAssertions]
    public function testAnAccountCannotBeOverdrawn(): void
    {
        $this->openedAccount(new MoneyDeposited($this->id, Money::EUR(500)))
            ->when(static fn(Account $account) => $account->withdraw(Money::EUR(501)))
            ->thenThrows(InsufficientFunds::class);
    }

    #[DoesNotPerformAssertions]
    public function testAWithdrawalObeysTheSameRulesAsADeposit(): void
    {
        $account = $this->openedAccount(new MoneyDeposited($this->id, Money::EUR(500)));

        $account->when(static fn(Account $account) => $account->withdraw(Money::USD(100)))->thenThrows(CurrencyMismatch::class);
        $account->when(static fn(Account $account) => $account->withdraw(Money::EUR(-100)))->thenThrows(InvalidAmount::class);
    }

    /**
     * An event the account has no use for, recorded by an earlier or a later version of
     * the model, does not prevent it from being rebuilt.
     */
    #[DoesNotPerformAssertions]
    public function testEventsTheAccountHasNoUseForAreIgnored(): void
    {
        AggregateScenario::for(Account::class)
            ->given(new AccountOpened($this->id, 'Ada', new Currency('EUR')), new class implements DomainEvent {})
            ->when(static fn(Account $account) => $account->deposit(Money::EUR(100)))
            ->then(new MoneyDeposited($this->id, Money::EUR(100)));
    }

    public function testTheBalanceFollowsTheMovements(): void
    {
        $account = $this
            ->openedAccount(new MoneyDeposited($this->id, Money::EUR(500)), new MoneyWithdrawn($this->id, Money::EUR(120)))
            ->when(static fn(Account $account) => $account->deposit(Money::EUR(20)))
            ->then(new MoneyDeposited($this->id, Money::EUR(20)))
            ->aggregate();

        self::assertEquals(Money::EUR(400), $account->balance);
        self::assertSame('Ada', $account->owner);
        self::assertSame(4, $account->version);
    }

    /**
     * @return AggregateScenario<Account>
     */
    private function openedAccount(MoneyDeposited|MoneyWithdrawn ...$movements): AggregateScenario
    {
        return AggregateScenario::for(Account::class)->given(new AccountOpened($this->id, 'Ada', new Currency('EUR')), ...$movements);
    }
}
