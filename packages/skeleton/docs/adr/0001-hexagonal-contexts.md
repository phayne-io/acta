# 0001. Business contexts with hexagonal layers, checked on every build

- Status: accepted
- Date: 2026-11-17

## Context

The application must outlive its delivery mechanisms. The same use cases are driven by
an API, a console and third-party webhooks today, and will be driven by something else
tomorrow; the rules of the business should not have to be touched when that happens,
nor when the database or the framework changes.

An architecture that is only described erodes: one convenient `use` at a time.

## Decision

The code is split into business contexts under `src/`, each with four layers: `Domain`,
`Application`, `Infrastructure\Primary` (what drives the application) and
`Infrastructure\Secondary` (what it drives). Dependencies point inwards. The application
states what it needs as ports; adapters implement them.

The rules are executable. Deptrac (`deptrac.yaml`, `composer architecture`) checks the
layers; `ContextIsolationTest` checks that no context uses the domain or the application
of another. Both run in the continuous integration.

What is not business — the wiring of the libraries — lives in `src/Platform`, which
knows no context.

## Consequences

- A use case is written once and reachable from every adapter.
- The domain and the application are tested without a database or a framework.
- There is more ceremony than in a layered CRUD application: a port and an adapter
  where a direct call would do. The generators absorb most of it.
- Contexts cannot share domain classes. What two contexts both need is either an
  integration event or a deliberate addition to `src/Shared`.
