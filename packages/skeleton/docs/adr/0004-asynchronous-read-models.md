# 0004. Read models are projected asynchronously

- Status: accepted
- Date: 2026-11-17

## Context

Queries are answered from read models built from the events. They can be updated in
the transaction of the command (always up to date, but every read model then slows
down and can fail every write) or after it (writes stay fast and independent, but
reads lag).

## Decision

Projections run in their own processes and follow the event log, woken up by
PostgreSQL notifications. Command endpoints answer with what is certain at that point —
a status, an identifier, a `Location` — and never with a representation read from a
read model.

For machines where no worker runs, `PROJECTIONS_AFTER_COMMAND` brings the listed
projections up to date right after each command commits. It is a development and test
convenience, off by default.

## Consequences

- A write depends on the event store only. A broken projection does not stop the
  business; it is fixed and catches up.
- Clients must not assume that a `GET` reflects the `POST` they just made. In practice
  the lag is milliseconds; the API still does not promise it.
- Optimistic concurrency is enforced on the write side: `If-Match` is checked against
  the aggregate, not against a read model that may be behind.
- Workers are part of the deployment: one per projection, restarted by the orchestrator.
- A read model that must be consistent with the write can be projected inline; that is
  a per-case decision to record here when it is made.
