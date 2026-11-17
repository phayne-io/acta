# Aggregates, snapshots and testing

## Aggregates

Extend `AggregateRoot`. A business method validates, then calls `recordThat()` with the
event describing what happened. State changes in `apply()` and nowhere else, because
`apply()` is also what rebuilds the aggregate from its history.

```php
final class Account extends AggregateRoot
{
    private AccountId $id;
    private Money $balance;

    public function withdraw(Money $money): void
    {
        $money->lessThanOrEqual($this->balance) || throw new InsufficientFunds();

        $this->recordThat(new MoneyWithdrawn($this->id, $money));
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof AccountOpened => [$this->id, $this->balance] = [$event->accountId, new Money(0, $event->currency)],
            $event instanceof MoneyDeposited => $this->balance = $this->balance->add($event->money),
            $event instanceof MoneyWithdrawn => $this->balance = $this->balance->subtract($event->money),
            default => null,
        };
    }
}
```

The banking examples of this documentation model amounts with
[moneyphp/money](https://github.com/moneyphp/money).

`apply()` must not fail: the event already happened. Keep in the aggregate only the state
its invariants need; everything else belongs to read models.

Aggregates are rebuilt without calling their constructor, so it can take any shape. The
public API of an aggregate contains your business methods, `aggregateId()` and the
read-only `$version`. What the infrastructure needs is protected and reached internally.

### Identifiers

```php
final readonly class AccountId extends AggregateId {}

$id = AccountId::generate();            // version 7 UUID
$id = AccountId::fromString($request);  // any non-empty string without whitespace
```

## Repositories

`AggregateRepository` is generic. Wrap it in the repository of your domain, behind an
interface the domain owns:

```php
interface Accounts
{
    public function get(AccountId $id): Account;
    public function save(Account $account): void;
}

final readonly class EventSourcedAccounts implements Accounts
{
    /** @param AggregateRepository<Account> $repository */
    public function __construct(private AggregateRepository $repository) {}

    public function get(AccountId $id): Account
    {
        return $this->repository->get($id);
    }

    public function save(Account $account): void
    {
        $this->repository->save($account);
    }
}

$accounts = new EventSourcedAccounts(
    new AggregateRepository(Account::class, 'account', $eventStore, $envelopes),
);
```

`save()` appends the recorded events to the stream `account-<id>`, expecting the version
the aggregate was loaded at. If someone else changed the aggregate meanwhile, it throws
`ConcurrencyConflict`. `find()` returns `null` for an unknown aggregate, `get()` throws
`AggregateNotFound`.

## Snapshots

A snapshot saves the state of an aggregate so that only the events recorded since need
to be replayed. Snapshots are a cache: the stream remains the source of truth. Remove
them when the properties of the aggregate class change, with
`SnapshotStore::removeAll('account')`.

Two strategies are available.

**Inline**, at a fixed interval, when the aggregate is saved:

```php
new AggregateRepository(Account::class, 'account', $eventStore, $envelopes, $snapshots, snapshotEvery: 100);
```

**In the background**, with a projection, so that command handling is not slowed down:

```php
$repository = new AggregateRepository(Account::class, 'account', $eventStore, $envelopes, $snapshots);

new SnapshotReadModel($repository, $snapshots)
    ->projection($projections, 'account_snapshots')
    ->run();
```

`AggregateRepository::takeSnapshot($id)` takes one on demand.

Aggregates are snapshotted with PHP serialization, so they must be serializable. Most
are, as long as they hold values and no services.

## Testing aggregates

`AggregateScenario` expresses a test as given events, when a behaviour, then events. It
needs no event store and no test framework.

```php
AggregateScenario::for(Account::class)
    ->given(new AccountOpened($id, 'Ada'), new MoneyDeposited($id, Money::EUR(100)))
    ->when(fn(Account $account) => $account->withdraw(Money::EUR(30)))
    ->then(new MoneyWithdrawn($id, Money::EUR(30)));

AggregateScenario::for(Account::class)
    ->when(fn() => Account::open($id, 'Ada'))      // creation: return the aggregate
    ->then(new AccountOpened($id, 'Ada'));

AggregateScenario::for(Account::class)
    ->given(new AccountOpened($id, 'Ada'))
    ->when(fn(Account $account) => $account->withdraw(1))
    ->thenThrows(InsufficientFunds::class);
```

Use `->aggregate()` after `then()` to assert on the resulting state.

## Testing the rest

- Use the in-memory adapters for handlers and projections: `InMemoryEventStore`,
  `InMemoryProjectionStore`, `InMemorySnapshotStore`. The event store is also a
  `TransactionManager`.
- Inject a PSR-20 clock into the `EnvelopeFactory` to control time.
- If you write an adapter, extend the contract suites of `Acta\EventSourcing\Testing`:
  `EventStoreContract`, `ProjectionStoreContract`, `SnapshotStoreContract`. They require
  PHPUnit.
