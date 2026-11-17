<?php

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use Acta\EventSourcing\EventStore\ExpectedVersion;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Exception\AggregateNotFound;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use App\Banking\Application\Command\DepositMoney;
use App\Banking\Application\Command\DepositMoneyHandler;
use App\Banking\Application\Command\OpenAccount;
use App\Banking\Application\Command\OpenAccountHandler;
use App\Banking\Application\Command\WithdrawMoney;
use App\Banking\Application\Command\WithdrawMoneyHandler;
use App\Banking\Domain\AccountId;
use App\Banking\Domain\AccountRepository;
use App\Banking\Domain\Exception\InsufficientFunds;
use App\Banking\Infrastructure\Secondary\Persistence\EventSourcedAccountRepository;
use App\Shared\OptimisticLock;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The use cases that change accounts, against a repository that keeps its events in
 * memory: the handlers are exercised without a bus, a container or a database.
 */
#[CoversClass(OpenAccountHandler::class)]
#[CoversClass(DepositMoneyHandler::class)]
#[CoversClass(WithdrawMoneyHandler::class)]
#[CoversClass(OptimisticLock::class)]
final class AccountUseCasesTest extends TestCase
{
    private AccountRepository $accounts;

    private AccountId $id;

    protected function setUp(): void
    {
        $this->accounts = new EventSourcedAccountRepository(new InMemoryEventStore());
        $this->id = AccountId::generate();

        new OpenAccountHandler($this->accounts)(new OpenAccount($this->id, 'Ada', new Currency('EUR')));
    }

    public function testAnAccountIsOpenedEmpty(): void
    {
        $account = $this->accounts->get($this->id);

        self::assertSame('Ada', $account->owner);
        self::assertEquals(Money::EUR(0), $account->balance);
    }

    public function testMoneyIsDepositedThenWithdrawn(): void
    {
        new DepositMoneyHandler($this->accounts)(new DepositMoney($this->id, Money::EUR(12000)));
        new WithdrawMoneyHandler($this->accounts)(new WithdrawMoney($this->id, Money::EUR(2000)));

        self::assertEquals(Money::EUR(10000), $this->accounts->get($this->id)->balance);
    }

    public function testARefusedWithdrawalChangesNothing(): void
    {
        try {
            new WithdrawMoneyHandler($this->accounts)(new WithdrawMoney($this->id, Money::EUR(1)));
            self::fail('The withdrawal should have been refused.');
        } catch (InsufficientFunds) {
            self::assertSame(1, $this->accounts->get($this->id)->version);
        }
    }

    public function testACommandMayStateTheVersionItsAuthorHasSeen(): void
    {
        $deposit = new DepositMoneyHandler($this->accounts);

        $deposit(new DepositMoney($this->id, Money::EUR(100), expectedVersion: ExpectedVersion::exactly(1)));

        $this->expectException(ConcurrencyConflict::class);
        $this->expectExceptionMessage('is at version 2');

        // The author of this command still believes the account is at version 1.
        $deposit(new DepositMoney($this->id, Money::EUR(100), expectedVersion: ExpectedVersion::exactly(1)));
    }

    public function testAWithdrawalMayStateItToo(): void
    {
        new DepositMoneyHandler($this->accounts)(new DepositMoney($this->id, Money::EUR(100)));

        $this->expectException(ConcurrencyConflict::class);

        new WithdrawMoneyHandler($this->accounts)(new WithdrawMoney($this->id, Money::EUR(50), ExpectedVersion::exactly(1)));
    }

    public function testAnUnknownAccountIsAnError(): void
    {
        $this->expectException(AggregateNotFound::class);

        new DepositMoneyHandler($this->accounts)(new DepositMoney(AccountId::generate(), Money::EUR(100)));
    }
}
