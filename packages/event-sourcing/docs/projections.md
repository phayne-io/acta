# Projections

A projection reads events and derives something from them. `ProjectionManager` creates
the three kinds:

| Kind | Persisted | Produces | Use it to |
| --- | --- | --- | --- |
| `query()` | no | a state, in memory | answer a one-off question |
| `projection()` | position and state | a state, optionally new events | keep counters, derive streams |
| `readModelProjection()` | position and state | a `ReadModel` | maintain query-optimized tables |

```php
$projections = new ProjectionManager($eventStore, $projectionStore, $transactions, $envelopes, $logger);
```

The optional PSR-3 logger receives the lifecycle of persistent projections: start and
stop, reset and deletion, each batch at the debug level, and failures at the error level
with the position reached.

## Describing a projection

```php
$projections->projection('deposits')
    ->init(fn(): array => ['count' => 0, 'total' => 0])
    ->fromCategory('account')
    ->when([
        MoneyDeposited::class => fn(array $state, MoneyDeposited $event): array => [
            'count' => $state['count'] + 1,
            'total' => $state['total'] + (int) $event->money->getAmount(),
        ],
    ])
    ->run(keepRunning: false);

$projections->state('deposits'); // ['count' => 12, 'total' => 340000], in minor units
```

- **Source**: `fromAll()`, `fromCategory(...)`, `fromStream(...)`, or `from($selector)`
  to also filter by event name or metadata.
- **Handlers**: `when()` maps event classes to closures; `whenAny()` receives every event
  without a handler of its own.
- **State**: `init()` returns the initial state. A handler returns the new state, or
  nothing to leave it unchanged. The state must be JSON-serializable.

A handler receives up to four arguments:

```php
function (array $state, MoneyDeposited $event, RecordedEvent $recorded, Projector $projection): array
```

`$recorded` gives the stream, the version, the position and the metadata of the event.
`$projection` lets the handler call `stop()`, and `emit()` or `linkTo()` on a projector.

## Read models

Implement `ReadModel` for the lifecycle, and add the methods your handlers need:

```php
final readonly class Balances implements ReadModel
{
    public function __construct(private PDO $pdo) {}

    public function add(string $accountId, int $amount): void
    {
        $this->pdo->prepare('UPDATE balances SET balance = balance + ? WHERE account_id = ?')
            ->execute([$amount, $accountId]);
    }

    public function init(): void { /* CREATE TABLE … */ }
    public function isInitialized(): bool { /* does the table exist? */ }
    public function reset(): void { /* TRUNCATE … */ }
    public function delete(): void { /* DROP TABLE … */ }
    public function persist(): void {}
}

$projections->readModelProjection('balances', $balances)
    ->fromCategory('account')
    ->when([MoneyDeposited::class => fn(array $s, MoneyDeposited $e) => $balances->add($e->accountId->value, (int) $e->money->getAmount())])
    ->run();
```

When the read model uses the connection of the `TransactionManager` given to the
manager, each batch is one transaction: the rows written by the handlers and the
checkpoint commit together. A crash never leaves a read model ahead of or behind its
checkpoint, and a batch that fails is handled again from its first event on the next run.

A read model stored elsewhere cannot join that transaction. Make its handlers
idempotent, since the last batch may be replayed after a crash.

## Running

```php
$projector->run(keepRunning: false); // catch up with the log, then return
$projector->run();                   // then wait for new events until stopped
```

Only one process runs a given projection: a second `run()` throws
`ProjectionAlreadyRunning`. With PostgreSQL the lock is tied to the database connection
and disappears with it, so a crashed worker never blocks its successor.

`ProjectionOptions` tunes the loop:

```php
new ProjectionOptions(
    batchSize: 500,                  // events per transaction
    sleepMicroseconds: 5_000_000,    // pause once caught up
    dispatchSignals: true,           // lets a SIGTERM handler call $projector->stop()
    wait: new NotificationWaiter($pdo)(...), // wake up on append instead of polling
);
```

`NotificationWaiter` listens to the channel `PostgresEventStore` notifies on each append.
The projection then reacts within milliseconds while `sleepMicroseconds` becomes a mere
safety net.

## Controlling a projection from another process

```php
$projections->stop('balances');
$projections->reset('balances');                             // replay from the first event
$projections->delete('balances', deleteEmittedEvents: true); // also drops the read model or the emitted stream

$projections->status('balances');   // ProjectionStatus
$projections->position('balances'); // Position of the last event handled
$projections->names();
```

These calls record a request. The process running the projection carries it out between
two batches; if the projection is not running, the request is applied the next time it
starts. Call `reset()` or `delete()` on the projection object itself to act immediately.

Comparing `position()` with the position returned by `EventStore::append()` tells whether
a read model already reflects a given write.

## Emitting and linking events

A projector can write events:

```php
$projections->projection('large-deposits')
    ->fromCategory('account')
    ->when([
        MoneyDeposited::class => function (array $state, MoneyDeposited $event, RecordedEvent $recorded, Projector $projection): void {
            if ($event->money->greaterThanOrEqual(Money::EUR(1_000_000))) {
                $projection->linkTo('compliance-review', $recorded);  // copy to another stream
                $projection->emit(new LargeDepositDetected($event->accountId, $event->money));
            }
        },
    ])
    ->run();
```

`emit()` appends to the stream named after the projection. `linkTo()` copies the event to
the given stream; the copy has its own identifier and a `link` metadata entry pointing to
the original. Do not let a projection read the stream it writes to.

## Queries

A query is a projection without persistence. Running it again only reads what was
appended since the previous run.

```php
$query = $projections->query()
    ->init(fn(): array => ['owners' => []])
    ->fromCategory('account')
    ->when([AccountOpened::class => fn(array $s, AccountOpened $e): array => ['owners' => [...$s['owners'], $e->owner]]]);

$query->run();
$query->state();
```
