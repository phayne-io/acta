# Coming from Prooph

Acta keeps the concepts of the Prooph components and changes how they are packaged and
implemented. This page maps one to the other.

## Packages

| Prooph package | In Acta |
| --- | --- |
| `prooph/common` | `Acta\EventSourcing\Message`, `Acta\EventSourcing\Serializer` |
| `prooph/service-bus` | `Acta\EventSourcing\Bus` |
| `prooph/event-store` | `Acta\EventSourcing\EventStore`, `Acta\EventSourcing\Projection` |
| `prooph/event-sourcing` | `Acta\EventSourcing\Aggregate` |
| `prooph/snapshot-store`, `prooph/snapshotter` | `Acta\EventSourcing\Snapshot` |
| `prooph/pdo-event-store`, `prooph/pdo-snapshot-store` | `Acta\EventSourcing\Postgres` |
| `prooph/event-store-bus-bridge` | `PublishingEventStore`, `TransactionMiddleware`, `CausationMiddleware` |
| `prooph/standard-projections` | Not needed: `Selector::all()`, `Selector::category()`, `Selector::named()` |

## Dependencies

| Prooph relied on | Acta uses |
| --- | --- |
| `ramsey/uuid` | `Acta\EventSourcing\Uuid`, a version 7 generator of about fifty lines |
| `beberlei/assert` | Native types and value objects |
| `marc-mabe/php-enum` | Native enums (`ProjectionStatus`, `Operator`, `MessageKind`) |
| `react/promise` in the query bus | The query bus returns the answer directly |
| `sandrokeil/interop-config`, container factories | Plain constructors; wire them in the container of your choice |
| `psr/container` | `psr/container`, for lazily resolved handlers only |
| — | `psr/clock`, so that time can be controlled in tests |
| — | `psr/log`, to report handled messages, retries and projections to any PSR-3 logger |

## Concepts

### Messages

| Prooph | Acta | What changed |
| --- | --- | --- |
| `Message`, `DomainMessage`, `Command`, `DomainEvent`, `Query` base classes | `Command`, `DomainEvent`, `Query` marker interfaces | Messages are plain objects with typed properties |
| `uuid()`, `createdAt()`, `metadata()` on the message | `Envelope` | The envelope carries what is not business data |
| `PayloadTrait`, `payload()` | Constructor promotion | The payload is derived from the constructor |
| `messageName()`, `FQCNMessageFactory` | `#[MessageName]`, `MessageTypeMap` | A stable public name, the class name by default |
| `MessageConverter`, `MessageFactory` | `MessageSerializer`, `PayloadSerializer` | One object does both directions |
| `MetadataEnricher` | `MetadataEnricher` | Applied by the `EnvelopeFactory` |
| `UpcastingPlugin`, `Upcaster` | `Upcaster`, `UpcasterChain` | Runs on the stored form, inside the serializer |

### Buses

| Prooph | Acta | What changed |
| --- | --- | --- |
| `CommandBus`, `EventBus`, `QueryBus` | Same names, as interfaces, with `Default*` implementations | |
| Plugins and `ActionEvent` listeners | `Middleware` | Code before and after `$next($envelope)` |
| `CommandRouter`, `EventRouter`, `QueryRouter` | `HandlerMap::on()` | |
| `ServiceLocatorPlugin` | `HandlerMap::registerLazy()` | PSR-11, resolved on first use |
| `InvokeStrategy` (`handle`, `on<Event>`...) | `#[Handles]` | Any method, discovered by its parameter type |
| `RouteGuard`, `AuthorizationService` | `AuthorizationMiddleware`, `Authorizer` | |
| `AsyncMessage`, `AsyncSwitchMessageRouter`, `MessageProducer` | `AsyncMessage`, `AsyncMiddleware`, `MessageProducer` | Same switch on a metadata flag |
| Command queueing inside `CommandBus` | `SequentialDispatchMiddleware` | Opt-in |
| `ListenerExceptionCollectionMode` | `DefaultEventBus(collectErrors: true)` | Throws `ListenerFailures` |

### Event store

| Prooph | Acta | What changed |
| --- | --- | --- |
| `EventStore`, `ReadOnlyEventStore` | Same names | |
| `create(Stream)`, stream metadata | — | Streams are implicit: appending creates them |
| `appendTo()` | `append()` with an `ExpectedVersion` | Concurrency is checked by the store, on every append |
| `load()`, `loadReverse()` with a `MetadataMatcher` | Same | |
| `fetchStreamNames()`, `fetchCategoryNames()` | `streamNames()`, `categoryNames()` | The regular expression variants are gone |
| `_aggregate_version`, `_position` metadata | `RecordedEvent::$streamVersion`, `$position` | Typed, separate from metadata |
| `TransactionalEventStore` | `TransactionManager` | A port shared with read models and snapshots |
| `ActionEventEmitterEventStore` and its plugins | Decorators, such as `PublishingEventStore` | |
| Persistence strategies, one table per stream | One table | See [the event store guide](event-store.md) |
| Gap detection in PDO projectors | Commit-ordered positions | No retry, no timeout |

### Aggregates

| Prooph | Acta | What changed |
| --- | --- | --- |
| `AggregateRoot::recordThat()`, `apply()` | Same | |
| `AggregateChanged` | Any `DomainEvent` | The event does not know its aggregate version |
| `AggregateTranslator`, `AggregateRootDecorator` | `AggregateAccess` (internal) | Nothing to configure |
| `AggregateType` | The `$category` of the repository | |
| `AggregateRepository` | `AggregateRepository<T>` | Generic, final, composed rather than extended |
| Identity map | — | The repository is stateless |
| One stream per aggregate type, by default | One stream per aggregate, always | `<category>-<id>` |

### Projections

| Prooph | Acta | What changed |
| --- | --- | --- |
| `ProjectionManager::createQuery()` | `ProjectionManager::query()` | |
| `createProjection()` | `projection()` | |
| `createReadModelProjection()` | `readModelProjection()` | |
| `fromStream()`, `fromStreams()`, `fromCategory()`, `fromCategories()`, `fromAll()` | `fromStream()`, `fromCategory()`, `fromAll()`, `from(Selector)` | Variadic; a selector can also filter by name and metadata |
| Handlers bound to the projector (`$this->emit()`) | The projection is the fourth argument of handlers | Plain closures |
| Handlers keyed by message name | Handlers keyed by event class | |
| `emit()`, `linkTo()` | Same | A linked event is a copy that points to its origin |
| `ReadModel::stack()` | — | A batch is a transaction; `persist()` remains |
| One projector class per database | One engine, a `ProjectionStore` port | |
| `locked_until` timestamps | PostgreSQL advisory locks | Released automatically when a process dies |
| `stop`, `reset`, `delete` through the status column | Same | Also honoured when the projection starts |

### Snapshots

| Prooph | Acta | What changed |
| --- | --- | --- |
| `SnapshotStore`, `Snapshot` | Same | |
| `TakeSnapshot` command, `Snapshotter` | `AggregateRepository::takeSnapshot()` | |
| `SnapshotReadModel` and its projections | `SnapshotReadModel::projection()` | |
| — | `AggregateRepository(snapshotEvery: 100)` | Inline alternative |
| `CallbackSerializer` | Two closures on `PostgresSnapshotStore` | |

## What is intentionally missing

- **Stream metadata and explicit stream creation.** They existed mostly to support the
  persistence strategies.
- **The plugin system.** Middlewares and decorators cover the same needs with code that
  is easier to follow and to type-check.
- **MySQL, MariaDB, MongoDB and ArangoDB adapters.** The ports are small; the contract
  suites in `Acta\EventSourcing\Testing` tell you when an adapter is correct.
- **Message producers for specific brokers.** `MessageProducer` is a port; adapters
  belong to the application or to dedicated packages.
