# Event store

The event store is an append-only log of events grouped in streams. It is a port,
`EventStore`, with two adapters: `InMemoryEventStore` and `PostgresEventStore`.

## Streams

A stream is named `<category>-<id>`, for instance `account-018f4e3a-…`. The category is
everything before the first dash. There is no call to create a stream: it exists as soon
as it holds an event.

```php
$stream = StreamName::for('account', $accountId);

$stream->category; // "account"
$stream->id;       // the identifier
```

## Appending

Every append states what the writer believes the stream looks like. The store refuses
the write with a `ConcurrencyConflict` when that belief is wrong.

```php
$recorded = $eventStore->append($stream, ExpectedVersion::exactly(3), $envelope);

$recorded[0]->streamVersion; // 4
$recorded[0]->position;      // place in the global log
```

| Expectation | The append succeeds when |
| --- | --- |
| `ExpectedVersion::noStream()` | the stream does not exist |
| `ExpectedVersion::exactly(n)` | the last event of the stream has version `n` |
| `ExpectedVersion::streamExists()` | the stream holds at least one event |
| `ExpectedVersion::any()` | always |

`AggregateRepository::save()` uses `exactly()` with the version the aggregate was loaded
at, which is what makes two concurrent changes to the same aggregate conflict.

## Reading one stream

```php
$eventStore->load($stream);                       // from the first event
$eventStore->load($stream, fromVersion: 10);      // what a snapshot leaves to replay
$eventStore->loadReverse($stream, count: 1);      // the last event
$eventStore->load($stream, matcher: MetadataMatcher::where('tenant', Operator::Equals, 'acme'));
```

## Reading the global log

Projections and subscribers read across streams, in commit order, and resume from the
position of the last event they handled.

```php
$events = $eventStore->read(Selector::category('account'), after: $lastPosition, count: 500);
```

| Selector | Events |
| --- | --- |
| `Selector::all()` | every event |
| `Selector::category('account', 'customer')` | every stream of these categories |
| `Selector::stream('account-42')` | these streams |
| `->orCategory(…)`, `->orStream(…)` | widens the selection |
| `->named('banking.account-opened')` | keeps these event names |
| `->matching($metadataMatcher)` | keeps events whose metadata matches |

Filters apply to events as they are stored, before upcasting. `$count` limits the number
of stored events read: when an upcaster splits one stored event in two, both are
returned.

## Transactions

`TransactionManager` delimits a unit of work. `InMemoryEventStore` implements it; for
PostgreSQL use `PdoTransactionManager` on the connection shared by the adapters.

```php
$transactions->transactional(function () use ($accounts, $account): void {
    $accounts->save($account);          // events
    $balances->add($id, 100);           // a read model updated in the same transaction
});

$transactions->afterCommit(fn() => $mailer->send($receipt)); // skipped on rollback
```

Calls nest: only the outermost one commits. A nested failure marks the whole transaction
for rollback, even if the exception is caught on the way.

On a command bus, `TransactionMiddleware` opens the transaction and
`RetryOnConflictMiddleware`, placed before it, replays the command when it conflicts.

## Publishing events in process

`PublishingEventStore` decorates an event store and publishes what was appended on an
`EventBus`, after the commit:

```php
$eventStore = new PublishingEventStore($postgresEventStore, $eventBus, $transactions);
```

Use it for reactions that may be lost if the process dies right after the commit. For
anything that must eventually happen, feed it from a persistent
[projection](projections.md), which resumes from its checkpoint.

### Inline projections

Leave the transaction manager out and events are published as soon as they are appended,
inside the transaction of the command being handled:

```php
$eventStore = new PublishingEventStore($postgresEventStore, $eventBus);
```

A listener that updates a read model on the same connection then commits or rolls back
together with the events. The read model is consistent with the write the moment the
command returns, which removes the need to wait for a projection. The price is coupling:
a failing listener now fails the command, and the write gets slower with every listener.
Reserve it for the few read models that truly need read-your-writes consistency.

## PostgreSQL

`PostgresSchema` holds the DDL. All events live in one table:

```sql
CREATE TABLE event_store (
    position        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_id  XID8        NOT NULL DEFAULT pg_current_xact_id(),
    event_id        UUID        NOT NULL,
    stream_name     TEXT        NOT NULL,
    stream_category TEXT        NOT NULL,
    stream_version  INTEGER     NOT NULL CHECK (stream_version > 0),
    event_name      TEXT        NOT NULL,
    schema_version  SMALLINT    NOT NULL DEFAULT 1,
    payload         JSONB       NOT NULL,
    metadata        JSONB       NOT NULL DEFAULT '{}',
    created_at      TIMESTAMPTZ NOT NULL,
    CONSTRAINT event_store_event_id_key UNIQUE (event_id),
    CONSTRAINT event_store_stream_version_key UNIQUE (stream_name, stream_version)
);
```

### Optimistic concurrency

The unique constraint on `(stream_name, stream_version)` is the lock. Two transactions
appending the same version cannot both commit: the second one fails with a unique
violation, reported as a `ConcurrencyConflict`. No row or advisory lock is taken.

### Why positions are a pair

A sequence gives increasing numbers, not numbers that become visible in order. Consider
two concurrent transactions:

1. transaction A takes sequence 41 and is slow to commit;
2. transaction B takes sequence 42 and commits;
3. a projection reads up to 42 and saves its checkpoint;
4. A commits. Event 41 is now behind the checkpoint and is never projected.

Acta stores the transaction identifier next to each event and reads the log ordered by
`(transaction_id, position)`, stopping before the oldest transaction still in progress:

```sql
SELECT … FROM event_store
WHERE (transaction_id, position) > (:last_transaction, :last_position)
  AND transaction_id < pg_snapshot_xmin(pg_current_snapshot())
ORDER BY transaction_id, position;
```

In the scenario above the projection sees nothing until A has finished, then receives 41
and 42. Nothing is skipped and there is no gap detection, no retry and no timeout to
tune. The cost is that a transaction left open for a long time holds projections back:
keep write transactions short.

This is why `Position` holds a transaction number and a sequence number. Treat it as an
opaque cursor.

### Waking up projections

`PostgresEventStore` sends a `NOTIFY` on each append. A projection can wait on it with
`NotificationWaiter` instead of polling, see [projections](projections.md).

## Writing an adapter

Implement `EventStore`, then extend `Acta\EventSourcing\Testing\EventStoreContract` in
your test suite:

```php
final class MyEventStoreTest extends EventStoreContract
{
    protected function createEventStore(): EventStore
    {
        return new MyEventStore(new MessageSerializer(self::messageTypes()));
    }
}
```

The guarantee that matters most, and that only your storage can provide, is the one
described above: an event returned by `read()` must never be preceded, later on, by an
event the reader has not seen.
