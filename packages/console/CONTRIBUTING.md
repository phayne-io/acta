# Contributing

Thank you for considering a contribution.

## Getting set up

```bash
docker compose up -d   # PostgreSQL for the integration tests
composer install
composer check         # coding standard, static analysis, tests
```

`composer test:unit` runs the tests that do not need PostgreSQL.

`acta/webhook`, `acta/http` and `acta/oidc` are development dependencies: the library
must work without them. Only the commands made for each may use it: the
`Webhook*Command`s, `OpenApiLintCommand` and the `Auth*Command`s.

## What a pull request needs

- **Tests.** New behaviour comes with tests; a bug fix comes with a test that fails
  without it. Commands are run through `CommandTester`, and what they display is part
  of what is tested.
- **Documentation.** Public classes and methods are documented in the source: say what
  they are for and what a caller must know, not how they work. Update `docs/console.md`
  and add a line to `CHANGELOG.md` under "Unreleased".
- **Green checks.** `composer check` must pass: PER coding style, PHPStan at the max
  level for `src`, the whole test suite.

## Design rules

- **Commands hold no business rule.** They translate what an operator asks and delegate
  to the application or to an adapter.
- **Constructor dependencies only.** A command asks for what it needs and looks nothing
  up in a container, so that it can be registered in any console application.
- **Long-running commands stop cleanly.** A signal lets the current unit of work finish.
- **Safe to run again.** What `es:migrate` runs is idempotent; a schema is installed
  entirely or not at all.
- **Backward compatibility.** The public API, the names of the commands, their options
  and their exit codes follow semantic versioning.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
