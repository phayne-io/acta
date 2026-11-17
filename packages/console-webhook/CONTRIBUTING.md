# Contributing

Thank you for considering a contribution.

## Getting set up

```bash
docker compose up -d   # PostgreSQL for the integration tests
composer install
composer check         # coding standard, static analysis, tests
```

`composer test:unit` runs the tests that do not need PostgreSQL.

`acta/http`, `acta/oidc`, `moneyphp/money` (which needs `ext-bcmath`) and
`laminas/laminas-diactoros` are development dependencies. Only three classes may use the
first two: `OpenApiLintCommand`, the `Auth*Command`s and `ContractPayloadValidator`.

## What a pull request needs

- **Tests.** New behaviour comes with tests; a bug fix comes with a test that fails
  without it. Anything observable through the `DeliveryLog` or `DeliveryQueue` ports
  belongs in the contract suites under `src/Webhook/Testing`. A signature scheme comes
  with a published example of its sender.
- **Documentation.** Public classes and methods are documented in the source: say what
  they are for and what a caller must know, not how they work. Update the guides in
  `docs/` and add a line to `CHANGELOG.md` under "Unreleased".
- **Green checks.** `composer check` must pass: PER coding style, PHPStan at the max
  level for `src`, the whole test suite.

## Design rules

- **Adapters hold no business rule.** A receiver, a translator and a console command
  translate and delegate to a bus.
- **Verify before reading.** Nothing of an incoming webhook is decoded before its
  signature was verified on the raw bytes.
- **At-least-once, made safe.** Incoming deliveries are deduplicated; outgoing ones
  carry a stable identifier. Nothing here may assume a webhook arrives exactly once.
- **Time is given, not read.** Stores receive the current time from their caller, so
  that one clock decides everything and tests can move it.
- **Long-running commands stop cleanly.** A signal lets the current unit of work finish.
- **Backward compatibility.** The public API, the body of outgoing webhooks and the
  table definitions follow semantic versioning.

## Reporting a security issue

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
