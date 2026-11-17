<?php

declare(strict_types=1);

namespace Acta\EventSourcing\Tests\Postgres;

use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\Bus\CommandBus;
use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\DefaultEventBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\RetryOnConflictMiddleware;
use Acta\EventSourcing\Bus\Middleware\TransactionMiddleware;
use Acta\EventSourcing\EventStore\PublishingEventStore;
use Acta\EventSourcing\EventStore\RecordedEvent;
use Acta\EventSourcing\EventStore\Selector;
use Acta\EventSourcing\Exception\ConcurrencyConflict;
use Acta\EventSourcing\Message\Envelope;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Postgres\PdoTransactionManager;
use Acta\EventSourcing\Postgres\PostgresEventStore;
use Acta\EventSourcing\Postgres\PostgresProjectionStore;
use Acta\EventSourcing\Postgres\PostgresSnapshotStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ProjectionOptions;
use Acta\EventSourcing\Projection\ReadModelProjector;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\SystemClock;
use Acta\EventSourcing\Tests\Fixture\Banking\Account;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountId;
use Acta\EventSourcing\Tests\Fixture\Banking\AccountOpened;
use Acta\EventSourcing\Tests\Fixture\Banking\DepositMoney;
use Acta\EventSourcing\Tests\Fixture\Banking\MoneyDeposited;
use Acta\EventSourcing\Tests\Fixture\Banking\MoneyWithdrawn;
use Acta\EventSourcing\Tests\Fixture\Banking\OpenAccount;
use Acta\EventSourcing\Tests\Fixture\SqlBalances;
use Money\Money;
use Override;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Wires the whole write and read sides on PostgreSQL the way an application would.
 */
#[Group('postgres')]
#[RequiresPhpExtension('pdo_pgsql')]
final class EndToEndTest extends TestCase
{
    use ConnectsToPostgres;

    private Pgsql $pdo;

    private PdoTransactionManager $transactions;

    private PostgresEventStore $eventStore;

    private EnvelopeFactory $envelopes;

    private CausationMiddleware $causation;

    /** @var AggregateRepository<Account> */
    private AggregateRepository $accounts;

    private ProjectionManager $projections;

    private SqlBalances $balances;

    /** @var list<Envelope> */
    private array $published = [];

    #[Override]
    protected function setUp(): void
    {
        $this->pdo = self::connectToFreshSchema();
        $this->pdo->exec('DROP TABLE IF EXISTS test_balances');

        $types = new MessageTypeMap(AccountOpened::class, MoneyDeposited::class, MoneyWithdrawn::class);
        $this->causation = new CausationMiddleware('actor');
        $this->envelopes = new EnvelopeFactory($types, new SystemClock(), $this->causation);
        $this->transactions = new PdoTransactionManager($this->pdo);
        $this->eventStore = new PostgresEventStore($this->pdo, new MessageSerializer($types));

        $eventBus = new DefaultEventBus(new HandlerMap()->on(AccountOpened::class, function (AccountOpened $event, Envelope $envelope): void {
            $this->published[] = $envelope;
        }));

        $this->accounts = new AggregateRepository(
            Account::class,
            'account',
            new PublishingEventStore($this->eventStore, $eventBus, $this->transactions),
            $this->envelopes,
            new PostgresSnapshotStore($this->pdo),
            snapshotEvery: 2,
        );
        $this->projections = new ProjectionManager(
            $this->eventStore,
            new PostgresProjectionStore($this->pdo),
            $this->transactions,
            $this->envelopes,
        );
        $this->balances = new SqlBalances($this->pdo);
    }

    #[Override]
    protected function tearDown(): void
    {
        // A failed assertion must not leave a transaction open: it would block the next test.
        // The connection does not exist when the test was skipped for lack of a server.
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testACommandIsStoredProjectedAndTraced(): void
    {
        $bus = $this->commandBus();
        $id = AccountId::generate();

        $bus->dispatch(new OpenAccount($id, 'Ada'), new Metadata(['actor' => 'teller-7']));
        $bus->dispatch(new DepositMoney($id, Money::EUR(120)));
        $bus->dispatch(new DepositMoney($id, Money::EUR(30)));

        $events = [...$this->eventStore->load($this->accounts->streamName($id))];

        self::assertSame(
            ['banking.account-opened', 'banking.money-deposited', 'banking.money-deposited'],
            array_map(static fn(RecordedEvent $event): string => $event->name, $events),
        );
        self::assertSame('teller-7', $events[0]->metadata->get('actor'));
        self::assertFalse($events[1]->metadata->has('actor'));
        self::assertSame(OpenAccount::class, $events[0]->metadata->get(Metadata::CAUSATION_NAME));
        self::assertNotNull($events[0]->metadata->get(Metadata::CORRELATION_ID));
        self::assertNotSame(
            $events[0]->metadata->get(Metadata::CORRELATION_ID),
            $events[1]->metadata->get(Metadata::CORRELATION_ID),
        );

        // The event was published once its transaction had committed.
        self::assertCount(1, $this->published);
        self::assertTrue($this->published[0]->id->equals($events[0]->envelope->id));

        // The aggregate is rebuilt from its snapshot (taken at version 2) and the events after it.
        $account = $this->accounts->get($id);
        self::assertEquals(Money::EUR(150), $account->balance());
        self::assertSame(3, $account->version);

        $this->balancesProjection()->run(keepRunning: false);

        self::assertSame(150, $this->balances->balanceOf($id->value));
        self::assertTrue($this->projections->position('balances')->equals($events[2]->position));
    }

    public function testAFailingCommandLeavesNoTrace(): void
    {
        $id = AccountId::generate();
        $handlers = new HandlerMap()->on(OpenAccount::class, function (OpenAccount $command): never {
            $this->accounts->save(Account::open($command->accountId, $command->owner));

            throw new RuntimeException('boom');
        });
        $bus = new DefaultCommandBus($handlers, $this->envelopes, new TransactionMiddleware($this->transactions));

        try {
            $bus->dispatch(new OpenAccount($id, 'Ada'));
            self::fail('The failure of the handler should be rethrown.');
        } catch (RuntimeException) {
        }

        self::assertFalse($this->accounts->exists($id));
        self::assertSame([], [...$this->eventStore->read(Selector::all())]);
        self::assertSame([], $this->published);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testAConflictRollsBackAndTheCommandIsRetried(): void
    {
        $id = AccountId::generate();
        $this->accounts->save(Account::open($id, 'Ada'));
        $attempts = 0;
        $handlers = new HandlerMap()->on(DepositMoney::class, function (DepositMoney $command) use (&$attempts): void {
            $account = $this->accounts->get($command->accountId);
            $account->deposit($command->money);

            if (++$attempts === 1) {
                // A competing writer gets in between the load and the save of the first attempt.
                $competitor = $this->accounts->get($command->accountId);
                $competitor->deposit(Money::EUR(1000));
                $this->accounts->save($competitor);
            }

            $this->accounts->save($account);
        });
        $bus = new DefaultCommandBus(
            $handlers,
            $this->envelopes,
            new RetryOnConflictMiddleware(2, $this->transactions),
            new TransactionMiddleware($this->transactions),
        );

        $bus->dispatch(new DepositMoney($id, Money::EUR(5)));

        // The first attempt was rolled back entirely, competitor included; the second one succeeded.
        self::assertSame(2, $attempts);
        self::assertEquals(Money::EUR(5), $this->accounts->get($id)->balance());
        self::assertSame(2, $this->accounts->get($id)->version);
    }

    public function testAFailingProjectionBatchRollsBackTheReadModelAndTheCheckpoint(): void
    {
        $first = AccountId::generate();
        $second = AccountId::generate();
        $this->accounts->save(Account::open($first, 'Ada'));
        $this->balancesProjection()->run(keepRunning: false);
        $checkpoint = $this->projections->position('balances');

        $account = $this->accounts->get($first);
        $account->deposit(Money::EUR(10));
        $this->accounts->save($account);
        $this->accounts->save(Account::open($second, 'Grace'));

        try {
            $this->balancesProjection(failOn: 'Grace')->run(keepRunning: false);
            self::fail('The failure of a handler should stop the projection.');
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->balances->balanceOf($first->value));
        self::assertNull($this->balances->balanceOf($second->value));
        self::assertTrue($this->projections->position('balances')->equals($checkpoint));

        $this->balancesProjection()->run(keepRunning: false);

        self::assertSame(10, $this->balances->balanceOf($first->value));
        self::assertSame(0, $this->balances->balanceOf($second->value));
    }

    public function testAReadModelCanBeRebuiltAndRemoved(): void
    {
        $id = AccountId::generate();
        $account = Account::open($id, 'Ada');
        $account->deposit(Money::EUR(40));
        $this->accounts->save($account);
        $this->balancesProjection()->run(keepRunning: false);
        $this->balances->add($id->value, 999);

        $this->projections->reset('balances');
        $this->balancesProjection()->run(keepRunning: false);

        self::assertSame(40, $this->balances->balanceOf($id->value));

        $this->projections->delete('balances', deleteEmittedEvents: true);
        $this->balancesProjection()->run(keepRunning: false);

        self::assertSame([], $this->projections->names());
        self::assertFalse($this->balances->isInitialized());
    }

    public function testAnInlineProjectionCommitsOrRollsBackWithTheEvents(): void
    {
        $this->balances->init();
        $listeners = new HandlerMap()
            ->on(AccountOpened::class, fn(AccountOpened $event) => $this->balances->open($event->accountId->value, $event->owner))
            ->on(MoneyDeposited::class, fn(MoneyDeposited $event) => $this->balances->add($event->accountId->value, (int) $event->money->getAmount()));
        // No transaction manager: listeners run inside the transaction of the command.
        $accounts = new AggregateRepository(
            Account::class,
            'account',
            new PublishingEventStore($this->eventStore, new DefaultEventBus($listeners, $this->envelopes)),
            $this->envelopes,
        );
        $handlers = new HandlerMap()
            ->on(OpenAccount::class, static fn(OpenAccount $command) => $accounts->save(Account::open($command->accountId, $command->owner)))
            ->on(DepositMoney::class, static function (DepositMoney $command) use ($accounts): void {
                $account = $accounts->get($command->accountId);
                $account->deposit($command->money);
                $accounts->save($account);

                if ($command->money->equals(Money::EUR(13))) {
                    throw new RuntimeException('boom');
                }
            });
        $bus = new DefaultCommandBus($handlers, $this->envelopes, new TransactionMiddleware($this->transactions));
        $id = AccountId::generate();

        $bus->dispatch(new OpenAccount($id, 'Ada'));
        $bus->dispatch(new DepositMoney($id, Money::EUR(50)));

        // The read model is up to date as soon as the command returns.
        self::assertSame(50, $this->balances->balanceOf($id->value));

        try {
            $bus->dispatch(new DepositMoney($id, Money::EUR(13)));
            self::fail('The failure of the handler should be rethrown.');
        } catch (RuntimeException) {
        }

        self::assertSame(50, $this->balances->balanceOf($id->value));
        self::assertSame(2, $this->eventStore->streamVersion($accounts->streamName($id)));
    }

    public function testTheTransactionManagerJoinsATransactionOpenedElsewhere(): void
    {
        $id = AccountId::generate();

        $this->pdo->beginTransaction();
        $this->transactions->transactional(fn() => $this->accounts->save(Account::open($id, 'Ada')));

        // The commit is left to whoever opened the transaction.
        self::assertTrue($this->pdo->inTransaction());
        self::assertTrue($this->accounts->exists($id));

        $this->pdo->rollBack();

        self::assertFalse($this->accounts->exists($id));
    }

    public function testConflictsSurfaceThroughTheRepository(): void
    {
        $id = AccountId::generate();
        $this->accounts->save(Account::open($id, 'Ada'));
        $stale = $this->accounts->get($id);
        $fresh = $this->accounts->get($id);
        $fresh->deposit(Money::EUR(1));
        $this->accounts->save($fresh);
        $stale->deposit(Money::EUR(2));

        $this->expectException(ConcurrencyConflict::class);

        $this->accounts->save($stale);
    }

    private function commandBus(): CommandBus
    {
        $handlers = new HandlerMap()
            ->on(OpenAccount::class, fn(OpenAccount $command) => $this->accounts->save(
                Account::open($command->accountId, $command->owner),
            ))
            ->on(DepositMoney::class, function (DepositMoney $command): void {
                $account = $this->accounts->get($command->accountId);
                $account->deposit($command->money);
                $this->accounts->save($account);
            });

        return new DefaultCommandBus(
            $handlers,
            $this->envelopes,
            $this->causation,
            new RetryOnConflictMiddleware(3, $this->transactions),
            new TransactionMiddleware($this->transactions),
        );
    }

    private function balancesProjection(?string $failOn = null): ReadModelProjector
    {
        return $this->projections
            ->readModelProjection('balances', $this->balances, new ProjectionOptions(batchSize: 50))
            ->fromCategory('account')
            ->when([
                AccountOpened::class => function (array $state, AccountOpened $event) use ($failOn): void {
                    if ($event->owner === $failOn) {
                        throw new RuntimeException('boom');
                    }

                    $this->balances->open($event->accountId->value, $event->owner);
                },
                MoneyDeposited::class => fn(array $state, MoneyDeposited $event) => $this->balances->add(
                    $event->accountId->value,
                    (int) $event->money->getAmount(),
                ),
            ]);
    }
}
