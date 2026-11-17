<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Unit\Aggregate;

use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\EventStore\StreamName;
use Acta\EventSourcing\Exception\AggregateNotFound;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Exception\InvalidArgument;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\Snapshot\InMemorySnapshotStore;
use Acta\EventSourcing\Testing\AggregateScenario;
use Acta\EventSourcing\Testing\Fixture\Counter;
use Acta\EventSourcing\Tests\Fixture\Banking\Account;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountOpened;
use Acta\EventSourcing\Tests\Fixture\Banking\InsufficientFunds;
use Acta\EventSourcing\Tests\Fixture\Banking\MoneyDeposited;
use Acta\EventSourcing\Tests\Fixture\Banking\MoneyWithdrawn;
use AssertionError;
use DomainException;
use Money\Currency;
use Money\Money;
use Override;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AggregateTest extends TestCase
{
    private InMemoryEventStore $store;

    private MessageTypeMap $types;

    #[Override]
    protected function setUp(): void
    {
        $this->types = new MessageTypeMap(AccountOpened::class, MoneyDeposited::class, MoneyWithdrawn::class);
        $this->store = new InMemoryEventStore(new MessageSerializer($this->types));
    }

    public function testTypedIdentifiers(): void
    {
        $id = AccountId::generate();

        self::assertTrue($id->equals(AccountId::fromString($id->value)));
        self::assertFalse($id->equals(AccountId::generate()));
        self::assertSame($id->value, (string) $id);
        self::assertSame('natural-key', AccountId::fromString('natural-key')->value);

        $this->expectException(InvalidArgument::class);

        AccountId::fromString('with space');
    }

    public function testAnAggregateRecordsEventsAndTracksItsVersion(): void
    {
        $account = Account::open(AccountId::generate(), 'Ada');
        $account->deposit(Money::EUR(100));
        $account->withdraw(Money::EUR(30));

        self::assertSame(3, $account->version);
        self::assertEquals(Money::EUR(70), $account->balance());
    }

    public function testASavedAggregateIsRebuiltFromItsEvents(): void
    {
        $repository = $this->repository();
        $id = AccountId::generate();
        $account = Account::open($id, 'Ada');
        $account->deposit(Money::EUR(100));

        $repository->save($account);
        $repository->save($account);

        $loaded = $repository->get($id);
        $loaded->withdraw(Money::EUR(40));
        $repository->save($loaded);

        $reloaded = $repository->get($id);

        self::assertNotSame($account, $loaded);
        self::assertEquals(Money::EUR(60), $reloaded->balance());
        self::assertSame(3, $reloaded->version);
        self::assertSame(3, $this->store->streamVersion(StreamName::for('account', $id)));
        self::assertTrue($repository->exists($id));
        self::assertSame(
            ['banking.account-opened', 'banking.money-deposited', 'banking.money-withdrawn'],
            array_map(static fn($event) => $event->name, [...$this->store->load($repository->streamName($id))]),
        );
    }

    public function testAnUnknownAggregateIsNotFound(): void
    {
        $repository = $this->repository();
        $id = AccountId::generate();

        self::assertNull($repository->find($id));
        self::assertFalse($repository->exists($id));

        $this->expectException(AggregateNotFound::class);

        (void) $repository->get($id);
    }

    public function testConcurrentChangesToAnAggregateConflict(): void
    {
        $repository = $this->repository();
        $id = AccountId::generate();
        $repository->save(Account::open($id, 'Ada'));

        $first = $repository->get($id);
        $second = $repository->get($id);
        $first->deposit(Money::EUR(10));
        $second->deposit(Money::EUR(20));
        $repository->save($first);

        $this->expectException(ConcurrencyConflict::class);

        $repository->save($second);
    }

    public function testCreatingTheSameAggregateTwiceConflicts(): void
    {
        $repository = $this->repository();
        $id = AccountId::generate();
        $repository->save(Account::open($id, 'Ada'));

        $this->expectException(ConcurrencyConflict::class);

        $repository->save(Account::open($id, 'Grace'));
    }

    public function testARepositoryOnlyStoresItsOwnAggregateType(): void
    {
        $this->expectException(InvalidArgument::class);

        /** @phpstan-ignore argument.type */
        $this->repository()->save(Counter::start('a'));
    }

    public function testRepositoryConfigurationIsValidated(): void
    {
        $failures = 0;
        $attempts = [
            /** @phpstan-ignore argument.type */
            fn() => new AggregateRepository(stdClass::class, 'account', $this->store),
            fn() => new AggregateRepository(Account::class, 'bank-account', $this->store),
            fn() => new AggregateRepository(Account::class, 'account', $this->store, snapshotEvery: 5),
            fn() => new AggregateRepository(Account::class, 'account', $this->store, snapshotEvery: -1),
            fn() => $this->repository()->takeSnapshot('a'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgument) {
                $failures++;
            }
        }

        self::assertSame(5, $failures);
    }

    public function testSnapshotsAreTakenAtTheConfiguredIntervalAndUsedToLoad(): void
    {
        $snapshots = new InMemorySnapshotStore();
        $repository = new AggregateRepository(
            Account::class,
            'account',
            $this->store,
            new EnvelopeFactory($this->types),
            $snapshots,
            snapshotEvery: 3,
        );
        $id = AccountId::generate();
        $account = Account::open($id, 'Ada');
        $account->deposit(Money::EUR(10));
        $repository->save($account);

        self::assertNull($snapshots->get('account', $id->value));

        $account->deposit(Money::EUR(20));
        $account->deposit(Money::EUR(30));
        $repository->save($account);

        self::assertSame(4, $snapshots->get('account', $id->value)?->version);

        $account->deposit(Money::EUR(5));
        $repository->save($account);

        // Dropping the events covered by the snapshot proves the snapshot is what gets loaded.
        $events = [...$this->store->load($repository->streamName($id), 5)];
        $this->store->delete($repository->streamName($id));
        $loaded = $repository->get($id);

        self::assertEquals(Money::EUR(60), $loaded->balance());
        self::assertSame(4, $loaded->version);
        self::assertCount(1, $events);
    }

    public function testASnapshotCanBeTakenOnDemand(): void
    {
        $snapshots = new InMemorySnapshotStore();
        $repository = new AggregateRepository(Account::class, 'account', $this->store, new EnvelopeFactory($this->types), $snapshots);
        $id = AccountId::generate();
        $account = Account::open($id, 'Ada');
        $account->deposit(Money::EUR(10));
        $repository->save($account);

        $repository->takeSnapshot($id);
        $repository->takeSnapshot(AccountId::generate());

        $snapshot = $snapshots->get('account', $id->value);

        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->version);
        self::assertInstanceOf(Account::class, $snapshot->aggregate);
        self::assertEquals(Money::EUR(10), $snapshot->aggregate->balance());

        $account->deposit(Money::EUR(5));
        $repository->save($account);

        self::assertEquals(Money::EUR(15), $repository->get($id)->balance());
        self::assertSame(3, $repository->get($id)->version);
    }

    public function testScenariosDescribeTheBehaviourOfAnAggregate(): void
    {
        $id = AccountId::generate();

        AggregateScenario::for(Account::class)
            ->when(static fn() => Account::open($id, 'Ada'))
            ->then(new AccountOpened($id, 'Ada'));

        $scenario = AggregateScenario::for(Account::class)
            ->given(new AccountOpened($id, 'Ada'), new MoneyDeposited($id, Money::EUR(100)))
            ->when(static fn(?Account $account) => $account?->withdraw(Money::EUR(30)))
            ->then(new MoneyWithdrawn($id, Money::EUR(30)));

        self::assertEquals(Money::EUR(70), $scenario->aggregate()->balance());
        self::assertSame(3, $scenario->aggregate()->version);

        AggregateScenario::for(Account::class)
            ->given(new AccountOpened($id, 'Ada'))
            ->when(static fn(?Account $account) => $account?->withdraw(Money::EUR(1)))
            ->thenThrows(InsufficientFunds::class);

        AggregateScenario::for(Account::class)
            ->given(new AccountOpened($id, 'Ada'))
            ->when(static fn(?Account $account) => $account?->balance())
            ->thenNothing();
    }

    public function testAnAccountOnlyAcceptsItsOwnCurrency(): void
    {
        $id = AccountId::generate();

        AggregateScenario::for(Account::class)
            ->given(new AccountOpened($id, 'Ada', new Currency('USD')))
            ->when(static fn(?Account $account) => $account?->deposit(Money::EUR(5)))
            ->thenThrows(DomainException::class);

        $scenario = AggregateScenario::for(Account::class)
            ->given(new AccountOpened($id, 'Ada', new Currency('USD')))
            ->when(static fn(?Account $account) => $account?->deposit(Money::USD(5)))
            ->then(new MoneyDeposited($id, Money::USD(5)));

        self::assertEquals(Money::USD(5), $scenario->aggregate()->balance());
    }

    public function testAScenarioFailsWithAnAssertionError(): void
    {
        $id = AccountId::generate();
        $failures = [];
        $scenarios = [
            'unexpected events' => static fn() => AggregateScenario::for(Account::class)
                ->given(new AccountOpened($id, 'Ada'))
                ->when(static fn(?Account $account) => $account?->deposit(Money::EUR(5)))
                ->thenNothing(),
            'wrong exception' => static fn() => AggregateScenario::for(Account::class)
                ->given(new AccountOpened($id, 'Ada'))
                ->when(static fn(?Account $account) => $account?->deposit(Money::EUR(-5)))
                ->thenThrows(InsufficientFunds::class),
            'no exception' => static fn() => AggregateScenario::for(Account::class)
                ->given(new AccountOpened($id, 'Ada'))
                ->when(static fn(?Account $account) => $account?->deposit(Money::EUR(5)))
                ->thenThrows(DomainException::class),
            'no aggregate' => static fn() => AggregateScenario::for(Account::class)->thenNothing()->aggregate(),
        ];

        foreach ($scenarios as $name => $scenario) {
            try {
                $scenario();
            } catch (AssertionError $error) {
                $failures[$name] = $error->getMessage();
            }
        }

        self::assertSame(array_keys($scenarios), array_keys($failures));
        self::assertStringContainsString(MoneyDeposited::class, $failures['unexpected events'] ?? '');
        self::assertStringContainsString('(nothing)', $failures['unexpected events'] ?? '');
    }

    /**
     * @return AggregateRepository<Account>
     */
    private function repository(): AggregateRepository
    {
        return new AggregateRepository(Account::class, 'account', $this->store, new EnvelopeFactory($this->types));
    }
}
