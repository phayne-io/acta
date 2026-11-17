# Messages, buses and middlewares

## Messages

A message is a `final readonly` class implementing one of three marker interfaces:

| Interface | Meaning | Handlers | Returns |
| --- | --- | --- | --- |
| `Command` | ask for a change | exactly one | nothing |
| `Query` | ask for information | exactly one | the answer |
| `DomainEvent` | something happened | any number | nothing |

Messages hold business data only. The identifier, the creation date and the metadata of
a message live in its `Envelope`, created by the `EnvelopeFactory` when the message is
dispatched or recorded.

```php
$envelope->message;    // the command, query or event
$envelope->id;         // Uuid, version 7
$envelope->name;       // public name
$envelope->createdAt;  // DateTimeImmutable
$envelope->metadata;   // Metadata
```

`Metadata` is an immutable bag for what the infrastructure needs: correlation, causation,
actor, tenant. Share one `EnvelopeFactory`, and the `MessageTypeMap` it uses, across the
buses, the repositories and the projections of an application.

## Handlers

`HandlerMap` maps message classes to handlers.

```php
$handlers = new HandlerMap($container)
    ->on(OpenAccount::class, $callable)                 // any callable
    ->register(new AccountHandlers($accounts))          // objects with #[Handles] methods
    ->registerLazy(SendWelcomeLetterHandler::class);    // fetched from the PSR-11 container on first use
```

A handler receives the message and, optionally, its envelope:

```php
#[Handles]
public function open(OpenAccount $command, Envelope $envelope): void
```

The handled class is the type of the first parameter. Use a union type to handle several
messages with one method, or pass the class to the attribute: `#[Handles(OpenAccount::class)]`.
Put `#[Handles]` on the class itself for invokable handlers.

A handler registered for an interface or a parent class also receives the messages that
implement or extend it. This is how one listener can observe every `DomainEvent`.

## Buses

```php
$commands = new DefaultCommandBus($handlers, $envelopes, ...$middlewares);
$queries  = new DefaultQueryBus($handlers, $envelopes, ...$middlewares);
$events   = new DefaultEventBus($listeners, $envelopes, collectErrors: false);

$commands->dispatch(new OpenAccount($id, 'Ada'), new Metadata(['actor' => 'ada']));
$balance = $queries->ask(new GetBalance($id));
$events->publish(new AccountOpened($id, 'Ada'));
```

Application code depends on the `CommandBus`, `QueryBus` and `EventBus` interfaces. They
are the primary ports your HTTP handlers, console commands and webhook receivers call.

A command or a query without handler, or with several, raises `HandlerNotFound`. An event
may have no listener. By default the event bus stops at the first listener that fails;
with `collectErrors: true` it calls them all and throws one `ListenerFailures`.

## Middlewares

A middleware wraps the handling of a message. Middlewares are given outermost first.

```php
final readonly class TenantMiddleware implements Middleware
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(Envelope $envelope, Closure $next): mixed
    {
        return $this->tenants->within($envelope->metadata->get('tenant'), fn() => $next($envelope));
    }
}
```

| Middleware | Role |
| --- | --- |
| `CausationMiddleware` | Gives each message a correlation id and stamps what it produces with its id and name as cause. Can propagate chosen metadata keys, such as the actor. Also register it as an enricher of the `EnvelopeFactory`. |
| `LoggingMiddleware` | Reports each handled or failed message to a PSR-3 logger. |
| `RetryOnConflictMiddleware` | Replays a command that failed on a `ConcurrencyConflict`. Place it outside the transaction. |
| `TransactionMiddleware` | Handles each message in a transaction. |
| `AuthorizationMiddleware` | Asks an `Authorizer` before any handler runs; throws `AccessDenied`. |
| `SequentialDispatchMiddleware` | Queues commands dispatched from a handler until the current command is done. |
| `AsyncMiddleware` | Hands `AsyncMessage`s over to a `MessageProducer` instead of handling them. |

A typical command bus:

```php
new DefaultCommandBus(
    $handlers,
    $envelopes,
    $causation,
    new LoggingMiddleware($logger),
    new AuthorizationMiddleware($authorizer),
    new RetryOnConflictMiddleware(3, $transactions, $logger),
    new TransactionMiddleware($transactions),
);
```

## Logging

The library logs through PSR-3 and ships no logger of its own: pass Monolog or any other
`Psr\Log\LoggerInterface`. Every logger parameter defaults to `NullLogger`, so logging
is opt-in.

| Where | Level | What |
| --- | --- | --- |
| `LoggingMiddleware` | `info`, configurable | a message was handled, with its duration |
| `LoggingMiddleware` | `error` | the handling of a message failed; the exception is rethrown |
| `RetryOnConflictMiddleware` | `warning` | a command is retried after a concurrency conflict |
| `ProjectionManager` | `info`, `notice` | a projection started, stopped, was reset or deleted |
| `ProjectionManager` | `debug` | a projection handled a batch |
| `ProjectionManager` | `error` | a projection failed; the exception is rethrown |
| `PostgresSnapshotStore` | `warning` | a stored snapshot cannot be read and is ignored |

Records use PSR-3 placeholders and a structured context: `message`, `message_id`, `kind`,
`correlation_id`, `causation_id`, `duration_ms` for messages; `projection`, `position`
for projections; `exception` whenever there is one. The payload of a message is never
logged, since it may hold personal data.

Put `LoggingMiddleware` after `CausationMiddleware`, so that the correlation identifier
is set, and before the retry and transaction middlewares, to get one record per message
rather than one per attempt.

## Asynchronous messages

Mark a message with `AsyncMessage` and add `AsyncMiddleware` to the bus. The first
dispatch flags the envelope and gives it to your `MessageProducer`, which sends it to a
queue. The consumer rebuilds the envelope and dispatches it on a bus using the same
middleware: the flag is there, so this time the handler runs.

```php
final readonly class SqsProducer implements MessageProducer
{
    public function produce(Envelope $envelope): void
    {
        $body = json_encode($this->serializer->serialize($envelope)->toArray(), JSON_THROW_ON_ERROR);
        // send $body
    }
}

// In the worker:
[$envelope] = $serializer->deserialize(SerializedMessage::fromArray(json_decode($body, true)));
$commands->dispatch($envelope);
```
