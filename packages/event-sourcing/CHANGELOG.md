# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Messages: `Command`, `Query` and `DomainEvent` markers, `Envelope`, `Metadata`,
  `#[MessageName]`, `MessageTypeMap`, `EnvelopeFactory` and `MetadataEnricher`.
- Buses: command, query and event buses with a middleware pipeline, `HandlerMap` with
  `#[Handles]` discovery and lazy PSR-11 resolution, and the transaction, retry,
  causation, logging, authorization, sequential dispatch and async middlewares.
- PSR-3 logging of handled and failed messages, retries, the lifecycle and failures of
  projections, and unreadable snapshots.
- Event store port with expected versions, metadata matchers, selectors and
  commit-ordered positions; in-memory adapter; `PublishingEventStore` decorator.
- `TransactionManager` port with nested units of work and after-commit callbacks.
- Aggregates: `AggregateRoot`, typed `AggregateId`, generic `AggregateRepository`.
- Projections: queries, projectors with `emit()` and `linkTo()`, read-model projectors,
  and a `ProjectionManager` to stop, reset and delete them remotely.
- Snapshots: `SnapshotStore` port, inline snapshotting and `SnapshotReadModel`.
- Serialization: `ReflectionPayloadSerializer`, `SerializablePayload`, `#[ListOf]`,
  `MessageSerializer`, upcasters.
- PostgreSQL adapters for the event store, projection checkpoints and snapshots, with
  `PostgresSchema`, `PdoTransactionManager` and `NotificationWaiter`.
- Testing support: `AggregateScenario` and the `EventStoreContract`,
  `ProjectionStoreContract` and `SnapshotStoreContract` suites.
- `ReflectionPayloadSerializer` keeps values that already are objects of the expected class
  when rebuilding a message.
- Runnable banking example and test fixtures modelling amounts with `moneyphp/money`, a
  development dependency only.
