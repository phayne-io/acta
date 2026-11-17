# Acta

Libraries and a project skeleton for hexagonal, event-sourced applications in PHP 8.5+.
This file tells an AI assistant how to work in this repository. Humans: the same rules
apply to you; the READMEs and `docs/` of each package go deeper.

## Layout

A monorepo. Each package under `packages/` is a standalone Composer package with its own
`composer.json`, `vendor/`, tests, docs and CI workflow (`.github/workflows/<package>.yml`).

| Package | Namespace | What it is |
| --- | --- | --- |
| `packages/event-sourcing` | `Acta\EventSourcing` | Messages, buses, event store, aggregates, projections, snapshots; in-memory and PostgreSQL adapters |
| `packages/http` | `Acta\Http` | HTTP primary adapter driven by an OpenAPI 3.1 contract: routing, security, validation, RFC 9457 errors (PSR-7/15/17) |
| `packages/oidc` | `Acta\Oidc` | OAuth 2.0 / OIDC: Bearer JWT verification (discovery, JWKS), client flows, a fake provider for tests |
| `packages/console-webhook` | `Acta\Console`, `Acta\Webhook` | Operational console commands; incoming and outgoing webhooks |
| `packages/skeleton` | `App` | Project template wiring the four on Mezzio, with a `Banking` example context and `make:*` generators |

Dependencies between packages only go this way: `event-sourcing` depends on no other
package; `http` and `oidc` do not know each other; `console-webhook` builds on
`event-sourcing` and uses `http` and `oidc` only in optional bridges; `skeleton` uses all four.

Packages reach each other through Composer **path repositories** (`../event-sourcing`),
installed as symlinks. Each path repository states the version it holds
(`"options": {"versions": {"acta/x": "1.0.x-dev"}}`) and consumers require `^1.0@dev`.
Keep both: without the stated version Composer names the package after the checked-out
branch and installation fails on every branch but `main`.

## Commands

Run from inside a package directory, never from the repository root.

```sh
composer install
composer check        # everything the CI runs for the package
composer cs           # PER coding style (dry run); composer cs:fix applies it
composer stan         # PHPStan
composer test         # PHPUnit; PostgreSQL tests are skipped when no server answers
composer test:unit    # without the PostgreSQL group (not in oidc, which has none)
```

`packages/skeleton` adds:

```sh
composer architecture    # Deptrac: dependency rules between layers
composer lint:contract   # openapi/openapi.yaml
composer mutation        # Infection on Domain and Application (needs Xdebug or PCOV)
composer serve           # http://localhost:8080 with the PHP built-in server
vendor/bin/phpstan analyse --memory-limit=1G -c phpstan.tests.neon.dist   # tests, level 8
bin/console list
make up                  # Docker: API, workers, PostgreSQL, development identity provider
```

PostgreSQL for the tests: the libraries read `ACTA_TEST_POSTGRES_DSN`, `_USER`,
`_PASSWORD` (default database `acta_test`, user `acta`); the skeleton reads
`TEST_DATABASE_DSN`, `_USER`, `_PASSWORD` (default `app_test`, user `app`). Defaults are
in each `phpunit.xml.dist`; `docker compose up -d` provides the server in the packages
that have a `compose.yaml`.

Before saying a change is done, run `composer check` in every package the change
touches **and in the packages that depend on it** (a change in `event-sourcing` can
break all the others). In the skeleton, also run PHPStan on the tests and, when
`src/*/Domain` or `src/*/Application` changed, `composer mutation`.

## Code conventions

- **PHP 8.5 is the floor; use it.** Readonly classes, enums, property hooks, asymmetric
  visibility, the pipe operator, `clone($object, [...])`, `#[\NoDiscard]`, `#[\Override]`,
  first-class callables, `array_find`/`array_any`/`array_all`, `new X()->method()`.
  CI also runs PHP 8.6, where failures are tolerated but should be fixed.
- **PSR-4 and PER Coding Style** (the successor of PSR-12), enforced by PHP-CS-Fixer.
  `declare(strict_types=1)` everywhere. Classes are `final` unless designed for extension;
  value objects and messages are `final readonly`.
- **PHPStan at level max on `src`**, level 8 on `tests`. Fix the cause of an error: no
  `@phpstan-ignore`, no baseline, no inline `@var` to override an inferred type, no cast
  added only to silence the analyser.
- **Document the code.** Every class and public method has a PHPDoc that says what it is
  for and what a caller must know, not how it works. Comments explain why. Code, docs
  and commit messages are in English.
- **Libraries depend on PSR interfaces only**, plus the few packages already in their
  `require`. A new runtime dependency needs a strong reason; `moneyphp/money` is for
  examples and tests, never for library code.
- **Primary adapters speak PSR-7, PSR-15 and PSR-17**; nothing types against Diactoros
  or Mezzio outside the skeleton's wiring.
- **OpenAPI 3.1 is the source of truth for HTTP I/O.** The contract is written by hand;
  routes, security requirements and validation come from it. Change the contract first,
  then the code. Errors are RFC 9457 problem documents.
- **Time comes from a PSR-20 clock.** Stores do not read the clock themselves: the
  caller passes `$now` (see `IdempotencyStore::acquire`, `DeliveryLog::claim`).

## Architecture rules of the skeleton

Each business context lives in `src/<Context>` with four layers:

- `Domain` — aggregates, events, value objects, exceptions, repository ports. Depends only
  on `Acta\EventSourcing\Aggregate`, `Acta\EventSourcing\Message`, value-object libraries
  and PHP. This includes PHPDoc references: Deptrac reads them.
- `Application` — commands, queries, handlers, read ports. Depends on `Domain`, `Shared`
  and `acta/event-sourcing`. Never on PSR-7, PDO, the framework or another context.
- `Infrastructure\Primary` — HTTP actions, console commands, webhook translators. Reaches
  the application through the command and query buses only.
- `Infrastructure\Secondary` — repositories, read models, projections, published events.

`src/Platform` wires the libraries and knows no context. `src/Shared` holds the little
that contexts share. Two contexts talk through integration events or an explicit port.
`composer architecture` and `tests/Architecture` enforce all of this.

A context plugs in through its `ConfigProvider`, which lists its events, handlers,
operations, projections, schemas and commands. Nothing is discovered by scanning. A
class whose constructor only asks for services needs no factory.

Add features with the generators (`bin/console make:context|aggregate|command|query|webhook`)
rather than by hand; they insert above the `// acta:` and `# acta:` markers, which must
stay in place. Their templates are in `templates/make`: a change to a convention goes
into the templates as well, and the generated code must still pass `composer check`.

## Tests

- New behaviour comes with tests; a bug fix comes with a test that fails without it.
- Domain rules are tested as given/when/then scenarios with `AggregateScenario`, without
  infrastructure.
- Anything observable through a port (`EventStore`, `ProjectionStore`, `IdempotencyStore`,
  `DeliveryQueue`…) belongs in the contract suites under `src/Testing`, so that every
  adapter is held to it.
- End-to-end tests of the skeleton run the same scenarios in memory and on PostgreSQL
  (`tests/Functional`), with `FakeIdentityProvider` standing in for the identity provider.
- Always run PHPUnit with a timeout when driving it from a script: a projection test that
  never stops otherwise hangs the run.
- Do not assert the exact text of messages produced by PHP itself (JSON errors, type
  errors): it changes between PHP versions.

## Git and pull requests

- Never commit to `main` directly: work on a branch and open a pull request.
- Commits are authored by the repository owner. Do **not** add `Co-Authored-By` lines,
  links to an AI session, or "Generated with/by Claude Code" mentions to commit messages
  or pull request descriptions.
- Commit messages: an imperative subject line, then a body that says why.
- Do not rewrite the history of `main`.
- Update the package's `CHANGELOG.md` ("Unreleased") and its `docs/` when behaviour or
  public API changes.
- CI: one workflow per package, triggered by changes under its path and under the
  packages it depends on. Jobs on PHP 8.6 are allowed to fail, so a green workflow can
  hide a red job: check the jobs.

## Things that are not done yet

- Nothing is published on Packagist; `composer create-project acta/skeleton` does not
  work yet. The `acta` vendor name is unconfirmed.
- The skeleton's Docker image and Compose stack have never been built or run.
- Nothing has been tested against a real identity provider.
