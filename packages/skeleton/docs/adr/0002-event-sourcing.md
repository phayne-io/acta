# 0002. Aggregates are event-sourced, in PostgreSQL

- Status: accepted
- Date: 2026-11-17

## Context

The business needs to know not only the state of things but how they got there: who
did what, when, in response to what. Other systems need to be told what happened.
Read needs change faster than the rules do.

## Decision

Aggregates record domain events and are rebuilt from them (`acta/event-sourcing`). The
events are the source of truth; they are appended to a single PostgreSQL table with
optimistic concurrency per aggregate. Everything else — read models, the webhook
outbox — is derived from the event log by projections and can be rebuilt.

Every event carries who caused it (`actor`), the request it belongs to
(`correlation_id`) and the message that led to it (`causation_id`).

PostgreSQL is the only infrastructure dependency: event store, read models, queues and
locks all live in it, in one transaction where that matters.

## Consequences

- The audit trail is the data, not a side effect that can drift from it.
- A new read model is a new projection replayed over the history; no migration of data.
- Events are a contract with the future: once recorded, an event is never changed. Its
  stored name is set with `#[MessageName]` so that classes can still be renamed, and a
  change of shape goes through an upcaster.
- Developers must think in events and accept that reads lag behind writes
  ([0004](0004-asynchronous-read-models.md)).
- An aggregate with a very long history needs snapshots; the library provides them.
