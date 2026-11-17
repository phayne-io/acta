# Contributing

Thank you for considering a contribution.

## Getting set up

```bash
docker compose up -d   # PostgreSQL for the integration tests
composer install
composer check         # coding standard, static analysis, tests
```

`composer test:unit` runs the tests that do not need PostgreSQL.

The examples and the test fixtures model amounts with `moneyphp/money`, which needs
`ext-bcmath`. It is a development dependency: the library must not use it.

## What a pull request needs

- **Tests.** New behaviour comes with tests; a bug fix comes with a test that fails
  without it. Anything observable through the `EventStore`, `ProjectionStore` or
  `SnapshotStore` ports belongs in the contract suites under `src/Testing`, so that
  every adapter is held to it.
- **Documentation.** Public classes and methods are documented in the source: say what
  they are for and what a caller must know, not how they work. Update the guides in
  `docs/` and add a line to `CHANGELOG.md` under "Unreleased".
- **Green checks.** `composer check` must pass: PER coding style, PHPStan at the max
  level for `src`, the whole test suite.

## Design rules

- **No third-party runtime dependency.** The package requires PHP and PSR interfaces
  only (`psr/clock`, `psr/container`, `psr/log`). Integrations with other libraries belong in separate packages.
- **Ports first.** Storage-specific code lives in an adapter namespace
  (`Acta\EventSourcing\Postgres`). The engine is written against interfaces.
- **The domain stays clean.** Nothing a domain class extends or implements may expose
  infrastructure concerns.
- **Immutability by default.** Value objects and messages are `final readonly`.
- **Backward compatibility.** The stored format of events and the public API follow
  semantic versioning. A change to either needs a migration path.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
