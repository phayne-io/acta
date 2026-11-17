<?php

declare(strict_types=1);

/*
 * A complete write and read side in one file, running in memory.
 *
 *     php examples/quickstart.php
 *
 * Swap the three in-memory adapters for the PostgreSQL ones (see README.md) and the rest
 * of the code stays the same.
 *
 * Amounts are modelled with moneyphp/money, a development dependency of this package:
 * its value objects are stored and rebuilt by the default serializer without any adapter.
 */

namespace Acta\Example;

use Acta\EventSourcing\Aggregate\AggregateId;
use Acta\EventSourcing\Aggregate\AggregateRepository;
use Acta\EventSourcing\Aggregate\AggregateRoot;
use Acta\EventSourcing\Bus\DefaultCommandBus;
use Acta\EventSourcing\Bus\DefaultQueryBus;
use Acta\EventSourcing\Bus\HandlerMap;
use Acta\EventSourcing\Bus\Handles;
use Acta\EventSourcing\Bus\Middleware\CausationMiddleware;
use Acta\EventSourcing\Bus\Middleware\RetryOnConflictMiddleware;
use Acta\EventSourcing\Bus\Middleware\TransactionMiddleware;
use Acta\EventSourcing\EventStore\InMemoryEventStore;
use Acta\EventSourcing\Message\Command;
use Acta\EventSourcing\Message\DomainEvent;
use Acta\EventSourcing\Message\EnvelopeFactory;
use Acta\EventSourcing\Message\MessageName;
use Acta\EventSourcing\Message\MessageTypeMap;
use Acta\EventSourcing\Message\Metadata;
use Acta\EventSourcing\Message\Query;
use Acta\EventSourcing\Projection\InMemoryProjectionStore;
use Acta\EventSourcing\Projection\ProjectionManager;
use Acta\EventSourcing\Projection\ReadModel;
use Acta\EventSourcing\Serializer\MessageSerializer;
use Acta\EventSourcing\SystemClock;
use DomainException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

require __DIR__ . '/../vendor/autoload.php';

// --- Domain ---------------------------------------------------------------------------

final readonly class AccountId extends AggregateId {}

#[MessageName('banking.account-opened')]
final readonly class AccountOpened implements DomainEvent
{
    public function __construct(public AccountId $accountId, public string $owner, public Currency $currency) {}
}

#[MessageName('banking.money-deposited')]
final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(public AccountId $accountId, public Money $money) {}
}

final class Account extends AggregateRoot
{
    private AccountId $id;

    private Currency $currency;

    private function __construct() {}

    public static function open(AccountId $id, string $owner, Currency $currency): self
    {
        return new self()->recordThat(new AccountOpened($id, $owner, $currency));
    }

    public function deposit(Money $money): void
    {
        $money->getCurrency()->equals($this->currency) || throw new DomainException('Wrong currency.');
        $money->isPositive() || throw new DomainException('A deposit must be positive.');

        $this->recordThat(new MoneyDeposited($this->id, $money));
    }

    public function aggregateId(): string
    {
        return $this->id->value;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof AccountOpened => [$this->id, $this->currency] = [$event->accountId, $event->currency],
            default => null,
        };
    }
}

// --- Application ----------------------------------------------------------------------

final readonly class OpenAccount implements Command
{
    public function __construct(public AccountId $accountId, public string $owner, public Currency $currency) {}
}

final readonly class DepositMoney implements Command
{
    public function __construct(public AccountId $accountId, public Money $money) {}
}

final readonly class GetBalance implements Query
{
    public function __construct(public AccountId $accountId) {}
}

final readonly class AccountHandlers
{
    /**
     * @param AggregateRepository<Account> $accounts
     */
    public function __construct(private AggregateRepository $accounts, private Balances $balances) {}

    #[Handles]
    public function open(OpenAccount $command): void
    {
        $this->accounts->save(Account::open($command->accountId, $command->owner, $command->currency));
    }

    #[Handles]
    public function deposit(DepositMoney $command): void
    {
        $account = $this->accounts->get($command->accountId);
        $account->deposit($command->money);

        $this->accounts->save($account);
    }

    #[Handles]
    public function balance(GetBalance $query): ?Money
    {
        return $this->balances->of($query->accountId);
    }
}

// --- Read model -----------------------------------------------------------------------

final class Balances implements ReadModel
{
    /** @var array<string, Money> */
    private array $balances = [];

    public function add(AccountId $id, Money $money): void
    {
        $this->balances[$id->value] = $this->of($id)?->add($money) ?? $money;
    }

    public function of(AccountId $id): ?Money
    {
        return $this->balances[$id->value] ?? null;
    }

    public function init(): void {}

    public function isInitialized(): bool
    {
        return true;
    }

    public function reset(): void
    {
        $this->balances = [];
    }

    public function delete(): void
    {
        $this->balances = [];
    }

    public function persist(): void {}
}

// --- Wiring ---------------------------------------------------------------------------

$types = new MessageTypeMap(AccountOpened::class, MoneyDeposited::class);
$causation = new CausationMiddleware('actor');
$envelopes = new EnvelopeFactory($types, new SystemClock(), $causation);

$eventStore = new InMemoryEventStore(new MessageSerializer($types));
$transactions = $eventStore;

$accounts = new AggregateRepository(Account::class, 'account', $eventStore, $envelopes);
$balances = new Balances();
$handlers = new HandlerMap()->register(new AccountHandlers($accounts, $balances));

$commands = new DefaultCommandBus(
    $handlers,
    $envelopes,
    $causation,
    new RetryOnConflictMiddleware(3, $transactions),
    new TransactionMiddleware($transactions),
);
$queries = new DefaultQueryBus($handlers, $envelopes);

$projections = new ProjectionManager($eventStore, new InMemoryProjectionStore(), $transactions, $envelopes);

// --- Use ------------------------------------------------------------------------------

$id = AccountId::generate();

$commands->dispatch(new OpenAccount($id, 'Ada', new Currency('EUR')), new Metadata(['actor' => 'teller-7']));
$commands->dispatch(new DepositMoney($id, Money::EUR(12_000)));
$commands->dispatch(new DepositMoney($id, Money::EUR(3_050)));

$projections->readModelProjection('balances', $balances)
    ->fromCategory('account')
    ->when([
        MoneyDeposited::class => static fn(array $state, MoneyDeposited $event) => $balances->add(
            $event->accountId,
            $event->money,
        ),
    ])
    ->run(keepRunning: false);

$balance = $queries->ask(new GetBalance($id)) ?? Money::EUR(0);

printf(
    "Balance of %s: %s %s\n\n",
    $id,
    new DecimalMoneyFormatter(new ISOCurrencies())->format($balance),
    $balance->getCurrency(),
);

foreach ($eventStore->load($accounts->streamName($id)) as $recorded) {
    printf(
        "v%d  %-24s  caused by %s  actor %s\n",
        $recorded->streamVersion,
        $recorded->name,
        basename(str_replace('\\', '/', (string) $recorded->metadata->get(Metadata::CAUSATION_NAME))),
        $recorded->metadata->get('actor') ?? '-',
    );
}
