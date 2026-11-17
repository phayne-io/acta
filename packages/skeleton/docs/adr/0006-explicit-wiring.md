# 0006. Wiring is explicit, one `ConfigProvider` per context

- Status: accepted
- Date: 2026-11-17

## Context

Frameworks can discover handlers, routes and services by scanning directories. It
saves typing, and makes it hard to answer a simple question: what does this
application do, and what happens when this message is dispatched?

## Decision

The application runs on Mezzio and `laminas-servicemanager`. Each context has one
`ConfigProvider` that lists what it contributes: its events, its handlers, its
operations, its projections, its console commands, and the adapters behind its ports.
Nothing is found by scanning.

Attributes say what a class is (`#[Handles]`, `#[Operation]`, `#[MessageName]`,
`#[AsCommand]`); the `ConfigProvider` says that it is in use. A class whose constructor
only asks for services is built from it, without a factory.

Settings come from the environment. Adapters that have an in-memory twin are chosen by
one setting, `acta.persistence`.

## Consequences

- One file per context answers what the context does. Removing a context is removing
  its directory and one line.
- A class that is not listed is not used: forgetting a line is a visible mistake, caught
  by `ContractTest` for operations and by the first test for the rest. The generators
  write the lines.
- Factories are plain static methods referenced as first-class callables, which the
  static analysis follows. The price is that the merged configuration cannot be cached
  as a PHP file; the expensive part, the contract, is cached separately.
- The framework is at the edge. The domain and the application do not know Mezzio, and
  another PSR-15 runner could replace it by rewriting `config/pipeline.php` and
  `public/index.php`.
