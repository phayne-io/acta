# Contributing

Thank you for considering a contribution.

## Getting set up

```bash
docker compose up -d   # PostgreSQL for the integration tests
composer install
composer check         # coding standard, static analysis, tests
```

`composer test:unit` runs the tests that do not need PostgreSQL.

The example and the tests put the pipeline in front of an application built with
`acta/event-sourcing` and `moneyphp/money` (which needs `ext-bcmath`), and use
`laminas/laminas-diactoros` as PSR-7 implementation. All three are development
dependencies: outside `Acta\Http\EventSourcing`, the library must not use them.

## What a pull request needs

- **Tests.** New behaviour comes with tests; a bug fix comes with a test that fails
  without it. Anything observable through the `IdempotencyStore` port belongs in the
  contract suite under `src/Testing`, so that every adapter is held to it.
- **Documentation.** Public classes and methods are documented in the source: say what
  they are for and what a caller must know, not how they work. Update the guides in
  `docs/` and add a line to `CHANGELOG.md` under "Unreleased".
- **Green checks.** `composer check` must pass: PER coding style, PHPStan at the max
  level for `src`, the whole test suite.

## Design rules

- **The contract is the source of truth.** Routes, parameters, bodies, responses and
  security requirements are read from the OpenAPI description, never declared a second
  time in PHP.
- **PSR interfaces only.** Middlewares and handlers type against PSR-7, PSR-15 and
  PSR-17; no PSR-7 implementation and no framework is required. The one third-party
  runtime dependency is `opis/json-schema`, for JSON Schema 2020-12.
- **Nothing internal reaches the client.** Error responses are built from
  `HttpException`s; anything else becomes a bare 500 unless a mapper says otherwise.
- **Fail closed.** A security scheme without authenticator, a contract that does not
  load or a schema that cannot be read is a server error, never a request let through.
- **Backward compatibility.** The public API and the shape of problem documents follow
  semantic versioning.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
