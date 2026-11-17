# Acta Event Sourcing

CQRS and event sourcing building blocks for PHP 8.5+, in one package and without any
third-party runtime dependency.

Acta takes the concepts that made [Prooph](https://github.com/prooph) a reference
(immutable messages, typed buses, an event store with streams, event-sourced aggregates,
persistent projections, snapshots, upcasting) and rebuilds them for today's PHP. What
Prooph spread over a dozen repositories and several libraries fits here in a single
namespace that requires three PSR interface packages and nothing else.

- **Messages** are plain `final readonly` classes. No base class, no payload array.
- **Buses** for commands, queries and events, extended with middlewares.
- **Event store** with optimistic concurrency, a gap-free global order and selectors by
  stream, category, event name or metadata.
- **Aggregates** whose public API contains business methods only.
- **Projections** (queries, projectors, read-model projectors) written once for every
  storage, with remote stop, reset and delete.
- **Snapshots**, taken inline or in the background.
- **Logging** through any PSR-3 logger: handled and failed messages, retries, projections.
- **Serialization** driven by constructor signatures, with upcasting.
- **Adapters** in memory and for PostgreSQL, verified by the same contract test suites,
  which are shipped so you can check your own adapters.

## Requirements

- PHP 8.5 or later
- `psr/clock`, `psr/container` and `psr/log` (interfaces only)
- For the PostgreSQL adapters: `ext-pdo_pgsql` and PostgreSQL 14 or later

## Installation

```bash
composer require acta/event-sourcing
```

## Quick start

The snippets below are taken from [`examples/quickstart.php`](examples/quickstart.php),
which runs as is. The example models amounts with
[moneyphp/money](https://github.com/moneyphp/money); the library itself does not depend
on it.

### 1. Model the domain

Events are facts, named in the past tense. An aggregate checks its invariants in a
business method, records the event, and changes its state in `apply()` only.

```php
final readonly class AccountId extends AggregateId {}

#[MessageName('banking.money-deposited')]
final readonly class MoneyDeposited implements DomainEvent
{
    public function __construct(public AccountId $accountId, public Money $money) {}
}

final class Account extends AggregateRoot
{
    private AccountId $id;

    private Currency $currency;

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
```

### 2. Handle commands and queries

```php
final readonly class AccountHandlers
{
    /** @param AggregateRepository<Account> $accounts */
    public function __construct(private AggregateRepository $accounts, private Balances $balances) {}

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
```

### 3. Wire it

```php
$types = new MessageTypeMap(AccountOpened::class, MoneyDeposited::class);
$causation = new CausationMiddleware('actor');
$envelopes = new EnvelopeFactory($types, new SystemClock(), $causation);

$eventStore = new InMemoryEventStore(new MessageSerializer($types));
$transactions = $eventStore;

$accounts = new AggregateRepository(Account::class, 'account', $eventStore, $envelopes);
$handlers = new HandlerMap()->register(new AccountHandlers($accounts, $balances));

$commands = new DefaultCommandBus(
    $handlers,
    $envelopes,
    $causation,                                      // correlation and causation ids
    new RetryOnConflictMiddleware(3, $transactions), // replays a command on a write conflict
    new TransactionMiddleware($transactions),        // one transaction per command
);
$queries = new DefaultQueryBus($handlers, $envelopes);
```

### 4. Use it

```php
$commands->dispatch(new OpenAccount($id, 'Ada', new Currency('EUR')), new Metadata(['actor' => 'teller-7']));
$commands->dispatch(new DepositMoney($id, Money::EUR(12_000)));

$projections->readModelProjection('balances', $balances)
    ->fromCategory('account')
    ->when([
        MoneyDeposited::class => fn(array $state, MoneyDeposited $event) => $balances->add(
            $event->accountId,
            $event->money,
        ),
    ])
    ->run(keepRunning: false);

$queries->ask(new GetBalance($id)); // Money::EUR(12_000), that is 120.00 EUR
```

## Going to PostgreSQL

Create the tables once, then swap the adapters. Nothing else changes.

```php
$pdo = PDO::connect('pgsql:host=127.0.0.1;dbname=app', 'app', 'secret');

new PostgresSchema()->install($pdo);          // or copy ->statements() into your migrations

$transactions = new PdoTransactionManager($pdo);
$eventStore = new PostgresEventStore($pdo, new MessageSerializer($types));
$snapshots = new PostgresSnapshotStore($pdo);
$projections = new ProjectionManager($eventStore, new PostgresProjectionStore($pdo), $transactions, $envelopes);
```

Give the same connection and the same transaction manager to everything that must commit
together: the events of a command, the read models updated inline, the checkpoint of a
projection and the rows it wrote.

## Documentation

| Guide | What it covers |
| --- | --- |
| [Coming from Prooph](docs/coming-from-prooph.md) | Where each Prooph concept went, what was removed and why |
| [Messages, buses and middlewares](docs/messages-and-buses.md) | Envelopes, metadata, handlers, the provided middlewares, logging, async messages |
| [Event store](docs/event-store.md) | Streams, expected versions, selectors, transactions, the PostgreSQL schema and its ordering guarantee |
| [Projections](docs/projections.md) | Queries, projectors, read models, running and controlling them |
| [Serialization and upcasting](docs/serialization.md) | Message names, payload conventions, evolving events |
| [Aggregates, snapshots and testing](docs/aggregates.md) | Repositories, snapshot strategies, `AggregateScenario`, contract suites |

Every public class and method is documented in the source as well.

## Development

```bash
docker compose up -d      # PostgreSQL for the integration tests
composer install
composer check            # coding standard, static analysis, tests
composer test:unit        # tests that do not need PostgreSQL
```

The PostgreSQL tests read their connection from the `ACTA_TEST_POSTGRES_*` environment
variables, see [`phpunit.xml.dist`](phpunit.xml.dist), and are skipped when the server
cannot be reached.

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

Released under the [MIT License](LICENSE).
