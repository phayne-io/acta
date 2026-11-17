<?php

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use App\Banking\Application\Query\AccountView;
use App\Banking\Application\Query\GetAccount;
use App\Banking\Application\Query\GetAccountHandler;
use App\Banking\Application\Query\ListAccounts;
use App\Banking\Application\Query\ListAccountsHandler;
use App\Banking\Domain\Account;
use App\Banking\Domain\AccountId;
use App\Banking\Infrastructure\Secondary\Persistence\AccountDirectoryProjection;
use App\Banking\Infrastructure\Secondary\Persistence\EventSourcedAccountRepository;
use App\Banking\Infrastructure\Secondary\Persistence\InMemoryAccountDirectory;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What readers see of accounts once their events are projected, and how the queries
 * answer from it.
 */
#[CoversClass(AccountDirectoryProjection::class)]
#[CoversClass(InMemoryAccountDirectory::class)]
#[CoversClass(GetAccountHandler::class)]
#[CoversClass(ListAccountsHandler::class)]
#[CoversClass(AccountView::class)]
final class AccountDirectoryProjectionTest extends TestCase
{
    private InMemoryEventStore $eventStore;

    private InMemoryAccountDirectory $directory;

    private EventSourcedAccountRepository $accounts;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->directory = new InMemoryAccountDirectory();
        $this->accounts = new EventSourcedAccountRepository($this->eventStore);
    }

    public function testTheReadModelFollowsTheEvents(): void
    {
        $id = AccountId::generate();
        $account = Account::open($id, 'Ada', new Currency('EUR'));
        $account->deposit(Money::EUR(12000));
        $account->withdraw(Money::EUR(2000));
        $this->accounts->save($account);

        $this->project();

        self::assertEquals(
            new AccountView($id->value, 'Ada', Money::EUR(10000), 3),
            new GetAccountHandler($this->directory)(new GetAccount($id)),
        );
    }

    public function testTheProjectionResumesWhereItStopped(): void
    {
        $id = AccountId::generate();
        $this->accounts->save(Account::open($id, 'Ada', new Currency('EUR')));
        $this->project();

        $account = $this->accounts->get($id);
        $account->deposit(Money::EUR(100));
        $this->accounts->save($account);
        $this->project();

        self::assertEquals(Money::EUR(100), $this->directory->find($id)?->balance);
    }

    public function testAccountsAreListedByOwner(): void
    {
        foreach (['Ada', 'Grace', 'Ada'] as $owner) {
            $this->accounts->save(Account::open(AccountId::generate(), $owner, new Currency('EUR')));
        }

        $this->project();
        $list = new ListAccountsHandler($this->directory);

        self::assertCount(3, $list(new ListAccounts()));
        self::assertCount(2, $list(new ListAccounts('Ada')));
        self::assertCount(1, $list(new ListAccounts('Ada', 1)));
        self::assertNull(new GetAccountHandler($this->directory)(new GetAccount(AccountId::generate())));
    }

    private function project(): void
    {
        new AccountDirectoryProjection($this->directory)
            ->build(new ProjectionManager($this->eventStore, new InMemoryProjectionStore()), AccountDirectoryProjection::NAME, new ProjectionOptions())
            ->run(keepRunning: false);
    }
}
